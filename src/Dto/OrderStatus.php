<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `OrderStatusType`: full response payload of getOrderStatus (Parcel Life Cycle Service 3.1). */
final class OrderStatus
{
    public function __construct(
        public readonly ?string $parcelNo,
        public readonly ?OrderInformation $orderInformation,
        public readonly ?Address $shipAddress,
        public readonly ?StatusInfoDetail $lastStatusInfo,
        public readonly ?StatusInfoContainer $statusInfoContainer,
    ) {
    }
}
