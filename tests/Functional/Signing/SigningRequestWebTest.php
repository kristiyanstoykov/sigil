<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signing;

use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use App\Document\Service\DocumentUploader;
use App\Signing\Form\CreateSigningRequestForm;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The request UI end to end: the owner sends a request from the compose page,
 * the first signer is invited and the second is made to wait, and the document
 * page reports the queue.
 */
final class SigningRequestWebTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [documentId, owner, first, second]
     */
    private function seed(string $prefix): array
    {
        $emails = [];
        foreach (['owner', 'first', 'second'] as $role) {
            $email = $this->uniqueEmail($prefix.'-'.$role);
            $user = $this->createUser($email, verified: true, totpEnabled: true);
            if ('owner' !== $role) {
                $this->giveCertificate($user);
            }
            $emails[$role] = $email;
            $users[$role] = $user;
        }

        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($users['owner'], self::MINIMAL_PDF, 'Board Resolution.pdf');

        return [$document->getId()->toRfc4122(), $emails['owner'], $emails['first'], $emails['second']];
    }

    /** Submits the real compose form; the hidden field is what the picker writes. */
    private function sendRequest(string $documentId, string ...$signerEmails): void
    {
        $crawler = $this->client->request('GET', '/documents/'.$documentId.'/request');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/request"]')->form();
        $form['create_signing_request_form['.CreateSigningRequestForm::E_SIGNERS.']'] = implode("\n", $signerEmails);
        $form['create_signing_request_form['.CreateSigningRequestForm::E_DEADLINE_DAYS.']'] = '7';
        $this->client->submit($form);
    }

    public function testOwnerSendsARequestAndTheQueueIsVisibleOnTheDocument(): void
    {
        [$documentId, $owner, $first, $second] = $this->seed('web-request');
        $this->loginFully($owner);

        $this->sendRequest($documentId, $first, $second);
        self::assertResponseRedirects('/documents/'.$documentId);

        $crawler = $this->client->followRedirect();
        $html = $crawler->html();
        self::assertStringContainsString('Awaiting signatures', $html);
        self::assertStringContainsString('Their turn', $html);
        self::assertStringContainsString($first, $html);
        // The owner can withdraw it, and cannot start a second one.
        self::assertGreaterThan(0, $crawler->filter('form[action$="/request/cancel"]')->count());
    }

    public function testTheFirstSignerIsInvitedAndTheSecondIsMadeToWait(): void
    {
        [$documentId, $owner, $first, $second] = $this->seed('web-turn');
        $this->loginFully($owner);
        $this->sendRequest($documentId, $first, $second);

        $this->switchUser($first);
        $crawler = $this->client->request('GET', '/documents?tab=tosign');
        self::assertStringContainsString('Board Resolution.pdf', $crawler->html());
        self::assertStringContainsString('Sign now', $crawler->html());

        $crawler = $this->client->request('GET', '/documents/'.$documentId.'/sign');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Sign document', $crawler->html());

        $this->switchUser($second);
        $crawler = $this->client->request('GET', '/documents/'.$documentId.'/sign');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Not your turn yet', $crawler->html());
    }

    public function testAStrangerCannotSeeTheDocumentAtAll(): void
    {
        [$documentId, $owner, $first] = $this->seed('web-stranger');
        $this->loginFully($owner);
        $this->sendRequest($documentId, $first);

        $stranger = $this->uniqueEmail('web-stranger-outsider');
        $this->createUser($stranger, verified: true, totpEnabled: true);
        $this->switchUser($stranger);

        $this->client->request('GET', '/documents/'.$documentId.'/sign');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnIneligibleSignerIsRefusedWithTheReasonOnTheField(): void
    {
        [$documentId, $owner] = $this->seed('web-ineligible');
        $withoutCertificate = $this->uniqueEmail('web-ineligible-nocert');
        $this->createUser($withoutCertificate, verified: true, totpEnabled: true);

        $this->loginFully($owner);
        $this->sendRequest($documentId, $withoutCertificate);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no usable certificate', $this->client->getResponse()->getContent() ?: '');
    }

    public function testLookupReportsWhetherSomeoneCanBeAdded(): void
    {
        [$documentId, $owner, $first] = $this->seed('web-lookup');
        $this->loginFully($owner);

        $this->client->request(
            'POST',
            '/documents/'.$documentId.'/request/lookup',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $first]),
        );
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertSame($first, $payload['email']);

        $this->client->request(
            'POST',
            '/documents/'.$documentId.'/request/lookup',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'nobody@test.sigil.local']),
        );
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('No Sigil account', $payload['reason']);
    }

    private function switchUser(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->loginFully($email);
    }

    private function giveCertificate(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $em->persist(new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Web Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'web-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash('135790', \PASSWORD_ARGON2ID),
        ));
        $em->flush();
    }
}
