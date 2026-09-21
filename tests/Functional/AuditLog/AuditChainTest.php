<?php

declare(strict_types=1);

namespace App\Tests\Functional\AuditLog;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Command\AuditAnchorCommand;
use App\AuditLog\Command\AuditVerifyCommand;
use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
use App\AuditLog\Service\AuditChainHasher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AuditChainTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditLoggerInterface $auditLogger;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->auditLogger = $container->get(\App\AuditLog\Service\DoctrineAuditLogger::class);
        $this->em->getConnection()->executeStatement('DELETE FROM audit_log_entry');
    }

    public function testEntriesFormAHashChain(): void
    {
        $first = $this->auditLogger->log('test.first', payload: ['a' => 1]);
        $second = $this->auditLogger->log('test.second', payload: ['b' => 2], severity: AuditSeverity::Warning);

        self::assertSame(1, $first->getSequence());
        self::assertSame(AuditLogEntry::GENESIS_HASH, $first->getPreviousHash());
        self::assertSame(2, $second->getSequence());
        self::assertSame($first->getEntryHash(), $second->getPreviousHash());
        self::assertSame(
            hash('sha256', $second->getPreviousHash().$second->canonicalPayload()),
            $second->getEntryHash(),
        );
    }

    public function testPayloadKeyOrderDoesNotChangeTheHash(): void
    {
        $a = $this->auditLogger->log('test.canonical', payload: ['x' => 1, 'y' => ['b' => 2, 'a' => 3]]);
        $canonical = $a->canonicalPayload();

        self::assertStringContainsString('"a":3,"b":2', $canonical);
    }

    public function testVerifyPassesOnIntactChain(): void
    {
        $this->auditLogger->log('test.one');
        $this->auditLogger->log('test.two');

        $tester = $this->makeVerifyTester();
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 entries verified', $tester->getDisplay());
    }

    /**
     * Regression: entries used to be hashed over a timestamp with microseconds
     * that timestamp(0) then dropped, so every entry failed verification the
     * moment it was reloaded - the identity map hid it from the test above.
     */
    public function testVerifyPassesAfterTheEntriesAreReloadedFromTheDatabase(): void
    {
        $this->auditLogger->log('test.one');
        $this->auditLogger->log('test.two');
        $this->em->clear();

        $tester = $this->makeVerifyTester();
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 entries verified', $tester->getDisplay());
    }

    public function testVerifyFailsWhenAnEntryIsTampered(): void
    {
        $this->auditLogger->log('test.one', payload: ['amount' => 10]);
        $this->auditLogger->log('test.two');

        // simulate direct DB tampering behind the application's back
        $this->em->getConnection()->executeStatement(
            "UPDATE audit_log_entry SET payload = '{\"amount\": 99999}' WHERE action = 'test.one'"
        );
        $this->em->clear();

        $tester = $this->makeVerifyTester();
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('BROKEN at sequence 1', $tester->getDisplay());
    }

    public function testVerifyFailsWhenAnEntryIsDeleted(): void
    {
        $this->auditLogger->log('test.one');
        $this->auditLogger->log('test.two');
        $this->auditLogger->log('test.three');

        $this->em->getConnection()->executeStatement(
            "DELETE FROM audit_log_entry WHERE action = 'test.two'"
        );
        $this->em->clear();

        $tester = $this->makeVerifyTester();
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('BROKEN', $tester->getDisplay());
    }

    /**
     * The chain alone cannot see its own tail go missing: delete the last
     * entries and every remaining link still checks out. That is what the
     * anchor is for - a signed checkpoint of the head, kept elsewhere.
     */
    public function testAnAnchorCatchesATailDeletionTheChainCannotSee(): void
    {
        $this->auditLogger->log('test.one');
        $this->auditLogger->log('test.two');
        $this->auditLogger->log('test.three');

        $anchorFile = tempnam(sys_get_temp_dir(), 'sigil-anchor-');
        $anchor = new CommandTester(static::getContainer()->get(AuditAnchorCommand::class));
        self::assertSame(0, $anchor->execute(['--path' => $anchorFile]));
        self::assertStringContainsString('Anchored sequence 3', $anchor->getDisplay());

        $tester = $this->makeVerifyTester();
        self::assertSame(0, $tester->execute(['--anchor' => $anchorFile]));
        self::assertStringContainsString('1 anchor(s) hold', $tester->getDisplay());

        // Cut the tail: the chain is still internally consistent...
        $this->em->getConnection()->executeStatement("DELETE FROM audit_log_entry WHERE action = 'test.three'");
        $this->em->clear();
        $tester = $this->makeVerifyTester();
        $tester->execute([]);
        self::assertStringContainsString('2 entries verified', $tester->getDisplay(), 'the chain alone is blind to a cut tail');

        // ...but the anchor is not.
        $tester = $this->makeVerifyTester();
        self::assertSame(1, $tester->execute(['--anchor' => $anchorFile]));
        self::assertStringContainsString('no longer', $tester->getDisplay());
        @unlink($anchorFile);
    }

    public function testAnAnchorSomeoneElseWroteIsRejected(): void
    {
        $this->auditLogger->log('test.one');

        $anchorFile = tempnam(sys_get_temp_dir(), 'sigil-anchor-');
        (new CommandTester(static::getContainer()->get(AuditAnchorCommand::class)))->execute(['--path' => $anchorFile]);
        // A forger with write access to the file but not the root key edits the checkpoint.
        file_put_contents($anchorFile, str_replace('"sequence":1', '"sequence":7', (string) file_get_contents($anchorFile)));

        $tester = $this->makeVerifyTester();
        self::assertSame(1, $tester->execute(['--anchor' => $anchorFile]));
        self::assertStringContainsString('was not minted', $tester->getDisplay());
        @unlink($anchorFile);
    }

    public function testEveryEntryNamesItsChainScheme(): void
    {
        $entry = $this->auditLogger->log('test.scheme');

        self::assertSame(AuditChainHasher::SCHEME, $entry->getHashScheme());
        self::assertSame(AuditChainHasher::hash($entry->getHashScheme(), $entry->getPreviousHash(), $entry->canonicalPayload()), $entry->getEntryHash());
    }

    private function makeVerifyTester(): CommandTester
    {
        $command = static::getContainer()->get(AuditVerifyCommand::class);

        return new CommandTester($command);
    }
}
