<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Core\Entity\User;
use App\Delivery\Service\DeliveryService;
use App\Document\Entity\Document;
use App\Document\Service\DocumentUploader;
use App\Notification\Repository\NotificationRepository;
use App\Notification\Service\InboxTopic;
use App\Tests\Functional\AuthWebTestCase;
use App\Tests\Support\RecordingHub;

/**
 * The live half of the inbox (ADR-013): what leaves the app for the hub, and
 * what the hub is allowed to tell whom.
 *
 * The hub is a separate process holding a shared key. Everything here exists to
 * pin the two properties that follow from that - it learns nothing, and it can
 * hand one person's topic to nobody else.
 */
class LivePushTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testEachRecipientIsNudgedOnTheirOwnTopicAndTheNudgeSaysNothing(): void
    {
        $sender = $this->user('push-sender');
        $first = $this->user('push-first');
        $second = $this->user('push-second');
        $document = $this->upload($sender, 'Notice of assignment.pdf');

        $hub = $this->hub();
        $hub->reset();

        static::getContainer()->get(DeliveryService::class)
            ->deliver($document, $sender, [$first, $second], 'For your records.');

        $updates = $hub->published();
        self::assertCount(2, $updates);

        $topics = [];
        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate(), 'A public update would be readable without a cookie.');
            $topics[] = $update->getTopics();

            // The whole point of the nudge: the hub is told that something
            // happened, never what. Titles and bodies stay in Postgres and reach
            // the browser over its own authenticated request.
            self::assertSame('{"event":"notification.created"}', $update->getData());
            self::assertStringNotContainsStringIgnoringCase('Notice of assignment', $update->getData());
            self::assertStringNotContainsString('For your records.', $update->getData());
        }

        self::assertSame([[InboxTopic::for($first)], [InboxTopic::for($second)]], $topics);

        // The sender served it. Nothing is pushed to them, exactly as nothing is
        // stored for them.
        foreach ($topics as $topic) {
            self::assertNotSame([InboxTopic::for($sender)], $topic);
        }
    }

    /**
     * Store first, push second. A hub that is down, unreachable or simply not
     * running must cost the recipient immediacy and nothing else.
     */
    public function testAHubFailureLeavesTheStoredNotificationIntact(): void
    {
        $sender = $this->user('down-sender');
        $recipient = $this->user('down-recipient');
        $document = $this->upload($sender, 'Served anyway.pdf');

        $hub = $this->hub();
        $hub->reset();
        $hub->fail();

        try {
            static::getContainer()->get(DeliveryService::class)
                ->deliver($document, $sender, [$recipient]);
        } finally {
            $hub->fail(false);
        }

        $rows = static::getContainer()->get(NotificationRepository::class)->findRecentFor($recipient);
        self::assertCount(1, $rows, 'The row is the notification; the push is only how it arrives sooner.');
        self::assertStringContainsString('Served anyway.pdf', $rows[0]->getTitle());
        self::assertSame([], $hub->published());
    }

    /**
     * What the nudge actually causes: the browser re-reads its own inbox. The
     * two marked regions are the contract between the fragment and the
     * controller that swaps them - a rename on either side is silent, so it is
     * asserted here.
     */
    public function testTheBellFragmentCarriesBothRegionsAndTheCurrentCount(): void
    {
        $senderEmail = $this->uniqueEmail('fragment-sender');
        $readerEmail = $this->uniqueEmail('fragment-reader');
        $sender = $this->createUser($senderEmail, verified: true, totpEnabled: true);
        $reader = $this->createUser($readerEmail, verified: true, totpEnabled: true);

        $document = $this->upload($sender, 'Nudged into view.pdf');
        static::getContainer()->get(DeliveryService::class)->deliver($document, $sender, [$reader]);

        $this->loginFully($readerEmail);
        $crawler = $this->client->request('GET', '/notifications/bell');

        self::assertResponseIsSuccessful();
        self::assertSame('1 unread', trim($crawler->filter('[data-bell-region="badge"]')->text()));
        self::assertStringContainsString('Nudged into view.pdf', $crawler->filter('[data-bell-region="menu"]')->html());
    }

    /**
     * The cookie is the only thing the hub can check, so it must grant exactly
     * one topic: the holder's own inbox. A wildcard here would make every
     * notification in the system readable by any logged-in user.
     */
    public function testTheSubscriberCookieGrantsOnlyTheReadersOwnInbox(): void
    {
        $email = $this->uniqueEmail('cookie-reader');
        $reader = $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if ('mercureAuthorization' === $candidate->getName()) {
                $cookie = $candidate;
            }
        }

        self::assertNotNull($cookie, 'Without the cookie the hub refuses the subscription outright.');
        self::assertTrue($cookie->isHttpOnly(), 'Script-readable would put the grant one XSS away.');

        // Scoped to the hub's own path, so it rides along to the hub and to
        // nothing else - cookies ignore the port, which is what lets one cookie
        // cross from :8000 to :3000 in the first place.
        self::assertSame('/.well-known/mercure', $cookie->getPath());

        $claims = $this->jwtClaims((string) $cookie->getValue());
        self::assertSame(
            ['subscribe' => [InboxTopic::for($reader)]],
            $claims['mercure'] ?? null,
        );
        self::assertArrayNotHasKey('publish', $claims['mercure']);
    }

    /**
     * @return array<string, mixed>
     */
    private function jwtClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'Not a JWT.');

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($payload);

        $claims = json_decode($payload, true);
        self::assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    private function hub(): RecordingHub
    {
        $hub = static::getContainer()->get(RecordingHub::class);
        \assert($hub instanceof RecordingHub);

        return $hub;
    }

    private function user(string $slug): User
    {
        return $this->createUser($this->uniqueEmail($slug), verified: true, totpEnabled: true);
    }

    private function upload(User $owner, string $title = 'Document.pdf'): Document
    {
        return static::getContainer()->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, $title);
    }
}
