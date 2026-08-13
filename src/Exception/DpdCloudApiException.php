<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Exception;

/**
 * Thrown when DPD returns a business-logic error (Ack=false) that is not an authentication failure.
 * Contains the full list of structured DPD error entries (ErrorID / ErrorCode / message).
 */
class DpdCloudApiException extends DpdCloudException
{
    /** @param list<array{errorId: int, errorCode: string, messageShort: string, messageLong: string}> $errors */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * All DPD error codes as a flat array of strings, e.g. ['CLOUD_API_ORDER_WEIGHT: Gewicht: 0 bis 31,5 Kg.'].
     *
     * @return list<string>
     */
    public function getFormattedErrors(): array
    {
        return array_map(
            static fn (array $e) => trim($e['errorCode'] . ': ' . ($e['messageLong'] !== '' ? $e['messageLong'] : $e['messageShort'])),
            $this->errors,
        );
    }

    public function hasCode(string $errorCode): bool
    {
        foreach ($this->errors as $e) {
            if ($e['errorCode'] === $errorCode) {
                return true;
            }
        }
        return false;
    }
}
