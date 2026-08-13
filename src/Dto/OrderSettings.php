<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

use VeryCodeCom\DpdDe\Enum\LabelSize;
use VeryCodeCom\DpdDe\Enum\LabelStartPosition;

/** `OrderSettingsType`: shared settings applied to every item in a `setOrder` call. */
final class OrderSettings
{
    public function __construct(
        /** Requested pickup/ship date. Defaults to "now" when omitted via {@see self::default()}. */
        public readonly \DateTimeImmutable $shipDate,
        public readonly LabelSize $labelSize = LabelSize::PdfA4,
        public readonly LabelStartPosition $labelStartPosition = LabelStartPosition::UpperLeft,
    ) {
    }

    public static function default(): self
    {
        return new self(new \DateTimeImmutable());
    }
}
