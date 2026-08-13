<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Exception;

/**
 * Thrown when DPD rejects the Partner or User credentials.
 *
 * Covers the following DPD Cloud Service error codes:
 *   2000 CLOUD_API_PARTNERCREDENTIALS, Partner Credentials invalid.
 *   2001 CLOUD_API_USERCREDENTIALS    - User Credentials invalid.
 *   2002 CLOUD_API_NOLOGIN            - Webservice credentials incorrect.
 *   2021 CLOUD_API_NOUSERACCESS       - Invalid credentials.
 *
 * 2027 CLOUD_API_USERCALLLIMIT (rate limit) is reported as
 * {@see DpdCloudRateLimitException}, which currently still extends this class.
 */
class DpdCloudAuthException extends DpdCloudException
{
    public function __construct(string $message, public readonly string $errorCode = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
