<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

/** The one spelling of the driver spec, derived from the suite's own answers. */
trait DriverSpecTrait
{
    /** @return array{spec: 'v1', id: string, family: string, parameter_set: string, digest: string, signing_mode: string, signature_algorithm: string} */
    public function toDriverSpec(): array
    {
        return [
            'spec' => 'v1',
            'id' => $this->id(),
            'family' => $this->family(),
            'parameter_set' => $this->parameterSet(),
            'digest' => $this->digest(),
            'signing_mode' => $this->signingMode()->value,
            'signature_algorithm' => $this->signatureAlgorithm(),
        ];
    }
}
