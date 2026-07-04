<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        // TODO: replace every array below with repository queries once the
        // Certificate / Document / Signing / AuditLog modules exist. The shapes
        // mirror the planned entities so the template won't need to change.
        // Set them all empty ([] / 0) to see the onboarding empty state.
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
            'certificates' => [
                ['name' => 'Personal signing', 'cn' => 'Maria Koleva', 'algorithm' => 'ECDSA P-384', 'valid_until' => '14 Mar 2027', 'status' => 'active'],
                ['name' => 'Acme Ltd · company', 'cn' => 'Acme Ltd / M. Koleva', 'algorithm' => 'RSA-3072', 'valid_until' => '09 Nov 2026', 'status' => 'active'],
                ['name' => 'Qualified eIDAS', 'cn' => 'Maria Koleva', 'algorithm' => 'ECDSA P-384', 'valid_until' => '15 Jul 2026', 'status' => 'expiring', 'expires_in' => '12d'],
            ],
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
