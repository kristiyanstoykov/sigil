<?php

declare(strict_types=1);

namespace App\Tests\Functional\AuditLog;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Command\AuditVerifyCommand;
use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
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

    private function makeVerifyTester(): CommandTester
    {
        $command = static::getContainer()->get(AuditVerifyCommand::class);

        return new CommandTester($command);
    }
}
