<?php

declare(strict_types=1);

namespace App\Signing\Controller;

use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Signing\Entity\SigningRequest;
use App\Signing\Form\DeclineFormFactory;
use App\Signing\Form\DeclineSigningRequestForm;
use App\Signing\Form\WithdrawFormFactory;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\SigningRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The whole life of a signature request, from the current user's side: what is
 * waiting on them (To sign), what they are waiting on other people for (Sent),
 * and what is already settled (History).
 *
 * The sidebar count stays narrower than the page - it counts turns that are
 * actually the user's, so it never claims work that is not theirs to do.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/signing-requests')]
class SigningInboxController extends AbstractController
{
    private const TABS = ['sign', 'sent', 'history'];

    public function __construct(
        private readonly SigningRequestRepository $requests,
        private readonly SigningRequestService $service,
        private readonly DeclineFormFactory $declineForms,
        private readonly WithdrawFormFactory $withdrawForms,
        private readonly DocumentKeyGrantRepository $grants,
    ) {
    }

    #[Route('', name: 'app_signing_requests', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $requested = (string) $request->query->get('tab', 'sign');
        $tab = \in_array($requested, self::TABS, true) ? $requested : 'sign';

        $incoming = $this->requests->findPendingForSigner($user);
        $sent = $this->requests->findPendingByRequester($user);
        $history = 'history' === $tab ? $this->requests->findClosedForParticipant($user) : [];

        return $this->render('signing/index.html.twig', [
            'tab' => $tab,
            'counts' => [
                'sign' => \count(array_filter($incoming, static fn (SigningRequest $r): bool => $r->isTurnOf($user))),
                'sent' => \count($sent),
            ],
            'incoming' => $incoming,
            'sent' => $sent,
            'history' => $history,
            // Whether the document behind a closed request is still readable:
            // a decliner lost their grant when the request closed, so History
            // must not offer them a link that 404s.
            'readable' => $this->readabilityOf($history, $user),
            // One form per card: a shared FormView would emit duplicate DOM ids
            // and one CSRF token valid for every request on the page.
            'declineForms' => $this->viewsFor($incoming, $this->declineForms->create(...)),
            'withdrawForms' => $this->viewsFor($sent, $this->withdrawForms->create(...)),
        ]);
    }

    /** Withdraw a request you sent, from the Sent tab. */
    #[Route('/{id}/withdraw', name: 'app_signing_request_withdraw', methods: ['POST'])]
    public function withdraw(string $id, Request $request): Response
    {
        $user = $this->currentUser();
        $signingRequest = $this->sentBy($id, $user);

        $form = $this->withdrawForms->create($signingRequest);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Nothing here is user-typed: a bad payload is a stale or tampered POST.
            throw $this->createNotFoundException();
        }

        try {
            $this->service->cancel($signingRequest, $user);
            $this->addFlash('success', sprintf('The request on "%s" was withdrawn.', $signingRequest->getDocument()->getTitle()));
        } catch (DomainException $e) {
            // The form sits in a confirm modal the redirect has already closed.
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_signing_requests', ['tab' => 'sent']);
    }

    /** Refuse the request whose turn is yours. Optional reason, no negotiation. */
    #[Route('/{id}/decline', name: 'app_signing_request_decline', methods: ['POST'])]
    public function decline(string $id, Request $request): Response
    {
        $user = $this->currentUser();
        $signingRequest = $this->turnOf($id, $user);

        $form = $this->declineForms->create($signingRequest);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // The form sits in a confirm modal the redirect has already closed,
            // so a field error would render where nobody can see it.
            $this->addFlash('danger', 'Could not record the refusal - please try again.');

            return $this->redirectToRoute('app_signing_requests');
        }

        /** @var string|null $reason */
        $reason = $form->get(DeclineSigningRequestForm::E_REASON)->getData();

        try {
            $this->service->decline($signingRequest, $user, $reason);
            $this->addFlash('success', sprintf('You declined to sign "%s". %s has been told.', $signingRequest->getDocument()->getTitle(), $signingRequest->getRequester()->getFullName()));
        } catch (DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_signing_requests');
    }

    /**
     * @param list<SigningRequest> $requests
     *
     * @return array<string, bool> keyed by request uuid
     */
    private function readabilityOf(array $requests, User $user): array
    {
        $readable = [];
        foreach ($requests as $request) {
            $document = $request->getDocument();
            $readable[$request->getId()->toRfc4122()] = $document->getOwner()->getId()->toRfc4122() === $user->getId()->toRfc4122()
                || $this->grants->hasGrantForDocument($document, $user);
        }

        return $readable;
    }

    /**
     * @param list<SigningRequest>                          $requests
     * @param callable(SigningRequest): FormInterface<mixed> $factory
     *
     * @return array<string, FormView> keyed by request uuid
     */
    private function viewsFor(array $requests, callable $factory): array
    {
        $views = [];
        foreach ($requests as $request) {
            $views[$request->getId()->toRfc4122()] = $factory($request)->createView();
        }

        return $views;
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException when it is not this user's turn */
    private function turnOf(string $id, User $user): SigningRequest
    {
        $request = $this->pendingRequest($id);
        if (!$request->isTurnOf($user)) {
            throw $this->createNotFoundException();
        }

        return $request;
    }

    /** @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException when this user did not send it */
    private function sentBy(string $id, User $user): SigningRequest
    {
        $request = $this->pendingRequest($id);
        if ($request->getRequester()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $request;
    }

    private function pendingRequest(string $id): SigningRequest
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $request = $this->requests->find(Uuid::fromString($id));
        // 404 rather than 403 throughout: never confirm that an id exists to
        // someone with no business knowing it.
        if (null === $request) {
            throw $this->createNotFoundException();
        }

        return $request;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
