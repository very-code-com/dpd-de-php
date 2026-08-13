<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `HolidayType`: a closure period for a ParcelShop (e.g. annual leave). */
final class Holiday
{
    public function __construct(
        public readonly \DateTimeImmutable $holidayFrom,
        public readonly \DateTimeImmutable $holidayEnd,
    ) {
    }
}
