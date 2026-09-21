<?php

declare(strict_types=1);

namespace App\AuditLog\Service;

/**
 * A checkpoint of the chain head - "at this moment the log had N entries and
 * its head was this hash" - carrying a MAC so a copy kept outside the
 * database says whether the database still agrees. One JSON line.
 */
final readonly class AuditAnchor
{
    public function __construct(
        public int $sequence,
        public string $entryHash,
        public \DateTimeImmutable $anchoredAt,
        public string $scheme,
        public string $mac,
    ) {
    }

    /** What the MAC covers: everything but itself, in a fixed order. */
    public function signedBytes(): string
    {
        return json_encode([
            'sequence' => $this->sequence,
            'entryHash' => $this->entryHash,
            'anchoredAt' => $this->anchoredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'scheme' => $this->scheme,
        ], \JSON_THROW_ON_ERROR);
    }

    public function toJsonLine(): string
    {
        return json_encode([
            'sequence' => $this->sequence,
            'entryHash' => $this->entryHash,
            'anchoredAt' => $this->anchoredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'scheme' => $this->scheme,
            'mac' => $this->mac,
        ], \JSON_THROW_ON_ERROR)."\n";
    }

    public static function fromJsonLine(string $line): self
    {
        /** @var array{sequence: int, entryHash: string, anchoredAt: string, scheme: string, mac: string} $data */
        $data = json_decode($line, true, 8, \JSON_THROW_ON_ERROR);

        return new self(
            (int) $data['sequence'],
            (string) $data['entryHash'],
            new \DateTimeImmutable((string) $data['anchoredAt']),
            (string) $data['scheme'],
            (string) $data['mac'],
        );
    }
}
