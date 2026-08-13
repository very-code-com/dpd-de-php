<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `DepotDataType`: depot info attached to a {@see StatusInfo} step (getParcelLifeCycle) or {@see StatusInfoDetail} (getOrderStatus). */
final class DepotData
{
    public function __construct(
        public readonly ?string $depot,
        public readonly ?GeoData $geoData,
        public readonly ?Address $address,
    ) {
    }
}
