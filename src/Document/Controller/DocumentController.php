<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;
use App\Document\Form\RevokeShareForm;
use App\Document\Form\ShareDocumentForm;
use App\Document\Form\UploadDocumentForm;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentSharer;
use App\Document\Service\DocumentUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
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

        return $this->render('documents/index.html.twig', [
            'documents' => $this->documents->findByOwner($user),
            'sharedDocuments' => $this->documents->findSharedWith($user),
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
        $document = $this->readableDocument($id);

        return $this->renderShow($document, $this->createForm(ShareDocumentForm::class));
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

    #[Route('/{id}/share', name: 'app_document_share', methods: ['POST'])]
    public function share(string $id, Request $request, DocumentSharer $sharer, UserRepository $users): Response
    {
        $document = $this->ownedDocument($id);

        $form = $this->createForm(ShareDocumentForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get(ShareDocumentForm::E_EMAIL)->getData();
            $recipient = $users->findOneByEmail($email);

            if (null === $recipient) {
                // Deliberately explicit rather than an enumeration-safe silent
                // success: a share that quietly does nothing is worse, and
                // inviting strangers by email is a feature in its own right.
                $form->get(ShareDocumentForm::E_EMAIL)->addError(
                    new FormError('No Sigil account uses that email address.'),
                );
            } else {
                try {
                    $sharer->share($document, $this->currentUser(), $recipient);
                    $this->addFlash('success', sprintf('Shared with %s.', $recipient->getEmail()));

                    return $this->redirectToRoute('app_document_show', ['id' => $id]);
                } catch (DomainException $e) {
                    $form->get(ShareDocumentForm::E_EMAIL)->addError(new FormError($e->getMessage()));
                }
            }
        }

        // Anything unresolved re-renders the page with the error under the field
        // that caused it. 422, not 200, so Turbo replaces the page instead of
        // discarding the response.
        return $this->renderShow($document, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{id}/share/revoke', name: 'app_document_share_revoke', methods: ['POST'])]
    public function revokeShare(string $id, Request $request, DocumentSharer $sharer, UserRepository $users): Response
    {
        $document = $this->ownedDocument($id);

        $form = $this->createForm(RevokeShareForm::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Nothing here is user-typed, so a bad payload is a tampered or
            // stale POST, not a mistake to explain.
            throw $this->createNotFoundException();
        }

        $recipient = $users->find((string) $form->get(RevokeShareForm::E_USER)->getData());
        if (!$recipient instanceof User) {
            throw $this->createNotFoundException();
        }

        try {
            $sharer->revoke($document, $this->currentUser(), $recipient);
            $this->addFlash('success', sprintf('Access removed for %s.', $recipient->getEmail()));
        } catch (DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_document_show', ['id' => $id]);
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
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $attachment ? 'attachment' : 'inline',
                self::headerFilename($document->getTitle()),
            ),
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
     * The document page. Shared by show() and by a share attempt that came back
     * with errors, so the form (and its messages) render identically either way.
     *
     * @param FormInterface<mixed> $shareForm
     */
    private function renderShow(Document $document, FormInterface $shareForm, int $status = Response::HTTP_OK): Response
    {
        $recipients = $this->grants->findRecipientsForDocument($document);

        // One revoke form per recipient, each carrying its target. They share a
        // form name (the POST handler cannot know which row will be submitted),
        // so the template gives the hidden field a per-row id to keep the ids
        // unique.
        $revokeForms = [];
        foreach ($recipients as $person) {
            $id = $person->getId()->toRfc4122();
            $form = $this->createForm(RevokeShareForm::class);
            $form->get(RevokeShareForm::E_USER)->setData($id);
            $revokeForms[$id] = $form->createView();
        }

        // The signing panel's data comes from Twig functions in the Signing
        // module (pending_signing_request / signing_cancel_form): this module
        // does not depend on that one, and the show page is not the only place
        // that needs them.
        return $this->render('documents/show.html.twig', [
            'document' => $document,
            'isOwner' => $this->isOwner($document),
            'recipients' => $recipients,
            'shareForm' => $shareForm,
            'revokeForms' => $revokeForms,
        ], new Response(status: $status));
    }

    /**
     * For actions only the owner may take (sharing, revoking).
     */
    private function ownedDocument(string $id): Document
    {
        $document = $this->documents->find($id);
        if (null === $document || !$this->isOwner($document)) {
            // 404, not 403: do not reveal that the id exists.
            throw $this->createNotFoundException();
        }

        return $document;
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

    /** Strip characters that could break out of the Content-Disposition header. */
    private static function headerFilename(string $title): string
    {
        $safe = preg_replace('/[^\x20-\x7E]/', '', str_replace(['"', '\\', "\r", "\n"], '', $title)) ?? 'document.pdf';
        $safe = trim($safe);

        return '' === $safe ? 'document.pdf' : $safe;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
