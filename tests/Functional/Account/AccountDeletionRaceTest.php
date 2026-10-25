<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Service\AccountDeleter;
use App\Certificate\Exception\CertificateLockedException;
use App\Core\Exception\DomainException;
use App\Delivery\Service\DeliveryService;
use App\Document\Repository\UserEncryptionKeyRepository;
use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Event\SigningRequestClosed;
use App\Signing\Service\SigningRequestService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Deletion racing the things that could still reach the account, in the
 * StateTransitionRaceTest style: the deletion commits behind a stale entity
 * (raw SQL), and the other side must re-read and refuse.
 */
final class AccountDeletionRaceTest extends AuthWebTestCase
{
    use AccountFixtures;

    public function testADeliveryCannotMintAKeyForAnAccountDeletedMeanwhile(): void
    {
        [$owner, $recipient] = [$this->createUser($this->uniqueEmail('owner')), $this->createUser($this->uniqueEmail('recipient'))];
        $document = $this->upload($owner);
        self::assertFalse($recipient->isDeleted(), 'the stale entity says live');

        $this->sql('UPDATE users SET deleted_at = NOW() WHERE id = :id', $recipient->getId()->toRfc4122());

        try {
            $this->get(DeliveryService::class)->deliver($document, $owner, [$recipient]);
            self::fail('the delivery must be refused');
        } catch (DomainException) {
        }
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM user_encryption_key WHERE user_id = :id', ['id' => $recipient->getId()->toRfc4122()]), 'no key under which a grant would be lost');
    }

    public function testASignatureCannotLandAfterTheDeletionRevokedTheCertificate(): void
    {
        $user = $this->signerUser('leaver');
        $document = $this->upload($user);
        $certificate = $this->certificateOf($user);

        $this->sql("UPDATE certificate SET status = 'revoked', revoked_at = NOW(), revocation_reason = 'cessationOfOperation' WHERE id = :id", $certificate->getId()->toRfc4122());
        $this->sql('UPDATE users SET deleted_at = NOW() WHERE id = :id', $user->getId()->toRfc4122());

        $this->expectException(CertificateLockedException::class);
        $this->signer()->sign($document, $certificate, $user, self::PIN);
    }

    public function testARefusalCannotReopenARequestTheDeletionClosed(): void
    {
        [$owner, $first] = [$this->signerUser('owner'), $this->signerUser('first')];
        $request = $this->get(SigningRequestService::class)->create($this->upload($owner), $owner, [$first], $this->inDays(7));
        self::assertTrue($request->isPending(), 'the stale entity says pending');

        $this->sql("UPDATE signing_request SET status = 'signer_deleted', closed_at = NOW() WHERE id = :id", $request->getId()->toRfc4122());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already closed');
        $this->get(SigningRequestService::class)->decline($request, $first, 'Too late.');
    }

    public function testARequestSentToThemMidDeletionMakesItStartAgain(): void
    {
        [$leaver, $other] = [$this->signerUser('leaver'), $this->signerUser('other')];
        $this->get(SigningRequestService::class)->create($this->upload($leaver), $leaver, [$other], $this->inDays(7));

        // While deletion closes their own request, someone sends them a new one.
        $sent = false;
        $this->get(EventDispatcherInterface::class)->addListener(SigningRequestClosed::class, function () use (&$sent, $leaver, $other): void {
            if (!$sent) {
                $sent = true;
                $this->get(SigningRequestService::class)->create($this->upload($other), $other, [$leaver], $this->inDays(7));
            }
        }, -100);

        try {
            $this->get(AccountDeleter::class)->delete($leaver);
            self::fail('the deletion must notice the new request');
        } catch (DomainException $e) {
            self::assertSame(AccountDeleter::RETRY_MESSAGE, $e->getMessage());
        }
        self::assertTrue($sent);

        static::getContainer()->get('doctrine')->resetManager();
        $leaver = $this->get(EntityManagerInterface::class)->find($leaver::class, $leaver->getId());
        self::assertNotNull($leaver);
        self::assertFalse($leaver->isDeleted());
        self::assertNotNull($this->get(UserEncryptionKeyRepository::class)->findForUser($leaver), 'nothing was archived');

        // Run again, it carries on and closes the newcomer too.
        $summary = $this->get(AccountDeleter::class)->delete($leaver);
        self::assertTrue($leaver->isDeleted());
        self::assertContains(SigningRequestStatus::SignerDeleted->value, $summary->closedRequests);
    }

    private function sql(string $statement, string $id): void
    {
        $this->connection()->executeStatement($statement, ['id' => $id]);
    }

    private function connection(): Connection
    {
        return $this->get(EntityManagerInterface::class)->getConnection();
    }
}
