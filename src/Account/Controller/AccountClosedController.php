<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Form\RestoreAccountForm;
use App\Account\Service\AccountRestorer;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The two account pages reached without a session: the confirmation shown
 * once after a deletion, and the restore page a signed link opens (ADR-015 §4).
 */
#[Route('/account')]
class AccountClosedController extends AbstractController
{
    private const string INVALID_LINK = 'This restore link is invalid or has expired. Register again with your address to get a new one.';

    #[Route('/deleted', name: 'app_account_deleted', methods: ['GET'])]
    public function deleted(Request $request): Response
    {
        $confirmation = $request->getSession()->remove(AccountController::SESSION_DELETED);
        if (!\is_array($confirmation)) {
            return $this->redirectToRoute('app_login');
        }

        $response = $this->render('account/deleted.html.twig', ['confirmation' => $confirmation]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/restore', name: 'app_account_restore', methods: ['GET', 'POST'])]
    public function restore(
        Request $request,
        UriSigner $signer,
        UserRepository $users,
        AccountRestorer $restorer,
        UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.account_recovery')]
        RateLimiterFactory $recoveryLimiter,
    ): Response {
        if (null !== $this->getUser()) {
            $this->addFlash('info', 'Sign out first to restore another account.');

            return $this->redirectToRoute('app_dashboard');
        }

        $id = $request->query->getString('id');
        $email = $request->query->getString('email');
        $user = $signer->checkRequest($request) && Uuid::isValid($id) ? $users->find(Uuid::fromString($id)) : null;
        if (!$user instanceof User || '' === $email || !$restorer->belongsTo($user, $email)) {
            $this->addFlash('danger', self::INVALID_LINK);

            return $this->redirectToRoute('app_register');
        }

        $form = $this->createForm(RestoreAccountForm::class, options: ['action' => $request->getUri()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $field = static fn (string $name): string => trim((string) $form->get($name)->getData());

            if (!$recoveryLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
                $form->addError(new FormError('Too many attempts - please try again later.'));
            } else {
                try {
                    $restorer->restore(
                        user: $user,
                        email: $email,
                        recoveryCode: $field(RestoreAccountForm::E_RECOVERY_CODE),
                        firstName: $field(RestoreAccountForm::E_FIRST_NAME),
                        lastName: $field(RestoreAccountForm::E_LAST_NAME),
                        company: '' !== $field(RestoreAccountForm::E_COMPANY) ? $field(RestoreAccountForm::E_COMPANY) : null,
                        position: '' !== $field(RestoreAccountForm::E_POSITION) ? $field(RestoreAccountForm::E_POSITION) : null,
                        passwordHash: $hasher->hashPassword($user, (string) $form->get(RestoreAccountForm::E_PASSWORD)->getData()),
                    );

                    $this->addFlash('success', 'Your account is back, with its documents and history. Sign in and set up your authenticator again.');

                    return $this->redirectToRoute('app_login');
                } catch (DomainException $e) {
                    $target = AccountRestorer::WRONG_CODE === $e->getMessage() ? $form->get(RestoreAccountForm::E_RECOVERY_CODE) : $form;
                    $target->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('account/restore.html.twig', [
            'form' => $form,
            'email' => $email,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
