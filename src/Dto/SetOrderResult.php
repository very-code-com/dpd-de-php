<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/**
 * Full result of a successful `setOrder` call (`LabelResponseType` in the WSDL).
 *
 * `labelPdf` already holds the raw decoded document bytes. DPD's Base64 encoding is decoded
 * for you. In practice this is always a PDF: DPD accepts
 * {@see \VeryCodeCom\DpdDe\Enum\LabelSize::ZplA6} without an error but still answers with a
 * PDF payload.
 */
final class SetOrderResult
{
    /** @param list<OrderResult> $items One entry per parcel, in the same order as the request's OrderDataList. */
    public function __construct(
        public readonly string $labelPdf,
        public readonly array $items,
    ) {
    }

    /** Convenience: the parcel number of the first (or only) item, or null if none. */
    public function firstParcelNo(): ?string
    {
        return $this->items[0]->parcelNo ?? null;
    }
}
