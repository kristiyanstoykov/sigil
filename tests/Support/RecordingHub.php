<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/**
 * The Mercure hub in the test environment: records what would have been
 * published instead of opening a socket to a hub that is not running.
 *
 * It decorates the real one rather than replacing it, because the subscriber
 * cookie is minted from the hub's own token factory and public URL - so the
 * authorization path stays the production one and only the wire is cut.
 *
 * @phpstan-type Published array{topic: string, data: string, private: bool}
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    private array $published = [];

    public function __construct(private readonly HubInterface $inner)
    {
    }

    private bool $failing = false;

    public function publish(Update $update): string
    {
        if ($this->failing) {
            throw new \RuntimeException('the hub is down');
        }

        $this->published[] = $update;

        return 'urn:uuid:'.bin2hex(random_bytes(8));
    }

    /** Stands in for a hub that is down, unreachable or misconfigured. */
    public function fail(bool $failing = true): void
    {
        $this->failing = $failing;
    }

    /**
     * @return list<Update>
     */
    public function published(): array
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published = [];
        $this->failing = false;
    }

    public function getPublicUrl(): string
    {
        return $this->inner->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->inner->getFactory();
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->inner->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->inner->getCookieName();
    }
}
