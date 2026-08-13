<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Transport;

/** The raw HTTP response returned by a {@see TransportInterface}. */
final class TransportResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
