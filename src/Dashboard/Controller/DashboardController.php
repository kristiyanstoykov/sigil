<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        CertificateRepository $certificateRepository,
        SignatureAlgorithmRegistry $algorithms,
    ): Response {
        $user = $this->getUser();
        \assert($user instanceof User);

        $certificates = [];
        foreach ($certificateRepository->findByUser($user) as $certificate) {
            if (CertificateStatus::Revoked === $certificate->getStatus()) {
                continue;
            }
            $daysLeft = (int) (new \DateTimeImmutable())->diff($certificate->getNotAfter())->format('%r%a');
            $expiring = CertificateStatus::Active === $certificate->getStatus() && $daysLeft <= 30;
            $certificates[] = [
                'id' => $certificate->getId(),
                'name' => 'Personal signing',
                'cn' => $certificate->getSubjectDn(),
                'algorithm' => $algorithms->get($certificate->getAlgorithmId())->label(),
                'valid_until' => $certificate->getNotAfter()->format('d M Y'),
                'status' => $expiring ? 'expiring' : $certificate->getStatus()->value,
                'expires_in' => $expiring ? max(0, $daysLeft).'d' : null,
            ];
        }

        // TODO: replace the remaining arrays below with repository queries once
        // the Document / Signing modules exist. The shapes mirror the planned
        // entities so the template won't need to change.
        return $this->render('dashboard/index.html.twig', [
            'sidebar_badges' => ['signing_requests' => 2],
            'stats' => [
                'awaiting_me' => 2,
                'sent_pending' => 3,
                'signed_30d' => 18,
                'documents' => 41,
            ],
            'inbox_requests' => [
                ['document' => 'Series-B SAFE agreement.pdf', 'from' => 'Daniel Petrov', 'due' => 'due today'],
                ['document' => 'NDA — Aurelia Labs.pdf', 'from' => 'Lena Fischer', 'due' => 'due in 2 days'],
            ],
            'sent_requests' => [
                ['document' => 'Vendor MSA v3.pdf', 'to' => 'Orion GmbH', 'status' => 'Awaiting', 'meta' => 'sent 1 day ago'],
                ['document' => 'Board consent Q3.pdf', 'to' => '4 signers', 'status' => 'In progress', 'meta' => '2 of 4 signed'],
            ],
            'certificates' => $certificates,
            'activity' => [
                ['type' => 'signed', 'text' => 'You signed <strong>Payroll addendum.pdf</strong>', 'when' => '2 hours ago'],
                ['type' => 'uploaded', 'text' => 'Uploaded <strong>Q3 report.pdf</strong>', 'when' => 'Yesterday'],
                ['type' => 'shared', 'text' => 'Shared <strong>MSA v3.pdf</strong> with Orion', 'when' => '2 days ago'],
            ],
            'recent_documents' => [
                ['name' => 'Payroll addendum.pdf', 'version' => 'v3', 'updated' => '2h ago', 'status' => 'Signed', 'people' => ['MK', 'DP']],
                ['name' => 'Series-B SAFE.pdf', 'version' => 'v1', 'updated' => 'today', 'status' => 'Pending', 'people' => ['DP']],
                ['name' => 'Board consent Q3.pdf', 'version' => 'v2', 'updated' => '1d ago', 'status' => 'In progress', 'people' => ['MK', 'JR', '+2']],
                ['name' => 'Q3 report.pdf', 'version' => 'v1', 'updated' => '1d ago', 'status' => 'Draft', 'people' => ['MK']],
            ],
        ]);
    }
}
