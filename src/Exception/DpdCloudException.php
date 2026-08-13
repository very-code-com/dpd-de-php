<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Exception;

/**
 * Base exception for all DPD Cloud Service errors.
 *
 * Every exception can optionally carry the raw DPD response (XML or JSON, depending on the
 * transport used) that triggered it. This is populated by the client when debugging is enabled
 * (see {@see \VeryCodeCom\DpdDe\DpdCloudConfig::$debug}) so that unexpected / unrecognised errors
 * can be inspected verbatim, together with a full stack trace, via {@see self::getDebugReport()}.
 */
class DpdCloudException extends \RuntimeException
{
    /** Raw DPD response body (XML or JSON) associated with this error, if captured. */
    protected ?string $rawResponse = null;

    /** The raw DPD response body that triggered this error, or null. */
    public function getRawResponse(): ?string
    {
        return $this->rawResponse;
    }

    /**
     * Attach the raw DPD response body to this exception (fluent).
     *
     * @return $this
     */
    public function withRawResponse(?string $rawResponse): static
    {
        $this->rawResponse = $rawResponse;
        return $this;
    }

    /**
     * Build a verbose debug report: exception class + message, the raw DPD response (when
     * captured) and the full stack trace. Intended for logs or developer-facing output when an
     * unexpected error needs investigation.
     */
    public function getDebugReport(): string
    {
        $report = sprintf('%s: %s', static::class, $this->getMessage());

        if ($this->rawResponse !== null && $this->rawResponse !== '') {
            $report .= "\n\n--- Raw DPD response ---\n" . $this->rawResponse;
        }

        if ($this->getPrevious() !== null) {
            $report .= "\n\n--- Caused by ---\n"
                . get_class($this->getPrevious()) . ': ' . $this->getPrevious()->getMessage();
        }

        $report .= "\n\n--- Stack trace ---\n" . $this->getTraceAsString();

        return $report;
    }
}
