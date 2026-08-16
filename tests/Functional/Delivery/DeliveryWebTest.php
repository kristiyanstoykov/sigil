<?php

declare(strict_types=1);

namespace App\Tests\Functional\Delivery;

use App\Delivery\Form\DeliverDocumentForm;
use App\Document\Service\DocumentUploader;
use App\Tests\Functional\AuthWebTestCase;

/**
 * The delivery UI end to end: the owner serves a document, the recipient finds it
 * in their library badged Recipient, and the document page records who was served.
 */
final class DeliveryWebTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testTheOwnerServesADocumentAndBothSidesSeeTheRecord(): void
    {
        $sender = $this->uniqueEmail('deliver-sender');
        $recipient = $this->uniqueEmail('deliver-recipient');
        $senderUser = $this->createUser($sender, verified: true, totpEnabled: true);
        $this->createUser($recipient, verified: true, totpEnabled: true);

        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($senderUser, self::MINIMAL_PDF, 'Notice of assignment.pdf');
        $id = $document->getId()->toRfc4122();

        $this->loginFully($sender);

        // The document page offers delivery as its own action, not as a variant
        // of asking for a signature.
        $crawler = $this->client->request('GET', '/documents/'.$id);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Serve this on someone', $crawler->html());

        $crawler = $this->client->request('GET', '/documents/'.$id.'/deliver');
        self::assertResponseIsSuccessful();

        // The picker writes the hidden field; submitting the rendered form
        // exercises the real field names and CSRF token.
        $form = $crawler->filter('form[action$="/deliver"]')->form();
        $form['deliver_document_form['.DeliverDocumentForm::E_RECIPIENTS.']'] = $recipient;
        $form['deliver_document_form['.DeliverDocumentForm::E_NOTE.']'] = 'For your records.';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $show = $this->client->followRedirect()->html();
        self::assertStringContainsString('Delivered to', $show);
        self::assertStringContainsString($recipient, $show);
        self::assertStringContainsString('For your records.', $show);

        // The recipient can open it, and their library says why.
        $this->client->getCookieJar()->clear();
        $this->loginFully($recipient);

        $this->client->request('GET', '/documents/'.$id);
        self::assertResponseIsSuccessful();

        $list = $this->client->request('GET', '/documents?role=others')->html();
        self::assertStringContainsString('Notice of assignment.pdf', $list);
        self::assertStringContainsString('Recipient', $list);
    }

    public function testAStrangerCannotDeliverSomeoneElsesDocument(): void
    {
        $owner = $this->uniqueEmail('deliver-owner');
        $stranger = $this->uniqueEmail('deliver-stranger');
        $ownerUser = $this->createUser($owner, verified: true, totpEnabled: true);
        $this->createUser($stranger, verified: true, totpEnabled: true);

        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($ownerUser, self::MINIMAL_PDF, 'Private.pdf');

        $this->loginFully($stranger);
        $this->client->request('GET', '/documents/'.$document->getId()->toRfc4122().'/deliver');
        self::assertResponseStatusCodeSame(404);
    }
}
