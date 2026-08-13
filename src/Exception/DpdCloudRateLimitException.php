<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Exception;

/**
 * Thrown when DPD rejects a call because the account's API call limit has been reached
 * (2027 CLOUD_API_USERCALLLIMIT: "API Call Limit erreicht. Bitte 10 min warten.").
 *
 * This is throttling, not an authentication problem: the credentials are valid and the same
 * call will succeed again after the cool-down. Catch this type to back off and retry rather
 * than to prompt for new credentials. It deliberately does not extend
 * {@see DpdCloudAuthException}.
 */
final class DpdCloudRateLimitException extends DpdCloudException
{
    /** DPD documents a fixed 10-minute cool-down; it does not send a Retry-After header. */
    public const RETRY_AFTER_SECONDS = 600;

    public function __construct(string $message, public readonly string $errorCode = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function retryAfterSeconds(): int
    {
        return self::RETRY_AFTER_SECONDS;
    }
}
