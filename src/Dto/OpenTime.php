<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `OpenTimeType`: a single opening time window (e.g. "08:00" to "18:00"). */
final class OpenTime
{
    public function __construct(
        public readonly ?string $timeFrom,
        public readonly ?string $timeEnd,
    ) {
    }
}
