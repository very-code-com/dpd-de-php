<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `OrderInformationType`: shipment metadata attached to a getOrderStatus response. */
final class OrderInformation
{
    public function __construct(
        public readonly ?string $parcelNo,
        /** Multi-parcel-shipment ID, when the parcel is part of one (set by DPD, not by us, see {@see \VeryCodeCom\DpdDe\Dto\OrderItem}). */
        public readonly ?string $mpsId,
        public readonly int $serviceCode,
        public readonly ?string $productName,
        public readonly ?string $reference,
        public readonly ?string $weight,
        /** @deprecated COD discontinued 2020-05-11; kept for WSDL compatibility. */
        public readonly ?string $codAmount,
        public readonly int $collis,
        /** Comma-separated list of all parcel numbers belonging to the same shipment/MPS. */
        public readonly ?string $parcelNoList,
        public readonly bool $completeDelivery,
        public readonly ?string $receiverName,
        public readonly ?string $senderName,
        public readonly ?string $estimatedDeliveryTime,
    ) {
    }
}
