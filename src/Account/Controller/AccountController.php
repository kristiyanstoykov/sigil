<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Form\DeleteAccountForm;
use App\Account\Repository\AccountClosureRecordRepository;
use App\Account\Service\AccountDeleter;
use App\Account\Service\ClosureRecordSealer;
use App\Core\Exception\DomainException;
use App\Core\Http\ContentDisposition;
use App\Core\Security\CurrentUser;
use App\Document\Exception\NoAccessException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/** The account page, the closure records it holds, and deletion (ADR-015 §3). */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/account')]
class AccountController extends AbstractController
{
    /** Where the one-time confirmation travels across the logout; read once by AccountClosedController. */
    public const string SESSION_DELETED = 'account_deleted';

    public function __construct(
        private readonly CurrentUser $currentUser,
    ) {
    }

    #[Route('', name: 'app_account', methods: ['GET'])]
    public function show(AccountClosureRecordRepository $records): Response
    {
        return $this->render('account/show.html.twig', [
            'closure_records' => $records->findForUser($this->currentUser->get()),
        ]);
    }

    #[Route('/closure-records/{id}', name: 'app_account_closure_record', methods: ['GET'])]
    public function closureRecord(string $id, AccountClosureRecordRepository $records, ClosureRecordSealer $sealer): Response
    {
        $record = Uuid::isValid($id) ? $records->find(Uuid::fromString($id)) : null;
        if (null === $record) {
            throw $this->createNotFoundException();
        }

        try {
            $bytes = $sealer->read($record, $this->currentUser->get());
        } catch (NoAccessException) {
            throw $this->createNotFoundException();
        }

        return new Response($bytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::attachment($record->getFilename()),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    #[Route('/delete', name: 'app_account_delete', methods: ['GET', 'POST'])]
    public function delete(
        Request $request,
        AccountDeleter $deleter,
        UserPasswordHasherInterface $passwords,
        GoogleAuthenticatorInterface $totp,
        Security $security,
        #[Autowire(service: 'limiter.account_deletion')]
        RateLimiterFactory $deletionLimiter,
    ): Response {
        $user = $this->currentUser->get();
        $form = $this->createForm(DeleteAccountForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $typedEmail */
            $typedEmail = $form->get(DeleteAccountForm::E_CONFIRM_EMAIL)->getData();
            /** @var string $password */
            $password = $form->get(DeleteAccountForm::E_PASSWORD)->getData();
            /** @var string $code */
            $code = $form->get(DeleteAccountForm::E_TOTP_CODE)->getData();

            if (!$deletionLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
                $form->addError(new FormError('Too many attempts - please try again later.'));
            } elseif (mb_strtolower(trim($typedEmail)) !== mb_strtolower($user->getEmail())) {
                $form->get(DeleteAccountForm::E_CONFIRM_EMAIL)->addError(new FormError('That is not the address of this account.'));
            } elseif (!$passwords->isPasswordValid($user, $password) || !$totp->checkCode($user, $code)) {
                $form->addError(new FormError('Password or authenticator code incorrect.'));
            } else {
                try {
                    $summary = $deleter->delete($user);
                } catch (DomainException $e) {
                    $form->addError(new FormError($e->getMessage()));

                    return $this->renderDelete($deleter, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $security->logout(false);
                $request->getSession()->set(self::SESSION_DELETED, [
                    'recoveryCode' => $summary->recoveryCode,
                    'retainUntil' => $summary->retainUntil,
                    'documentsRetained' => $summary->documentsRetained,
                ]);

                return $this->redirectToRoute('app_account_deleted');
            }
        }

        return $this->renderDelete($deleter, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /** @param FormInterface<mixed> $form */
    private function renderDelete(AccountDeleter $deleter, FormInterface $form, int $status): Response
    {
        return $this->render('account/delete.html.twig', [
            'form' => $form,
            'preview' => $deleter->preview($this->currentUser->get()),
        ], new Response(status: $status));
    }
}
