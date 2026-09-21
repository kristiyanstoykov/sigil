<?php

declare(strict_types=1);

namespace App\AuditLog\Controller;

use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
use App\AuditLog\Form\VerifyChainForm;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\AuditLog\Service\AuditAnchor;
use App\AuditLog\Service\AuditChainVerifier;
use App\AuditLog\Twig\AuditActionExtension;
use App\Certificate\Entity\Certificate;
use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use App\Core\Security\CurrentUser;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The audit log as one person may read it (see AuditLogEntryRepository::findVisibleTo):
 * what they did, and everything that happened to their documents and
 * certificates, whoever did it. Server-paged and server-filtered - a log
 * grows without bound, so unlike the documents library it is never shipped
 * whole to the browser. The integrity panel is the same chain walk the
 * console command runs.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/audit', name: 'app_audit_log')]
final class AuditLogController extends AbstractController
{
    private const int PER_PAGE = 25;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly AuditLogEntryRepository $entries,
        private readonly DocumentRepository $documents,
        private readonly CertificateRepository $certificates,
        private readonly UserRepository $users,
        private readonly AuditChainVerifier $verifier,
        #[Autowire('%env(resolve:SIGIL_AUDIT_ANCHOR_PATH)%')]
        private readonly string $anchorPath,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser->get();
        $family = (string) $request->query->get('family', '');
        $family = isset(AuditActionExtension::FAMILIES[$family]) ? $family : '';
        $severity = AuditSeverity::tryFrom((string) $request->query->get('severity', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $documents = $this->documents->findByOwner($user);
        $certificates = $this->certificates->findByUser($user);
        $documentIds = array_map(static fn (Document $d): string => $d->getId()->toRfc4122(), $documents);
        $certificateIds = array_map(static fn (Certificate $c): string => $c->getId()->toRfc4122(), $certificates);
        $actions = '' === $family ? [] : AuditActionExtension::actionsOf($family);

        $total = $this->entries->countVisibleTo($user, $documentIds, $certificateIds, $actions, $severity);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $this->entries->findVisibleTo($user, $documentIds, $certificateIds, $actions, $severity, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->render('audit/index.html.twig', [
            'entries' => $rows,
            'actors' => $this->actorsOf($rows, $user),
            'subjects' => $this->subjectsOf($documents, $certificates),
            'families' => AuditActionExtension::FAMILIES,
            'family' => $family,
            'severity' => $severity,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'head' => $this->entries->findChainHead(),
            'lastAnchor' => $this->lastAnchor(),
            'verifyForm' => $this->createForm(VerifyChainForm::class)->createView(),
        ]);
    }

    /** Walks the whole chain and reports; a POST because it is work, and the answer is a flash. */
    #[Route('/verify', name: '_verify', methods: ['POST'])]
    public function verify(Request $request): Response
    {
        $form = $this->createForm(VerifyChainForm::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Could not start the verification - please try again.');

            return $this->redirectToRoute('app_audit_log');
        }

        $result = $this->verifier->verify();
        if ($result->isIntact()) {
            $this->addFlash('success', 0 === $result->entries
                ? 'The audit log is empty - nothing to verify.'
                : sprintf('Audit chain intact: all %d entries verified, every link recomputed from genesis.', $result->entries));
        } else {
            $this->addFlash('danger', sprintf('Audit chain BROKEN at entry #%d (%s): %s', $result->brokenAt, $result->brokenAction, implode('; ', $result->reasons)));
        }

        return $this->redirectToRoute('app_audit_log');
    }

    /**
     * actorId is a plain UUID, not a FK (entries outlive users), so names are
     * resolved here; a missing one reads as "a removed account".
     *
     * @param list<AuditLogEntry> $entries
     *
     * @return array<string, string> actor id => display name
     */
    private function actorsOf(array $entries, User $me): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (null !== $entry->getActorId()) {
                $ids[$entry->getActorId()->toRfc4122()] = $entry->getActorId();
            }
        }
        $names = [];
        foreach ($this->users->findBy(['id' => array_values($ids)]) as $actor) {
            $names[$actor->getId()->toRfc4122()] = $actor->is($me) ? 'You' : $actor->getFullName();
        }

        return $names;
    }

    /**
     * @param list<Document>    $documents
     * @param list<Certificate> $certificates
     *
     * @return array<string, array{title: string, route: string|null}> subject id => how to show it
     */
    private function subjectsOf(array $documents, array $certificates): array
    {
        $subjects = [];
        foreach ($documents as $document) {
            $subjects[$document->getId()->toRfc4122()] = ['title' => $document->getTitle(), 'route' => 'app_document_show'];
        }
        foreach ($certificates as $certificate) {
            $subjects[$certificate->getId()->toRfc4122()] = ['title' => 'Certificate #'.substr($certificate->getSerialNumber(), 0, 8), 'route' => 'app_certificate_show'];
        }

        return $subjects;
    }

    private function lastAnchor(): ?AuditAnchor
    {
        $lines = @file($this->anchorPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines || [] === $lines) {
            return null;
        }

        try {
            return AuditAnchor::fromJsonLine((string) end($lines));
        } catch (\Throwable) {
            return null;
        }
    }
}
