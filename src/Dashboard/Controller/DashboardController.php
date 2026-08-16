<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Enum\CertificateDisplayStatus;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use App\Delivery\Repository\DeliveryRepository;
use App\Document\Repository\DocumentRepository;
use App\Signing\Entity\SigningRequest;
use App\Signing\Repository\SigningRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The one surface that mixes roles: what is waiting on you, what you are waiting
 * on other people for, and what you have been doing. Every other page answers a
 * single question; this one is the overview.
 *
 * It reads the feature modules' repositories directly. That is deliberate and is
 * the only place it happens - a dashboard aggregates by definition, and giving
 * each module a "dashboard summary" service would spread this one view across
 * five of them.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class DashboardController extends AbstractController
{
    /** How far back the activity chart looks. */
    private const int CHART_MONTHS = 6;

    /** What counts as activity worth charting, in the order the legend shows. */
    private const array CHART_ACTIONS = [
        'uploaded' => 'document.uploaded',
        'signed' => 'document.signed',
        'delivered' => 'delivery.served',
    ];

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly DocumentRepository $documents,
        private readonly SigningRequestRepository $requests,
        private readonly DeliveryRepository $deliveries,
        private readonly AuditLogEntryRepository $auditEntries,
    ) {
    }

    #[Route('/', name: 'app_dashboard')]
    public function index(SignatureAlgorithmRegistry $algorithms): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Incoming requests split the same way the signing inbox splits them:
        // only turns that are actually yours are work you can do now.
        $incoming = $this->requests->findPendingForSigner($user);
        $myTurn = array_values(array_filter($incoming, static fn (SigningRequest $r): bool => $r->isTurnOf($user)));
        $sent = $this->requests->findPendingByRequester($user);
        $documents = $this->documents->findVisibleTo($user);
        $served = $this->deliveries->findServedTo($user);

        $certificates = $this->certificateCards($user, $algorithms);

        return $this->render('dashboard/index.html.twig', [
            'onboarding' => [] === $certificates && [] === $documents,
            'stats' => [
                'awaiting_me' => \count($myTurn),
                'sent_pending' => \count($sent),
                'delivered' => \count($served),
                'documents' => \count($documents),
            ],
            'myTurn' => \array_slice($myTurn, 0, 4),
            'sent' => \array_slice($sent, 0, 4),
            'recentDocuments' => \array_slice($documents, 0, 5),
            'activity' => $this->auditEntries->findRecentForActor($user, 6),
            'monthly' => $this->monthlyActivity($user),
            'certificates' => $certificates,
        ]);
    }

    /**
     * Six months of activity read straight off the audit log - the record of what
     * happened already exists, so the chart is a view of it rather than a second
     * set of counters that could drift from it.
     *
     * @return list<array{label: string, uploaded: int, signed: int, delivered: int}>
     */
    private function monthlyActivity(User $user): array
    {
        $first = (new \DateTimeImmutable('first day of this month'))
            ->setTime(0, 0)
            ->modify(sprintf('-%d months', self::CHART_MONTHS - 1));

        $counts = $this->auditEntries->countPerMonthForActor($user, array_values(self::CHART_ACTIONS), $first);

        $months = [];
        for ($i = 0; $i < self::CHART_MONTHS; ++$i) {
            $month = $first->modify(sprintf('+%d months', $i));
            $key = $month->format('Y-m');

            $row = ['label' => $month->format('M')];
            foreach (self::CHART_ACTIONS as $series => $action) {
                $row[$series] = $counts[$action][$key] ?? 0;
            }

            /** @var array{label: string, uploaded: int, signed: int, delivered: int} $row */
            $months[] = $row;
        }

        return $months;
    }

    /**
     * @return list<array{id: \Symfony\Component\Uid\Uuid, name: string, cn: string, algorithm: string, valid_until: string, status: CertificateDisplayStatus, expires_in: ?string}>
     */
    private function certificateCards(User $user, SignatureAlgorithmRegistry $algorithms): array
    {
        $cards = [];
        foreach ($this->certificates->findByUser($user) as $certificate) {
            if (CertificateStatus::Revoked === $certificate->getStatus()) {
                continue;
            }
            $daysLeft = (int) (new \DateTimeImmutable())->diff($certificate->getNotAfter())->format('%r%a');
            $expiring = CertificateStatus::Active === $certificate->getStatus() && $daysLeft <= 30;
            $cards[] = [
                'id' => $certificate->getId(),
                'name' => 'Personal signing',
                'cn' => $certificate->getSubjectDn(),
                'algorithm' => $algorithms->get($certificate->getAlgorithmId())->label(),
                'valid_until' => $certificate->getNotAfter()->format('d M Y'),
                'status' => $expiring ? CertificateDisplayStatus::Expiring : $certificate->getDisplayStatus(),
                'expires_in' => $expiring ? max(0, $daysLeft).'d' : null,
            ];
        }

        return $cards;
    }
}
