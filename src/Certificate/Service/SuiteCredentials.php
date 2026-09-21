<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\Certificate\Algorithm\EcdsaP384Sha384;
use App\Certificate\Algorithm\SignatureAlgorithmInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where a suite's CA and seal live: one token and one exported certificate
 * per suite for each (ADR-014). A chain is only as quantum-safe as its root,
 * so an ML-DSA leaf is issued by an ML-DSA CA, never by the classical one -
 * and switching the active suite provisions a new CA rather than re-keying
 * the old, so everything issued before keeps validating against its own.
 *
 * The classical suite keeps the pre-ADR-014 names (`sigil-ca`, `ca.crt`):
 * a token label cannot be changed without re-keying, and those tokens exist.
 */
final class SuiteCredentials
{
    private const array LEGACY_SUFFIX = [EcdsaP384Sha384::ID => ''];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/ca')]
        private readonly string $dir,
    ) {
    }

    public function caTokenLabel(SignatureAlgorithmInterface $suite): string
    {
        return 'sigil-ca'.$this->suffix($suite);
    }

    public function caCertPath(SignatureAlgorithmInterface $suite): string
    {
        return sprintf('%s/ca%s.crt', $this->dir, $this->suffix($suite));
    }

    public function sealTokenLabel(SignatureAlgorithmInterface $suite): string
    {
        return 'sigil-seal'.$this->suffix($suite);
    }

    public function sealCertPath(SignatureAlgorithmInterface $suite): string
    {
        return sprintf('%s/seal%s.crt', $this->dir, $this->suffix($suite));
    }

    private function suffix(SignatureAlgorithmInterface $suite): string
    {
        return self::LEGACY_SUFFIX[$suite->id()] ?? '-'.$suite->slug();
    }
}
