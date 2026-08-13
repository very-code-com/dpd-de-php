<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe;

/**
 * DPD Cloud Service client configuration.
 *
 * DPD Cloud Service uses two credential pairs:
 *  - PartnerCredentials (Name + Token): identifies the software/integration ("Alpha2" partner slot).
 *  - UserCredentials (cloudUserID + Token): identifies the DPD customer account (your own account).
 *
 * Three ways to build a config:
 *
 * 1. Named constructors (simplest):
 *      $config = DpdCloudConfig::sandbox($partnerName, $partnerToken, $userId, $userToken);
 *      $config = DpdCloudConfig::production($partnerName, $partnerToken, $userId, $userToken);
 *
 * 2. From environment variables (recommended for 12-factor apps):
 *      $config = DpdCloudConfig::fromEnv();
 *
 *    Reads these env vars (set in .env, docker-compose, CI secrets, etc.):
 *      DPD_CLOUD_PARTNER_NAME   - required
 *      DPD_CLOUD_PARTNER_TOKEN  - required
 *      DPD_CLOUD_USER_ID        - required (integer)
 *      DPD_CLOUD_USER_TOKEN     - required
 *      DPD_CLOUD_ENV            - "sandbox" | "production" (default: "production")
 *      DPD_CLOUD_LANGUAGE       - e.g. "de_DE", "en_US"    (default: "de_DE")
 *      DPD_CLOUD_TIMEOUT            - int seconds (default: 30)
 *      DPD_CLOUD_CONNECT_TIMEOUT    - int seconds (default: 10)
 *      DPD_CLOUD_DEBUG          - "1"/"true"/"yes"/"on" to enable debug reporting
 *
 * 3. From array (useful with framework config files):
 *      $config = DpdCloudConfig::fromArray([
 *          'partner_name'    => '...',
 *          'partner_token'   => '...',
 *          'user_id'         => 123456,
 *          'user_token'      => '...',
 *          'env'             => 'sandbox', // or 'production'
 *          'language'        => 'de_DE',
 *          'timeout'         => 30,
 *          'connect_timeout' => 10,
 *          'debug'           => false,
 *      ]);
 */
final class DpdCloudConfig
{
    public const SOAP_WSDL_SANDBOX     = 'https://cloud-stage.dpd.com/services/v1/DPDCloudService.asmx?wsdl';
    public const SOAP_WSDL_PRODUCTION  = 'https://cloud.dpd.com/services/v1/DPDCloudService.asmx?wsdl';
    public const SOAP_ENDPOINT_SANDBOX    = 'https://cloud-stage.dpd.com/services/v1/DPDCloudService.asmx';
    public const SOAP_ENDPOINT_PRODUCTION = 'https://cloud.dpd.com/services/v1/DPDCloudService.asmx';
    public const REST_BASE_SANDBOX     = 'https://cloud-stage.dpd.com/api/v1';
    public const REST_BASE_PRODUCTION  = 'https://cloud.dpd.com/api/v1';

    /** DPD Cloud Service WSDL protocol version, as sent in every request's `Version` field/header. */
    public const API_VERSION = 100;

    public function __construct(
        public readonly string $partnerName,
        public readonly string $partnerToken,
        public readonly int $userId,
        public readonly string $userToken,
        public readonly bool $sandbox = false,
        public readonly string $language = 'de_DE',
        /** Total request timeout (seconds) */
        public readonly int $timeout = 30,
        /** TCP connection timeout (seconds) */
        public readonly int $connectTimeout = 10,
        /**
         * When true, the client attaches the raw DPD response to thrown exceptions and logs a
         * full debug report (raw XML/JSON + stack trace) for every error. Useful for diagnosing
         * unexpected/unrecognised errors against your own sandbox account.
         * Leave false in production to keep exceptions and logs concise.
         */
        public readonly bool $debug = false,
    ) {
        if (trim($partnerName) === '') {
            throw new \InvalidArgumentException('DpdCloudConfig: partnerName must not be empty.');
        }
        if (trim($partnerToken) === '') {
            throw new \InvalidArgumentException('DpdCloudConfig: partnerToken must not be empty.');
        }
        if ($userId <= 0) {
            throw new \InvalidArgumentException('DpdCloudConfig: userId must be a positive integer.');
        }
        if (trim($userToken) === '') {
            throw new \InvalidArgumentException('DpdCloudConfig: userToken must not be empty.');
        }
    }

    // -- Named constructors ----------------------------------------------------

    public static function sandbox(string $partnerName, string $partnerToken, int $userId, string $userToken): self
    {
        return new self($partnerName, $partnerToken, $userId, $userToken, sandbox: true);
    }

    public static function production(string $partnerName, string $partnerToken, int $userId, string $userToken): self
    {
        return new self($partnerName, $partnerToken, $userId, $userToken, sandbox: false);
    }

    // -- Environment-variable factory ------------------------------------------

