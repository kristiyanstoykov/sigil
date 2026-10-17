<?php

declare(strict_types=1);

namespace App\Tests\Unit\Signing;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Signing\Entity\SigningRequest;
use App\Signing\Entity\SigningRequestSigner;
use PHPUnit\Framework\TestCase;

final class SigningRequestClosingAudienceTest extends TestCase
{
    public function testADeletedSignerLaterInTheQueueStillTellsTheTurnHolder(): void
    {
        [$requester, $first, $second] = [$this->user('Req'), $this->user('First'), $this->user('Second')];
        $request = $this->request($requester, $first, $second);

        $request->signerFor($second)?->markAccountDeleted(new \DateTimeImmutable());

        self::assertSame($request->signerFor($second), $request->deletedSigner());
        self::assertSame([$requester, $first], $request->closingAudience());
    }

    public function testADeletedSignerOnTheirOwnTurnIsNotTold(): void
    {
        [$requester, $first, $second] = [$this->user('Req'), $this->user('First'), $this->user('Second')];
        $request = $this->request($requester, $first, $second);

        $request->signerFor($first)?->markAccountDeleted(new \DateTimeImmutable());

        self::assertSame([$requester], $request->closingAudience());
    }

    public function testADeclinerIsStillNotToldOfTheirOwnRefusal(): void
    {
        [$requester, $first, $second] = [$this->user('Req'), $this->user('First'), $this->user('Second')];
        $request = $this->request($requester, $first, $second);

        $request->signerFor($first)?->markDeclined(null, new \DateTimeImmutable());

        self::assertNull($request->deletedSigner());
        self::assertSame([$requester], $request->closingAudience());
    }

    private function request(User $requester, User ...$signers): SigningRequest
    {
        $request = new SigningRequest(new Document($requester, 'Contract.pdf'), $requester, new \DateTimeImmutable('+7 days'));
        foreach (array_values($signers) as $i => $signer) {
            new SigningRequestSigner($request, $signer, $i + 1);
        }

        return $request;
    }

    private function user(string $name): User
    {
        $user = (new User())->setEmail(strtolower($name).'@example.com')->setFirstName($name)->setLastName('Test');
        $user->initUuid();

        return $user;
    }
}
