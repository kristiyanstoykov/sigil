<?php

declare(strict_types=1);

namespace App\Receipt\Controller;

use App\Core\Exception\DomainException;
use App\Core\Http\ContentDisposition;
use App\Core\Security\CurrentUser;
use App\Document\Exception\NoAccessException;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Form\VerifyDocumentForm;
use App\Receipt\Repository\DeliveryReceiptKeyGrantRepository;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Receipt\Service\ReceiptDownloader;
use App\Receipt\Service\ReceiptVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/receipts')]
class ReceiptController extends AbstractController
{
    /** The flash type the verify page renders inline; the toast stack ignores it. */
    public const FLASH_VERIFICATION = 'verification';

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly DeliveryReceiptRepository $receipts,
        private readonly DeliveryReceiptKeyGrantRepository $grants,
    ) {
    }

    #[Route('', name: 'app_receipts', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('receipt/index.html.twig', [
            'receipts' => $this->receipts->findReadableBy($this->currentUser->get()),
        ]);
    }

    #[Route('/{id}/download', name: 'app_receipt_download', methods: ['GET'])]
    public function download(string $id, ReceiptDownloader $downloader): Response
    {
        $receipt = $this->readableReceipt($id);

        try {
            $bytes = $downloader->download($receipt, $this->currentUser->get());
        } catch (NoAccessException) {
            throw $this->createNotFoundException();
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($receipt->getFilename()),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Check a file against the receipt's fingerprint (ADR-012 §7). POST then
     * redirect: the verdict travels as a flash of its own type and renders in
     * the page, not in the toast stack - it is a result, not a notice. A
     * machine-readable answer, if ever wanted, belongs on this same route via
     * content negotiation, not on a second auth stack.
     */
    #[Route('/{id}/verify', name: 'app_receipt_verify', methods: ['GET', 'POST'])]
    public function verify(
        string $id,
        Request $request,
        ReceiptVerifier $verifier,
        #[Autowire(service: 'limiter.receipt_verification')]
        RateLimiterFactory $verificationLimiter,
    ): Response {
        $receipt = $this->readableReceipt($id);
        $user = $this->currentUser->get();
        $back = $this->redirectToRoute('app_receipt_verify', ['id' => $id]);

        $form = $this->createForm(VerifyDocumentForm::class, options: ['csrf_token_id' => 'verify-receipt-'.$id]);

        if ($request->isMethod('POST')) {
            // Same two pre-form checks as an upload: a body over post_max_size
            // arrives with no fields at all, and PHP's own upload verdict is
            // lost once the form turns it into a generic "invalid".
            if (0 === $request->request->count() && 0 === $request->files->count() && $request->server->getInt('CONTENT_LENGTH') > 0) {
                $this->addFlash('danger', 'That file is too large to check. The maximum size is 10 MB.');

                return $back;
            }
            $uploaded = $request->files->all()[$form->getName()][VerifyDocumentForm::E_FILE] ?? null;
            if ($uploaded instanceof UploadedFile && \UPLOAD_ERR_OK !== $uploaded->getError()) {
                $this->addFlash('danger', \in_array($uploaded->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                    ? 'That file is too large to check. The maximum size is 10 MB.'
                    : 'The file could not be uploaded. Please try again.');

                return $back;
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get(VerifyDocumentForm::E_FILE)->getData();
            if (!$file instanceof UploadedFile) {
                $form->get(VerifyDocumentForm::E_FILE)->addError(new FormError('Choose the PDF to check.'));
            } elseif (!$verificationLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->addFlash('danger', 'Too many checks - please try again later.');

                return $back;
            } else {
                try {
                    $bytes = file_get_contents($file->getPathname());
                    if (false === $bytes) {
                        throw new DomainException('The uploaded file could not be read.');
                    }
                    $matches = $verifier->verify($receipt, $user, $bytes);
                } catch (NoAccessException) {
                    throw $this->createNotFoundException();
                } catch (DomainException $e) {
                    $form->get(VerifyDocumentForm::E_FILE)->addError(new FormError($e->getMessage()));

                    return $this->renderVerify($receipt, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $this->addFlash(self::FLASH_VERIFICATION, [
                    'matches' => $matches,
                    'sizeBytes' => \strlen($bytes),
                ]);

                return $back;
            }
        }

        return $this->renderVerify($receipt, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /** @param FormInterface<mixed> $form */
    private function renderVerify(DeliveryReceipt $receipt, FormInterface $form, int $status): Response
    {
        return $this->render('receipt/verify.html.twig', [
            'receipt' => $receipt,
            'form' => $form->createView(),
        ], new Response(status: $status));
    }

    /**
     * A receipt exists for the current user only if they hold a grant on it -
     * the same 404 whether the row is missing or belongs to other people.
     */
    private function readableReceipt(string $id): DeliveryReceipt
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $receipt = $this->receipts->find(Uuid::fromString($id));
        if (null === $receipt || null === $this->grants->findForReceiptAndUser($receipt, $this->currentUser->get())) {
            throw $this->createNotFoundException();
        }

        return $receipt;
    }
}
