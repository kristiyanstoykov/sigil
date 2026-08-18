<?php

declare(strict_types=1);

namespace App\Delivery\Controller;

use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use App\Delivery\Form\DeliverDocumentForm;
use App\Delivery\Service\DeliveryService;
use App\Delivery\Service\RecipientEligibility;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/documents/{id}/deliver')]
class DeliveryController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DeliveryService $service,
        private readonly UserRepository $users,
    ) {
    }

    /** The compose page: pick who to serve, add a note, deliver. */
    #[Route('', name: 'app_delivery_new', methods: ['GET', 'POST'])]
    public function new(string $id, Request $request): Response
    {
        $document = $this->ownedDocument($id);

        // One delivery per document, ever - deliver() enforces the same rule.
        // Stopping here keeps the user off a form that could only fail.
        if ($document->isDelivered()) {
            $this->addFlash('info', 'This document has already been delivered. Upload it again to serve it on anyone else.');

            return $this->redirectToRoute('app_document_show', ['id' => $id]);
        }

        $form = $this->createForm(DeliverDocumentForm::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $recipients = $this->resolveRecipients((string) $form->get(DeliverDocumentForm::E_RECIPIENTS)->getData());
                /** @var string|null $note */
                $note = $form->get(DeliverDocumentForm::E_NOTE)->getData();

                $delivery = $this->service->deliver($document, $this->currentUser(), $recipients, $note);

                $this->addFlash('success', sprintf(
                    'Delivered to %s. A sealed receipt is on the document.',
                    self::listNames($recipients),
                ));

                return $this->redirectToRoute('app_document_show', ['id' => $id, '_fragment' => 'deliveries']);
            } catch (DomainException $e) {
                $form->get(DeliverDocumentForm::E_RECIPIENTS)->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('delivery/new.html.twig', [
            'document' => $document,
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Look one recipient up as they are added. Same trade as the signer picker:
     * an authenticated user can probe whether an address is registered, and a
     * picker that silently accepts an address nobody can be served at is worse.
     */
    #[Route('/lookup', name: 'app_delivery_lookup', methods: ['POST'])]
    public function lookup(string $id, Request $request, RecipientEligibility $eligibility): JsonResponse
    {
        $this->ownedDocument($id);

        $email = trim((string) $request->getPayload()->get('email'));
        $user = '' === $email ? null : $this->users->findOneByEmail($email);

        if (null === $user) {
            return $this->json(['ok' => false, 'reason' => 'No Sigil account uses that email address.']);
        }

        return $this->json([
            'ok' => $eligibility->isEligible($user),
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'reason' => $eligibility->reasonWhyNot($user),
        ]);
    }

    /**
     * The hidden field carries one email per line. Order is not meaningful here -
     * unlike the signer list, where it is the signing order.
     *
     * @return list<User>
     *
     * @throws DomainException on an address with no account behind it
     */
    private function resolveRecipients(string $raw): array
    {
        $recipients = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $email = trim($line);
            if ('' === $email) {
                continue;
            }

            $recipients[] = $this->users->findOneByEmail($email)
                ?? throw new DomainException(sprintf('No Sigil account uses %s.', $email));
        }

        if ([] === $recipients) {
            throw new DomainException('Add at least one recipient.');
        }

        return $recipients;
    }

    /** @param list<User> $recipients */
    private static function listNames(array $recipients): string
    {
        return implode(', ', array_map(static fn (User $u): string => $u->getFullName(), $recipients));
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

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
