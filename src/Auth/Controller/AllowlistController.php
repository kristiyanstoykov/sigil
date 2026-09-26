<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Entity\AllowlistedEmail;
use App\Auth\Form\AllowlistAddForm;
use App\Auth\Form\AllowlistRemoveFormFactory;
use App\Auth\Repository\AllowlistedEmailRepository;
use App\Auth\Service\RegistrationAllowlist;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use App\Core\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The registration allowlist: who may create an account. Admins only.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/allowlist', name: 'app_admin_allowlist')]
final class AllowlistController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly AllowlistedEmailRepository $entries,
        private readonly UserRepository $users,
        private readonly RegistrationAllowlist $allowlist,
        private readonly AllowlistRemoveFormFactory $removeForms,
    ) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(AllowlistAddForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $email */
            $email = $form->get(AllowlistAddForm::E_EMAIL)->getData();
            try {
                $entry = $this->allowlist->add($email, $this->currentUser->get());
                $this->addFlash('success', sprintf('%s can now register.', $entry->getEmail()));

                return $this->redirectToRoute('app_admin_allowlist');
            } catch (DomainException $e) {
                $form->get(AllowlistAddForm::E_EMAIL)->addError(new FormError($e->getMessage()));
            }
        }

        $response = $this->renderList($form, $request->query->getInt('page', 1));
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/{id}/remove', name: '_remove', methods: ['POST'])]
    public function remove(string $id, Request $request): Response
    {
        $entry = Uuid::isValid($id) ? $this->entries->find(Uuid::fromString($id)) : null;
        if (null === $entry) {
            throw $this->createNotFoundException();
        }

        $form = $this->removeForms->create($entry);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'The form expired. Please try again.');

            return $this->redirectToRoute('app_admin_allowlist');
        }

        try {
            $this->allowlist->remove($entry, $this->currentUser->get());
            $this->addFlash('success', sprintf('The invitation for %s was withdrawn.', $entry->getEmail()));
        } catch (DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_allowlist');
    }

    /** @param FormInterface<mixed> $form */
    private function renderList(FormInterface $form, int $page): Response
    {
        $total = $this->entries->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $entries = $this->entries->findPage(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $accounts = $this->users->findByEmailsKeyed(array_map(static fn (AllowlistedEmail $e): string => $e->getEmail(), $entries));

        $adderIds = array_values(array_unique(array_filter(array_map(
            static fn (AllowlistedEmail $e): ?string => $e->getAddedBy()?->toRfc4122(),
            $entries,
        ))));
        $adders = [];
        foreach ($adderIds === [] ? [] : $this->users->findBy(['id' => $adderIds]) as $adder) {
            $adders[$adder->getId()->toRfc4122()] = $adder;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $account = $accounts[$entry->getEmail()] ?? null;
            $rows[] = [
                'entry' => $entry,
                'account' => $account,
                'addedBy' => null === $entry->getAddedBy() ? null : ($adders[$entry->getAddedBy()->toRfc4122()] ?? false),
                'removeForm' => null === $account ? $this->removeForms->create($entry)->createView() : null,
            ];
        }

        return $this->render('admin/allowlist.html.twig', [
            'form' => $form,
            'rows' => $rows,
            'total' => $total,
            'registered' => $this->entries->countRegistered(),
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
