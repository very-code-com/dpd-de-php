<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Exception;

/**
 * Thrown by {@see \VeryCodeCom\DpdDe\DpdCloudClient::validate()} / createShipment() when local
 * pre-flight validation fails, before any network call is made.
 */
class DpdCloudValidationException extends DpdCloudException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('DPD Cloud order validation failed: ' . implode('; ', $errors));
    }
}
