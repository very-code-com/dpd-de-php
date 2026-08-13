<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Transport;

use VeryCodeCom\DpdDe\Exception\DpdCloudTransportException;

/**
 * HTTP transport contract shared by the SOAP and REST clients.
 * Implement this interface to swap the default cURL transport with a PSR-18 client
 * adapter or a test double.
 */
interface TransportInterface
{
    /**
     * Send the request and return the raw HTTP response.
     *
     * @throws DpdCloudTransportException on network errors, SSL failures, or timeouts.
     */
    public function send(TransportRequest $request): TransportResponse;
}
