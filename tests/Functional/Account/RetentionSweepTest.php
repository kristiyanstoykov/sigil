<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Repository\AccountClosureRecordRepository;
use App\Account\Repository\ArchivedUserKeyRepository;
use App\Account\Service\AccountDeleter;
use App\Account\Service\AccountRestorer;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Receipt\Entity\DeliveryReceiptKeyGrant;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Signing\Service\SigningRequestService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Ten years on (ADR-015 §6): what the deleted account owned goes for everyone,
 * with its receipts; what others own stays theirs; the row and the "one
 * address, one account" rule survive.
 */
final class RetentionSweepTest extends AuthWebTestCase
{
    use AccountFixtures;

    public function testAnAccountPastRetentionIsErasedSilently(): void
    {
        $leaver = $this->createUser($email = $this->uniqueEmail('leaver'));
        $this->makeCertificate($leaver);
        $other = $this->signerUser('other');
        $id = $leaver->getId()->toRfc4122();

        // Theirs, signed by someone else: erased for everyone.
        $theirs = $this->upload($leaver);
        $this->get(SigningRequestService::class)->create($theirs, $leaver, [$other], $this->inDays(7));
        $this->signer()->sign($theirs, $this->certificateOf($other), $other, self::PIN);
        // Someone else's, signed by them: the other person's record, kept.
        $others = $this->upload($other);
        $this->get(SigningRequestService::class)->create($others, $other, [$leaver], $this->inDays(7));
        $this->signer()->sign($others, $this->certificateOf($leaver), $leaver, self::PIN);
        [$theirsId, $othersId] = [$theirs->getId(), $others->getId()];
        self::assertNotEmpty($this->get(DeliveryReceiptRepository::class)->findForDocument($theirsId));

        $code = $this->get(AccountDeleter::class)->delete($leaver)->recoveryCode;
        $this->runSweep(Command::SUCCESS, 'No account is past');

        $this->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE archived_user_key SET retain_until = NOW() - INTERVAL '1 day' WHERE user_id = :id", ['id' => $id]);
        $this->runSweep(Command::SUCCESS, '1 account(s) purged');

        $this->get(EntityManagerInterface::class)->clear();
        $leaver = $this->get(UserRepository::class)->find($id);
        self::assertInstanceOf(User::class, $leaver);
        self::assertTrue($leaver->isDeleted(), 'the tombstone stays');
        self::assertNull($this->get(DocumentRepository::class)->find($theirsId));
        self::assertSame([], $this->get(DeliveryReceiptRepository::class)->findForDocument($theirsId));
        self::assertNull($this->get(ArchivedUserKeyRepository::class)->findForUser($leaver));
        self::assertSame([], $this->get(AccountClosureRecordRepository::class)->findForUser($leaver));
        $em = $this->get(EntityManagerInterface::class);
        self::assertSame([], $em->getRepository(DocumentKeyGrant::class)->findBy(['user' => $leaver]));
        self::assertSame([], $em->getRepository(DeliveryReceiptKeyGrant::class)->findBy(['user' => $leaver]));

        $others = $this->get(DocumentRepository::class)->find($othersId);
        self::assertNotNull($others);
        self::assertTrue($this->get(DocumentKeyGrantRepository::class)->hasGrantForDocument($others, $this->reloadUser($other)));
        self::assertNotEmpty($this->get(DeliveryReceiptRepository::class)->findForDocument($othersId));

        $entries = $this->get(AuditLogEntryRepository::class)->findBy(['action' => 'retention.purged', 'subjectId' => $id]);
        self::assertCount(1, $entries);
        self::assertSame(['documentsErased' => 1, 'receiptsErased' => 1], $entries[0]->getPayload());

        // The address still only brings back that account - now an empty one.
        $this->get(AccountRestorer::class)->restore($leaver, $email, $code, 'Ana', 'Petrova', null, null, 'hash');
        self::assertFalse($leaver->isDeleted());
        self::assertSame([], $this->get(DocumentRepository::class)->findByOwner($leaver));
    }

    private function runSweep(int $exit, string $expected): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sigil:retention:sweep'));
        self::assertSame($exit, $tester->execute([]));
        self::assertStringContainsString($expected, $tester->getDisplay());
    }

    private function reloadUser(User $user): User
    {
        $reloaded = $this->get(UserRepository::class)->find($user->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
