<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `ContentItem`: a labelled block of one or more {@see ContentLine}s, optionally linking out. */
final class ContentItem
{
    /** @param list<ContentLine> $content */
    public function __construct(
        public readonly ?ContentLine $label,
        public readonly array $content,
        public readonly ?string $linkTarget,
    ) {
    }

    /** Convenience: all content lines joined with newlines, ignoring bold/paragraph formatting. */
    public function getText(): string
    {
        return implode("\n", array_filter(array_map(
            static fn (ContentLine $l) => $l->content ?? '',
            $this->content,
        )));
    }
}
