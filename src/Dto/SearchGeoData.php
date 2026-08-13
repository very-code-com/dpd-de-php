<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `SearchGeoDataType`: coordinate-based search criteria for getParcelShopFinder. */
final class SearchGeoData
{
    public function __construct(
        public readonly float $longitude,
        public readonly float $latitude,
    ) {
    }
}
