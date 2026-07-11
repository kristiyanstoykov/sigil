<?php

declare(strict_types=1);

namespace App\Document\Controller;

use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;
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
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    #[Route('', name: 'app_documents', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('documents/index.html.twig', [
            'documents' => $this->documents->findByOwner($this->currentUser()),
        ]);
    }

    #[Route('/upload', name: 'app_document_upload', methods: ['POST'])]
    public function upload(Request $request, DocumentUploader $uploader): Response
    {
        // The upload modal is available on every page; return the user to
        // whatever page they opened it from, not to a documents view.
        $back = $this->redirect($this->returnTarget($request));

        // A body larger than PHP's post_max_size is discarded before we run:
        // no fields, no files, not even the CSRF token survive. Detect that and
        // give a clear size message instead of a misleading "invalid token".
        if ('' === (string) $request->request->get('_token')
            && 0 === $request->files->count()
            && $request->server->getInt('CONTENT_LENGTH') > 0) {
            $this->addFlash('danger', 'That file is too large to upload. The maximum size is 10 MB.');

            return $back;
        }

        if (!$this->isCsrfTokenValid('upload-document', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token - please try again.');

            return $back;
        }

        $file = $request->files->get('document');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'Please choose a PDF file to upload.');

            return $back;
        }

        if (\UPLOAD_ERR_OK !== $file->getError()) {
            $this->addFlash('danger', \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? 'That file is too large to upload. The maximum size is 10 MB.'
                : 'The file could not be uploaded. Please try again.');

            return $back;
        }

        try {
            $bytes = file_get_contents($file->getPathname());
            if (false === $bytes) {
                throw new DomainException('The uploaded file could not be read.');
            }
            $uploader->upload($this->currentUser(), $bytes, (string) $file->getClientOriginalName());
        } catch (DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $back;
        }

        $this->addFlash('success', 'Document uploaded and encrypted.');

        return $back;
    }

    /**
     * Where to send the user after an upload: the page they opened the modal
     * from (a hidden _return field), or the referring page if the body was too
     * large to keep that field. Only same-site root-relative paths are allowed,
     * never an open redirect.
     */
    private function returnTarget(Request $request): string
    {
        $candidate = (string) $request->request->get('_return', '');
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
        return $this->render('documents/show.html.twig', [
            'document' => $this->ownedDocument($id),
        ]);
    }

    #[Route('/{id}/download', name: 'app_document_download', methods: ['GET'])]
    public function download(string $id, DocumentDownloader $downloader): Response
    {
        $document = $this->ownedDocument($id);

        return $this->streamVersion($document, $this->latestVersion($document), $downloader, attachment: true);
    }

    #[Route('/{id}/view', name: 'app_document_view', methods: ['GET'])]
    public function view(string $id, DocumentDownloader $downloader): Response
    {
        $document = $this->ownedDocument($id);

        return $this->streamVersion($document, $this->latestVersion($document), $downloader, attachment: false);
    }

    #[Route('/{id}/versions/{number}/download', name: 'app_document_version_download', methods: ['GET'], requirements: ['number' => '\d+'])]
    public function downloadVersion(string $id, int $number, DocumentDownloader $downloader): Response
    {
        $document = $this->ownedDocument($id);

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

    private function ownedDocument(string $id): Document
    {
        $document = $this->documents->find($id);
        if (null === $document || $document->getOwner()->getId()->toRfc4122() !== $this->currentUser()->getId()->toRfc4122()) {
            // 404, not 403: do not reveal that the id exists.
            throw $this->createNotFoundException();
        }

        return $document;
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
