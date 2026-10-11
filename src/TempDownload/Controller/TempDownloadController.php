<?php

declare(strict_types=1);

namespace App\TempDownload\Controller;

use App\Core\Http\ContentDisposition;
use App\Core\Security\CurrentUser;
use App\Document\Exception\NoAccessException;
use App\TempDownload\Form\TakeDownloadFormFactory;
use App\TempDownload\Repository\TempDownloadRepository;
use App\TempDownload\Service\TempDownloadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class TempDownloadController extends AbstractController
{
    private const int TIME_LIMIT_SECONDS = 45;

    /**
     * Not the owner, already downloaded, expired or unknown: all one 404, so an
     * id confirms nothing to anyone who cannot have the file.
     */
    #[Route('/downloads/{id}', name: 'app_temp_download', methods: ['POST'])]
    public function take(
        string $id,
        Request $request,
        CurrentUser $currentUser,
        TempDownloadRepository $downloads,
        TempDownloadService $service,
        TakeDownloadFormFactory $forms,
    ): Response {
        $download = Uuid::isValid($id) ? $downloads->find(Uuid::fromString($id)) : null;
        if (null === $download) {
            throw $this->createNotFoundException();
        }

        $form = $forms->create($download);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createNotFoundException();
        }

        // A 50 MB part fetched from object storage can outlast the 30 s web default.
        set_time_limit(self::TIME_LIMIT_SECONDS);

        try {
            $bytes = $service->take($download, $currentUser->get());
        } catch (NoAccessException) {
            throw $this->createNotFoundException();
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => $download->getMimeType(),
            // Lets the page show the transfer as a percentage.
            'Content-Length' => (string) \strlen($bytes),
            'Content-Disposition' => ContentDisposition::attachment($download->getFilename()),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
