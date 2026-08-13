<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `ZipCodeRulesType`: pickup rules for the calling Cloud User's own account address. */
final class ZipCodeRules
{
    public function __construct(
        public readonly ?string $country,
        public readonly ?string $zipCode,
        /** Comma-separated list of dates with no pickup, as returned by DPD (raw string, not parsed). */
        public readonly ?string $noPickupDays,
        public readonly ?string $expressCutOff,
        public readonly ?string $classicCutOff,
        public readonly ?string $pickupDepot,
        public readonly ?string $state,
    ) {
    }

    /**
     * Parse `noPickupDays` into a list of DateTimeImmutable, best-effort.
     * DPD's exact date format is not documented; falls back to skipping unparsable entries.
     *
     * @return list<\DateTimeImmutable>
     */
    public function getNoPickupDaysList(): array
    {
        if ($this->noPickupDays === null || trim($this->noPickupDays) === '') {
            return [];
        }

        $dates = [];
        foreach (explode(',', $this->noPickupDays) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $part)
                ?: \DateTimeImmutable::createFromFormat('!d.m.Y', $part);
            if ($date !== false) {
                $dates[] = $date;
            }
        }

        return $dates;
    }
}
