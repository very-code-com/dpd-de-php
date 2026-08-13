<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `GeoDataType`: coordinates + distance, used in getParcelShopFinder responses and DepotData. */
final class GeoData
{
    public function __construct(
        public readonly float $distance,
        public readonly float $longitude,
        public readonly float $latitude,
        public readonly float $coordinateX,
        public readonly float $coordinateY,
        public readonly float $coordinateZ,
    ) {
    }
}
