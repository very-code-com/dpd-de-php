<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Transport;

/**
 * A transport-agnostic HTTP request. Used for both the SOAP transport (POST, XML body,
 * SOAPAction header) and the REST transport (GET/POST, JSON body, custom headers).
 */
final class TransportRequest
{
    /**
     * @param 'GET'|'POST' $method
     * @param array<string, string> $headers Extra headers, keyed by header name (values only).
     */
    public function __construct(
        public readonly string $url,
        public readonly string $method = 'POST',
        public readonly ?string $body = null,
        public readonly array $headers = [],
        public readonly int $timeout = 30,
        public readonly int $connectTimeout = 10,
        public readonly bool $verifySsl = true,
    ) {
    }
}
