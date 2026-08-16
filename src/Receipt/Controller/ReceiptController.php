<?php

declare(strict_types=1);

namespace App\Receipt\Controller;

use App\Core\Entity\User;
use App\Core\Http\ContentDisposition;
use App\Core\Exception\DomainException;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Receipt\Service\ReceiptDownloader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/receipts')]
class ReceiptController extends AbstractController
{
    public function __construct(private readonly DeliveryReceiptRepository $receipts)
    {
    }

    #[Route('', name: 'app_receipts', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('receipt/index.html.twig', [
            'receipts' => $this->receipts->findReadableBy($this->currentUser()),
        ]);
    }

    #[Route('/{id}/download', name: 'app_receipt_download', methods: ['GET'])]
    public function download(string $id, ReceiptDownloader $downloader): Response
    {
        $receipt = $this->readableReceipt($id);

        try {
            $bytes = $downloader->download($receipt, $this->currentUser());
        } catch (DomainException) {
            throw $this->createNotFoundException();
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($receipt->getFilename()),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** A receipt exists for the current user only if they hold a grant on it. */
    private function readableReceipt(string $id): DeliveryReceipt
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $receipt = $this->receipts->find(Uuid::fromString($id));
        if (null === $receipt) {
            throw $this->createNotFoundException();
        }

        return $receipt;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
