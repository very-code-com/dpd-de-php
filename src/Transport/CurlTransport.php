<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Transport;

use VeryCodeCom\DpdDe\Exception\DpdCloudTransportException;

/**
 * Default cURL-based HTTP transport, shared by the SOAP and REST clients.
 *
 * No external dependencies beyond ext-curl, deliberately does not require ext-soap, mirroring
 * very-code-com/suus-php. SSL verification can be disabled per-request (useful for some
 * corporate/dev proxies in front of the DPD sandbox), but defaults to on.
 */
final class CurlTransport implements TransportInterface
{
    public function send(TransportRequest $request): TransportResponse
    {
        $ch = curl_init($request->url);

        if ($ch === false) {
            throw new DpdCloudTransportException('curl_init() failed. Is ext-curl installed?');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST   => $request->method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_SSL_VERIFYPEER  => $request->verifySsl,
            CURLOPT_SSL_VERIFYHOST  => $request->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT         => $request->timeout,
            CURLOPT_CONNECTTIMEOUT  => $request->connectTimeout,
        ];

        if ($request->method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        }

        curl_setopt_array($ch, $options);

        $body     = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $body === false) {
            throw new DpdCloudTransportException(
                "cURL error #{$errNo} calling DPD Cloud Service ({$request->method} {$request->url}): {$errMsg}"
            );
        }

        return new TransportResponse($httpCode, (string) $body);
    }
}
