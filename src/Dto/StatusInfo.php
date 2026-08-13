<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

use VeryCodeCom\DpdDe\Enum\TrackingStatus;

/**
 * `StatusInfo` (ParcelLifeCycleService/2.0 namespace): one tracking milestone as returned by
 * getParcelLifeCycle. This is the older, UI-rendering-oriented tracking model.
 *
 * For the newer, more structured tracking model used by getOrderStatus, see
 * {@see StatusInfoDetail} instead.
 */
class StatusInfo
{
    /**
     * @param list<ContentItem> $normalItems
     * @param list<ContentItem> $importantItems
     * @param list<ContentItem> $errorItems
     */
    public function __construct(
        public readonly TrackingStatus $status,
        public readonly ?ContentLine $label,
        public readonly ?ContentItem $description,
        public readonly bool $statusHasBeenReached,
        public readonly bool $isCurrentStatus,
        public readonly bool $showContactInfo,
        public readonly ?ContentLine $location,
        public readonly ?ContentLine $date,
        public readonly array $normalItems,
        public readonly array $importantItems,
        public readonly array $errorItems,
    ) {
    }
}
