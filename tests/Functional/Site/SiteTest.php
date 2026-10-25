<?php

declare(strict_types=1);

namespace App\Tests\Functional\Site;

use App\Tests\Functional\AuthWebTestCase;

final class SiteTest extends AuthWebTestCase
{
    public function testTheHomePageIsPublic(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#how-it-works');
        self::assertSelectorExists('a[href="/login"]');
        self::assertSelectorExists('footer a[href="/privacy"]');
        self::assertSelectorExists('footer a[href="/terms"]');
    }

    public function testTheLegalPagesArePublic(): void
    {
        foreach (['/privacy' => 'Privacy policy', '/terms' => 'Terms of use'] as $path => $title) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $title);
        }
    }

    public function testTheDashboardStillNeedsALogin(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }
}
