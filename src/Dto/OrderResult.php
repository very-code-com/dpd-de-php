<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/**
 * `LabelDataType`: per-parcel result entry inside a `setOrder` response's `LabelResponse.LabelDataList`.
 */
final class OrderResult
{
    public function __construct(
        public readonly ?string $yourInternalId,
        public readonly string $parcelNo,
    ) {
    }
}
