<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `OpeningHoursType`: opening hours for one weekday of a ParcelShop. */
final class OpeningHours
{
    /** @param list<OpenTime> $openTimeList */
    public function __construct(
        public readonly ?string $weekDay,
        public readonly array $openTimeList,
    ) {
    }
}
