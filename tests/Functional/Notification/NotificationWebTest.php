<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Delivery\Service\DeliveryService;
use App\Document\Service\DocumentUploader;
use App\Notification\Repository\NotificationRepository;
use App\Tests\Functional\AuthWebTestCase;

/**
 * The bell, the inbox page and the one action they share: following a
 * notification marks it read and goes where it points.
 */
final class NotificationWebTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testTheBellCountsUnreadAndFollowingARowMarksItReadAndOpensTheDocument(): void
    {
        $senderEmail = $this->uniqueEmail('bell-sender');
        $recipientEmail = $this->uniqueEmail('bell-recipient');
        $sender = $this->createUser($senderEmail, verified: true, totpEnabled: true);
        $recipient = $this->createUser($recipientEmail, verified: true, totpEnabled: true);

        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($sender, self::MINIMAL_PDF, 'Notice of assignment.pdf');
        static::getContainer()->get(DeliveryService::class)->deliver($document, $sender, [$recipient]);

        $this->loginFully($recipientEmail);

        // The badge is on every page, because the bell is in the header.
        $crawler = $this->client->request('GET', '/documents');
        self::assertResponseIsSuccessful();
        self::assertSame('1 unread', trim($crawler->filter('[data-notifications-target="badge"]')->text()));

        // A delivery is the first thing waiting on the recipient, and its action
        // is to view it.
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Delivered to you: Notice of assignment.pdf', $crawler->html());

        // Following the row is a POST: it marks the row read, then redirects to
        // the document the notification was about.
        $crawler = $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/open"]')->first()->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/documents/'.$document->getId()->toRfc4122(),
            (string) $this->client->getResponse()->headers->get('Location'),
        );

        static::getContainer()->get('doctrine')->getManager()->clear();
        self::assertSame(0, static::getContainer()->get(NotificationRepository::class)->countUnreadFor($recipient));

        // Read, not deleted: the row is still on the page, and the badge is gone.
        $crawler = $this->client->request('GET', '/notifications');
        self::assertStringContainsString('Notice of assignment.pdf', $crawler->html());
        self::assertCount(0, $crawler->filter('[aria-label="Notifications (1 unread)"]'));
    }

    /**
     * The bell renders on every page, so its "Mark all read" is submitted from
     * pages that know nothing about notifications. It used to hand-roll a bare
     * `_csrf_token` field, which the Form component never reads: the submit
     * redirected and marked nothing.
     */
    public function testMarkAllReadWorksFromTheBellOnAnyPage(): void
    {
        $senderEmail = $this->uniqueEmail('markall-sender');
        $recipientEmail = $this->uniqueEmail('markall-recipient');
        $sender = $this->createUser($senderEmail, verified: true, totpEnabled: true);
        $recipient = $this->createUser($recipientEmail, verified: true, totpEnabled: true);

        $uploader = static::getContainer()->get(DocumentUploader::class);
        $deliveries = static::getContainer()->get(DeliveryService::class);
        foreach (['First notice.pdf', 'Second notice.pdf'] as $title) {
            $deliveries->deliver($uploader->upload($sender, self::MINIMAL_PDF, $title), $sender, [$recipient]);
        }

        $this->loginFully($recipientEmail);
        $notifications = static::getContainer()->get(NotificationRepository::class);
        self::assertSame(2, $notifications->countUnreadFor($recipient));

        // Deliberately not /notifications: the header is where this lives.
        $crawler = $this->client->request('GET', '/documents');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/notifications/read-all"]')->first()->form();
        $this->client->submit($form);
        self::assertResponseRedirects();

        // Back to where it was submitted from, not to the inbox.
        self::assertStringEndsWith(
            '/documents',
            (string) $this->client->getResponse()->headers->get('Location'),
        );

        static::getContainer()->get('doctrine')->getManager()->clear();
        self::assertSame(0, $notifications->countUnreadFor($recipient));

        // Read, not deleted, and the badge is gone with them.
        $crawler = $this->client->followRedirect();
        self::assertSame('', trim($crawler->filter('[data-notifications-target="badge"]')->text()));
        self::assertSame(2, $notifications->countFor($recipient));
    }

    /**
     * Mark-all returns to its referer, which makes that header a redirect
     * target. Only this exact origin is honoured: an earlier version parsed the
     * host out and treated "no host" as safe, which accepted `javascript:` and
     * `/\evil.com` - a value browsers normalise to `//evil.com`.
     */
    public function testMarkAllReadNeverRedirectsOffOrigin(): void
    {
        $senderEmail = $this->uniqueEmail('referer-sender');
        $email = $this->uniqueEmail('referer-probe');
        $sender = $this->createUser($senderEmail, verified: true, totpEnabled: true);
        $probe = $this->createUser($email, verified: true, totpEnabled: true);

        // The form only renders when something is unread.
        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($sender, self::MINIMAL_PDF, 'Redirect probe.pdf');
        static::getContainer()->get(DeliveryService::class)->deliver($document, $sender, [$probe]);

        $this->loginFully($email);

        $crawler = $this->client->request('GET', '/notifications');
        $token = $crawler->filter('input[name="mark_all_read_form[_token]"]')->attr('value');

        $hostile = [
            'https://evil.example/steal',
            '//evil.example/steal',
            '/\evil.example',
            'javascript:alert(1)',
            'http://localhost:9999/elsewhere',
        ];

        foreach ($hostile as $referer) {
            $this->client->request(
                'POST',
                '/notifications/read-all',
                ['mark_all_read_form' => ['_token' => $token]],
                [],
                ['HTTP_REFERER' => $referer],
            );

            self::assertResponseRedirects();
            self::assertStringEndsWith(
                '/notifications',
                (string) $this->client->getResponse()->headers->get('Location'),
                \sprintf('Referer "%s" was honoured as a redirect target.', $referer),
            );
        }
    }

    public function testAnEmptyInboxSaysSoAndNobodyCanOpenSomeoneElsesNotification(): void
    {
        $strangerEmail = $this->uniqueEmail('bell-stranger');
        $senderEmail = $this->uniqueEmail('bell-sender2');
        $recipientEmail = $this->uniqueEmail('bell-recipient2');
        $this->createUser($strangerEmail, verified: true, totpEnabled: true);
        $sender = $this->createUser($senderEmail, verified: true, totpEnabled: true);
        $recipient = $this->createUser($recipientEmail, verified: true, totpEnabled: true);

        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($sender, self::MINIMAL_PDF, 'Private matter.pdf');
        static::getContainer()->get(DeliveryService::class)->deliver($document, $sender, [$recipient]);
        $notification = static::getContainer()->get(NotificationRepository::class)->findRecentFor($recipient)[0];

        $this->loginFully($strangerEmail);

        $crawler = $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing yet', $crawler->html());

        // Someone else's inbox row does not exist as far as this user is concerned.
        $this->client->request('POST', '/notifications/'.$notification->getId()->toRfc4122().'/open');
        self::assertResponseStatusCodeSame(404);
    }
}
