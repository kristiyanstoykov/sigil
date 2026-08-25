<?php

declare(strict_types=1);

namespace App\Tests\Functional\AuditLog;

use App\AuditLog\Repository\AuditLogEntryRepository;
use App\AuditLog\Service\DoctrineAuditLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two appenders must not be able to compute the same next sequence.
 *
 * This was a real failure, not a hypothetical: two suites running against one
 * database produced "duplicate key value violates unique constraint ...
 * Key (sequence)=(353) already exists". The old row lock on the chain head does
 * not prevent it - under READ COMMITTED the waiting transaction resumes with the
 * result set it had already computed and still sees the stale head - so appends
 * are serialised by an advisory lock on the chain instead.
 *
 * The test drives it from the other side: hold the chain lock on a second
 * connection and an append must wait rather than sail past.
 */
class AuditChainConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineAuditLogger $auditLogger;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->auditLogger = $container->get(DoctrineAuditLogger::class);
    }

    public function testAnAppendWaitsWhileAnotherWriterHoldsTheChain(): void
    {
        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->executeStatement('SELECT pg_advisory_xact_lock(?)', [AuditLogEntryRepository::CHAIN_LOCK_KEY]);

        // Wait, but not forever: without the fix this returns immediately and
        // the assertion below fails, which is exactly the regression to catch.
        $this->em->getConnection()->executeStatement("SET lock_timeout = '750ms'");

        $blocked = null;
        try {
            $this->auditLogger->log('test.concurrent_append');
        } catch (DbalException $e) {
            $blocked = $e;
        } finally {
            $other->rollBack();
            $other->close();
        }

        self::assertNotNull($blocked, 'the append sailed past a held chain lock');
        self::assertStringContainsString('lock timeout', strtolower($blocked->getMessage()));
    }

    /**
     * And the lock is only a queue, not a wall: once the other writer is done the
     * append goes through and chains onto whatever the head is by then.
     */
    public function testTheAppendGoesThroughOnceTheChainIsFree(): void
    {
        $before = $this->auditLogger->log('test.before');

        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->executeStatement('SELECT pg_advisory_xact_lock(?)', [AuditLogEntryRepository::CHAIN_LOCK_KEY]);
        $other->rollBack();
        $other->close();

        $after = $this->auditLogger->log('test.after');

        self::assertSame($before->getSequence() + 1, $after->getSequence());
        self::assertSame($before->getEntryHash(), $after->getPreviousHash());
    }

    private function secondConnection(): Connection
    {
        return DriverManager::getConnection($this->em->getConnection()->getParams());
    }
}
