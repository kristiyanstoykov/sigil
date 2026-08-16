<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Http\ContentDisposition;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;
use App\Document\Form\UploadDocumentForm;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/documents')]
class DocumentController extends AbstractController
{
    /** Symfony's generated name for UploadDocumentForm - its field prefix. */
    private const FORM_NAME = 'upload_document_form';

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentKeyGrantRepository $grants,
    ) {
    }

    #[Route('', name: 'app_documents', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser();

        // One list, not two: the page is a library, and which documents are the
        // user's own is a column, not a separate tab. Role and status filtering
        // happen in the template - status comes from document_status(), which
        // lives in the Signing module this one must not depend on.
        return $this->render('documents/index.html.twig', [
            'documents' => $this->documents->findVisibleTo($user),
        ]);
    }

    #[Route('/upload', name: 'app_document_upload', methods: ['POST'])]
    public function upload(Request $request, DocumentUploader $uploader): Response
    {
        // Where a *failed* upload goes: the modal is available on every page, so
        // an error returns the user to whatever page they opened it from rather
        // than dumping them in a documents view they did not ask for. A
        // successful upload ignores this and continues to the sign page.
        $back = $this->redirect($this->returnTarget($request));

        // A body larger than PHP's post_max_size is discarded before we run:
        // no fields, no files, not even the CSRF token survive. Detect that
        // BEFORE handing the request to the form, which would only be able to
        // report a misleading "invalid CSRF token".
        if (0 === $request->request->count()
            && 0 === $request->files->count()
            && $request->server->getInt('CONTENT_LENGTH') > 0) {
            $this->addFlash('danger', 'That file is too large to upload. The maximum size is 10 MB.');

            return $back;
        }

        // PHP's own verdict on the upload (over upload_max_filesize, truncated,
        // no tmp dir). Read before handleRequest(): the form turns any of these
        // into a generic "invalid" and the specific reason would be lost.
        $uploaded = $request->files->all()[self::FORM_NAME][UploadDocumentForm::E_FILE] ?? null;
        if ($uploaded instanceof UploadedFile && \UPLOAD_ERR_OK !== $uploaded->getError()) {
            $this->addFlash('danger', \in_array($uploaded->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is too large to upload. The maximum size is 10 MB.'
                : 'The file could not be uploaded. Please try again.');

            return $back;
        }

        $form = $this->createForm(UploadDocumentForm::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Invalid security token - please try again.');

            return $back;
        }

        $file = $form->get(UploadDocumentForm::E_FILE)->getData();
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'Please choose a PDF file to upload.');

            return $back;
        }

        try {
            $bytes = file_get_contents($file->getPathname());
            if (false === $bytes) {
                throw new DomainException('The uploaded file could not be read.');
            }
            $document = $uploader->upload($this->currentUser(), $bytes, (string) $file->getClientOriginalName());
        } catch (DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $back;
        }

        $this->addFlash('success', 'Document uploaded and encrypted. Sign it now, or come back to it later.');

        // Storing a document is not the point of storing it: an upload is only
        // ever a step towards a signature. Carry straight on to the sign page -
        // which itself handles the "no usable certificate" case - instead of
        // leaving the document sitting as a draft nobody was prompted to finish.
        return $this->redirectToRoute('app_document_sign', ['id' => $document->getId()->toRfc4122()]);
    }

    /**
     * Where to send the user after an upload: the page they opened the modal
     * from (a hidden _return field), or the referring page if the body was too
     * large to keep that field. Only same-site root-relative paths are allowed,
     * never an open redirect.
     */
    private function returnTarget(Request $request): string
    {
        $submitted = $request->request->all()[self::FORM_NAME] ?? [];
        $candidate = \is_array($submitted) ? (string) ($submitted[UploadDocumentForm::E_RETURN] ?? '') : '';
        if ('' === $candidate) {
            $referer = (string) $request->headers->get('referer', '');
            if ('' !== $referer && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
                $candidate = (string) (parse_url($referer, \PHP_URL_PATH) ?: '');
            }
        }

        if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
            return $candidate;
        }

        return $this->generateUrl('app_documents');
    }

    #[Route('/{id}', name: 'app_document_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        return $this->renderShow($this->readableDocument($id));
    }

    #[Route('/{id}/download', name: 'app_document_download', methods: ['GET'])]
    public function download(string $id, DocumentDownloader $downloader): Response
    {
        $document = $this->readableDocument($id);

        return $this->streamVersion($document, $this->latestVersion($document), $downloader, attachment: true);
    }

    #[Route('/{id}/view', name: 'app_document_view', methods: ['GET'])]
    public function view(string $id, DocumentDownloader $downloader): Response
    {
        $document = $this->readableDocument($id);

        return $this->streamVersion($document, $this->latestVersion($document), $downloader, attachment: false);
    }

    #[Route('/{id}/versions/{number}/download', name: 'app_document_version_download', methods: ['GET'], requirements: ['number' => '\d+'])]
    public function downloadVersion(string $id, int $number, DocumentDownloader $downloader): Response
    {
        $document = $this->readableDocument($id);

        return $this->streamVersion($document, $this->versionNumbered($document, $number), $downloader, attachment: true);
    }

    private function streamVersion(
        Document $document,
        DocumentVersion $version,
        DocumentDownloader $downloader,
        bool $attachment,
    ): Response {
        try {
            $bytes = $downloader->download($version, $this->currentUser());
        } catch (DomainException) {
            throw $this->createNotFoundException();
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $attachment
                ? ContentDisposition::attachment($document->getTitle())
                : ContentDisposition::inline($document->getTitle()),
            // Decrypted plaintext must never be cached by the browser or proxies.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function latestVersion(Document $document): DocumentVersion
    {
        return $document->getLatestVersion() ?? throw $this->createNotFoundException();
    }

    private function versionNumbered(Document $document, int $number): DocumentVersion
    {
        foreach ($document->getVersions() as $version) {
            if ($version->getVersionNumber() === $number) {
                return $version;
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * The document page. Its signing panel, signature list and receipts all come
     * from Twig functions in the Signing and Receipt modules (see
     * pending_signing_request / document_signatures / document_receipts): this
     * module does not depend on either of them.
     */
    private function renderShow(Document $document): Response
    {
        return $this->render('documents/show.html.twig', [
            'document' => $document,
            'isOwner' => $this->isOwner($document),
        ]);
    }

    /**
     * For reading: the owner, or anyone the document has been shared with. The
     * grant is the authority - holding one is exactly what "has access" means -
     * so this asks the grants rather than keeping a second list in sync.
     * Download still re-checks per version inside DocumentDownloader.
     */
    private function readableDocument(string $id): Document
    {
        $document = $this->documents->find($id);
        if (null === $document
            || (!$this->isOwner($document) && !$this->grants->hasGrantForDocument($document, $this->currentUser()))) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    private function isOwner(Document $document): bool
    {
        return $document->getOwner()->getId()->toRfc4122() === $this->currentUser()->getId()->toRfc4122();
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
