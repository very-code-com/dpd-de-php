<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

use VeryCodeCom\DpdDe\Enum\TrackingStatus;

/**
 * `ShipmentInfo` (ParcelLifeCycleService/2.0). Extends {@see StatusInfo} with receiver/service
 * details and free-form tracking properties. This is the top-level "header" entry of a
 * getParcelLifeCycle response.
 */
final class ShipmentInfo extends StatusInfo
{
    /** @param list<TrackingProperty> $trackingProperty */
    public function __construct(
        TrackingStatus $status,
        ?ContentLine $label,
        ?ContentItem $description,
        bool $statusHasBeenReached,
        bool $isCurrentStatus,
        bool $showContactInfo,
        ?ContentLine $location,
        ?ContentLine $date,
        array $normalItems,
        array $importantItems,
        array $errorItems,
        public readonly ?ContentItem $receiver,
        public readonly ?ContentItem $predictInformation,
        public readonly ?ContentItem $serviceDescription,
        public readonly ?ContentItem $additionalServiceElements,
        public readonly array $trackingProperty,
    ) {
        parent::__construct(
            $status,
            $label,
            $description,
            $statusHasBeenReached,
            $isCurrentStatus,
            $showContactInfo,
            $location,
            $date,
            $normalItems,
            $importantItems,
            $errorItems,
        );
    }
}
