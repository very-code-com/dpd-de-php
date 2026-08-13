<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/**
 * `TrackingProperty`: a free-form key/value pair attached to a {@see ShipmentInfo} entry.
 */
final class TrackingProperty
{
    public function __construct(
        public readonly ?string $key,
        public readonly ?string $value,
    ) {
    }
}