    /**
     * Build config from environment variables.
     *
     * Required: DPD_CLOUD_PARTNER_NAME, DPD_CLOUD_PARTNER_TOKEN, DPD_CLOUD_USER_ID, DPD_CLOUD_USER_TOKEN
     * Optional: DPD_CLOUD_ENV, DPD_CLOUD_LANGUAGE, DPD_CLOUD_TIMEOUT, DPD_CLOUD_CONNECT_TIMEOUT, DPD_CLOUD_DEBUG
     *
     * @throws \InvalidArgumentException if a required env var is missing.
     */
    public static function fromEnv(): self
    {
        $get = static fn (string $key): string => (string) (getenv($key) ?: ($_ENV[$key] ?? $_SERVER[$key] ?? ''));

        $partnerName  = $get('DPD_CLOUD_PARTNER_NAME');
        $partnerToken = $get('DPD_CLOUD_PARTNER_TOKEN');
        $userIdRaw    = $get('DPD_CLOUD_USER_ID');
        $userToken    = $get('DPD_CLOUD_USER_TOKEN');
        $env          = strtolower($get('DPD_CLOUD_ENV') ?: 'production');
        $language     = $get('DPD_CLOUD_LANGUAGE') ?: 'de_DE';
        $timeout      = (int) ($get('DPD_CLOUD_TIMEOUT') ?: 30);
        $connect      = (int) ($get('DPD_CLOUD_CONNECT_TIMEOUT') ?: 10);
        $debug        = in_array(strtolower($get('DPD_CLOUD_DEBUG')), ['1', 'true', 'yes', 'on'], true);

        if ($partnerName === '') {
            throw new \InvalidArgumentException('DpdCloudConfig::fromEnv(): DPD_CLOUD_PARTNER_NAME env var is not set.');
        }
        if ($partnerToken === '') {
            throw new \InvalidArgumentException('DpdCloudConfig::fromEnv(): DPD_CLOUD_PARTNER_TOKEN env var is not set.');
        }
        if ($userIdRaw === '') {
            throw new \InvalidArgumentException('DpdCloudConfig::fromEnv(): DPD_CLOUD_USER_ID env var is not set.');
        }
        if ($userToken === '') {
            throw new \InvalidArgumentException('DpdCloudConfig::fromEnv(): DPD_CLOUD_USER_TOKEN env var is not set.');
        }

        return new self(
            partnerName:    $partnerName,
            partnerToken:   $partnerToken,
            userId:         (int) $userIdRaw,
            userToken:      $userToken,
            sandbox:        $env === 'sandbox',
            language:       $language,
            timeout:        $timeout > 0 ? $timeout : 30,
            connectTimeout: $connect > 0 ? $connect : 10,
            debug:          $debug,
        );
    }

    // -- Array factory ---------------------------------------------------------

    /**
     * Build config from an associative array.
     *
     * Keys: partner_name*, partner_token*, user_id*, user_token*, env, language,
     *       timeout, connect_timeout, debug
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException on missing required keys.
     */
    public static function fromArray(array $config): self
    {
        $partnerName  = (string) ($config['partner_name']  ?? '');
        $partnerToken = (string) ($config['partner_token'] ?? '');
        $userId       = (int) ($config['user_id']          ?? 0);
        $userToken    = (string) ($config['user_token']    ?? '');
        $env          = strtolower((string) ($config['env'] ?? 'production'));
        $language     = (string) ($config['language']      ?? 'de_DE');
        $timeout      = (int) ($config['timeout']          ?? 30);
        $connect      = (int) ($config['connect_timeout']  ?? 10);
        $debug        = (bool) ($config['debug']           ?? false);

        if ($partnerName === '') {
            throw new \InvalidArgumentException('DpdCloudConfig::fromArray(): "partner_name" key is required.');
        }
        if ($partnerToken === '') {
            throw new \InvalidArgumentException('DpdCloudConfig::fromArray(): "partner_token" key is required.');
        }
        if ($userId <= 0) {
            throw new \InvalidArgumentException('DpdCloudConfig::fromArray(): "user_id" key is required and must be a positive integer.');
        }
        if ($userToken === '') {
            throw new \InvalidArgumentException('DpdCloudConfig::fromArray(): "user_token" key is required.');
        }

        return new self(
            partnerName:    $partnerName,
            partnerToken:   $partnerToken,
            userId:         $userId,
            userToken:      $userToken,
            sandbox:        $env === 'sandbox',
            language:       $language !== '' ? $language : 'de_DE',
            timeout:        $timeout > 0 ? $timeout : 30,
            connectTimeout: $connect > 0 ? $connect : 10,
            debug:          $debug,
        );
    }

    // -- Helpers ---------------------------------------------------------------

    public function getSoapEndpoint(): string
    {
        return $this->sandbox ? self::SOAP_ENDPOINT_SANDBOX : self::SOAP_ENDPOINT_PRODUCTION;
    }

    public function getRestBase(): string
    {
        return $this->sandbox ? self::REST_BASE_SANDBOX : self::REST_BASE_PRODUCTION;
    }

    /** SSL verification is enabled for both environments; DPD's sandbox uses a valid public certificate. */
    public function verifySsl(): bool
    {
        return true;
    }

    public function getEnvironment(): string
    {
        return $this->sandbox ? 'sandbox' : 'production';
    }
}
