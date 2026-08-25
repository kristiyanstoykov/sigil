<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Core\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Form\MarkAllReadFormFactory;
use App\Notification\Form\OpenNotificationFormFactory;
use App\Notification\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The inbox. The bell dropdown in the header shows the first page of the same
 * list, so this page exists for everything that has scrolled past it.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/notifications')]
class NotificationController extends AbstractController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly OpenNotificationFormFactory $openForms,
        private readonly MarkAllReadFormFactory $markAllForms,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_notifications', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->notifications->countFor($user);
        $rows = $this->notifications->findRecentFor($user, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $forms = [];
        foreach ($rows as $notification) {
            $forms[$notification->getId()->toRfc4122()] = $this->openForms->create($notification)->createView();
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $rows,
            'openForms' => $forms,
            'markAllForm' => $this->markAllForms->create()->createView(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'unread' => $this->notifications->countUnreadFor($user),
        ]);
    }

    /**
     * The bell's two variable regions, for the live push to swap in.
     *
     * The hub's nudge carries no content, so this is where the browser actually
     * learns what happened - over its own authenticated session, from the
     * database, with the same visibility rules as every other page.
     */
    #[Route('/bell', name: 'app_notifications_bell', methods: ['GET'])]
    public function bell(): Response
    {
        return $this->render('notification/_bell_content.html.twig');
    }

    /**
     * Follow a notification: mark it read, then go where it points. The target
     * comes off the stored row, never off the request, so this can never be
     * pointed at somewhere else.
     */
    #[Route('/{id}/open', name: 'app_notification_open', methods: ['POST'])]
    public function open(string $id, Request $request): Response
    {
        $notification = $this->ownNotification($id);
        $form = $this->openForms->create($notification);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->redirectToRoute('app_notifications');
        }

        $notification->markRead(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->em->flush();

        return $this->redirect($notification->getUrl());
    }

    #[Route('/read-all', name: 'app_notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        $form = $this->markAllForms->create();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->notifications->markAllReadFor(
                $this->currentUser(),
                \DateTimeImmutable::createFromInterface($this->clock->now()),
            );
        }

        // The bell lives in the header, so this is submitted from any page and
        // has to come back to it. Same-origin referers only - anything else is
        // an open redirect wearing a convenience hat.
        return $this->redirect($this->sameOriginReferer($request) ?? $this->generateUrl('app_notifications'));
    }

    /**
     * Only an absolute URL on this exact origin, scheme and port included.
     *
     * Parsing out the host and accepting anything that had none was the earlier
     * version, and it let through every value parse_url reports hostless:
     * `javascript:...`, and `/\evil.com`, which browsers normalise to
     * `//evil.com`. A prefix match against our own origin needs no parser and
     * has no such gaps - anything else falls back to the inbox.
     */
    private function sameOriginReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (null === $referer) {
            return null;
        }

        return str_starts_with($referer, $request->getSchemeAndHttpHost().'/') ? $referer : null;
    }

    private function ownNotification(string $id): Notification
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $notification = $this->notifications->find(Uuid::fromString($id));
        if (null === $notification || !$notification->isFor($this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $notification;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
