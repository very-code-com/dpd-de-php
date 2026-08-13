<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/**
 * `TrackingResult` (ParcelLifeCycleService/2.0): full response payload of getParcelLifeCycle.
 */
final class TrackingResult
{
    /**
     * @param list<StatusInfo> $statusInfo
     * @param list<ContentItem> $contactInfo
     */
    public function __construct(
        public readonly ?ShipmentInfo $shipmentInfo,
        public readonly array $statusInfo,
        public readonly array $contactInfo,
    ) {
    }
}
