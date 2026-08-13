<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

use VeryCodeCom\DpdDe\Enum\ShipService;

/**
 * `ParcelDataType`: shipping product, weight and free-text references for a single parcel.
 *
 * Note: unlike SUUS, DPD Cloud Service does not expose package dimensions (length/width/height)
 * in this API, only weight.
 */
final class Parcel
{
    public function __construct(
        public readonly ShipService $shipService,
        /** Weight in kg. DPD accepts 0-31.5 kg per parcel. */
        public readonly float $weightKg,
        public readonly ?string $content = null,
        /** Your own internal reference, echoed back in setOrder's LabelDataList. */
        public readonly ?string $yourInternalId = null,
        public readonly ?string $reference1 = null,
        public readonly ?string $reference2 = null,
        /** @deprecated see {@see Cod} */
        public readonly ?Cod $cod = null,
    ) {
    }
}
