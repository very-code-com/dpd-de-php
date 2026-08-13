<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/**
 * A single entry in `setOrder`'s `OrderDataList`: one parcel + its own ship address.
 *
 * Important: DPD Cloud Service does **not** support multi-parcel shipments (MPS) through this
 * API; each physical package needs its own `OrderItem` (see the DPD Cloud Service FAQ, point V).
 * Use {@see \VeryCodeCom\DpdDe\DpdCloudClient::createShipments()} to send up to 30 items in a
 * single call.
 */
final class OrderItem
{
    public function __construct(
        public readonly Address $shipAddress,
        /** ParcelShop pickup point ID (required by the WSDL even for classic home delivery, use 0 if not applicable to your account, per your DPD contract). */
        public readonly int $parcelShopId,
        public readonly Parcel $parcel,
        /** Undocumented in the PDF (09/2023) but present in the live WSDL, likely a newer PUDO/locker identifier, passed through 1:1. */
        public readonly ?string $pudoId = null,
    ) {
    }
}
