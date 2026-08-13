<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/**
 * `StatusInfoType` (DPD Cloud namespace): one tracking step in the newer, structured
 * getOrderStatus model (`LastStatusInfo` / `StatusInfoContainer.*`).
 *
 * Not to be confused with {@see StatusInfo}, which is the older getParcelLifeCycle model
 * (`ParcelLifeCycleService/2.0` namespace) with a different shape.
 */
final class StatusInfoDetail
{
    public function __construct(
        public readonly bool $statusReached,
        public readonly ?string $statusId,
        public readonly ?string $headline,
        public readonly ?string $description,
        public readonly ?string $statusTextMobile,
        public readonly ?string $statusTextDesktop,
        /** Raw date string as returned by DPD (format not strictly specified in the docs). */
        public readonly ?string $statusDate,
        public readonly ?DepotData $depotData,
    ) {
    }
}
