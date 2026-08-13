<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `ContentLine`: a single formatted line of tracking text (getParcelLifeCycle's UI-oriented model). */
final class ContentLine
{
    public function __construct(
        public readonly ?string $content,
        public readonly bool $bold,
        public readonly bool $paragraph,
    ) {
    }
}
