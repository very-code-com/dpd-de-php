<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\DpdCloudConfig;

final class DpdCloudConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'DPD_CLOUD_PARTNER_NAME', 'DPD_CLOUD_PARTNER_TOKEN', 'DPD_CLOUD_USER_ID', 'DPD_CLOUD_USER_TOKEN',
        'DPD_CLOUD_ENV', 'DPD_CLOUD_LANGUAGE', 'DPD_CLOUD_TIMEOUT', 'DPD_CLOUD_CONNECT_TIMEOUT', 'DPD_CLOUD_DEBUG',
    ];

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    // -- constructor validation --------------------------------------------------

    public function testConstructorThrowsWhenPartnerNameEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DpdCloudConfig('', 'token', 1, 'usertoken');
    }

    public function testConstructorThrowsWhenUserIdNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DpdCloudConfig('Partner', 'token', 0, 'usertoken');
    }

    // -- named constructors --------------------------------------------------

    public function testSandboxNamedConstructor(): void
    {
        $config = DpdCloudConfig::sandbox('Partner', 'ptoken', 123, 'utoken');

        self::assertTrue($config->sandbox);
        self::assertSame('sandbox', $config->getEnvironment());
        self::assertSame(DpdCloudConfig::SOAP_ENDPOINT_SANDBOX, $config->getSoapEndpoint());
        self::assertSame(DpdCloudConfig::REST_BASE_SANDBOX, $config->getRestBase());
    }

    public function testProductionNamedConstructor(): void
    {
        $config = DpdCloudConfig::production('Partner', 'ptoken', 123, 'utoken');

        self::assertFalse($config->sandbox);
        self::assertSame('production', $config->getEnvironment());
        self::assertSame(DpdCloudConfig::SOAP_ENDPOINT_PRODUCTION, $config->getSoapEndpoint());
        self::assertSame(DpdCloudConfig::REST_BASE_PRODUCTION, $config->getRestBase());
    }

    public function testVerifySslIsAlwaysTrue(): void
    {
        self::assertTrue(DpdCloudConfig::sandbox('P', 't', 1, 'u')->verifySsl());
        self::assertTrue(DpdCloudConfig::production('P', 't', 1, 'u')->verifySsl());
    }

    // -- fromEnv() -------------------------------------------------------------

    public function testFromEnvProductionByDefault(): void
    {
        putenv('DPD_CLOUD_PARTNER_NAME=Partner');
        putenv('DPD_CLOUD_PARTNER_TOKEN=ptoken');
        putenv('DPD_CLOUD_USER_ID=123456');
        putenv('DPD_CLOUD_USER_TOKEN=utoken');

        $config = DpdCloudConfig::fromEnv();

        self::assertSame('Partner', $config->partnerName);
        self::assertSame('ptoken', $config->partnerToken);
        self::assertSame(123456, $config->userId);
        self::assertSame('utoken', $config->userToken);
        self::assertFalse($config->sandbox);
        self::assertSame('de_DE', $config->language);
    }

    public function testFromEnvSandbox(): void
    {
        putenv('DPD_CLOUD_PARTNER_NAME=Partner');
        putenv('DPD_CLOUD_PARTNER_TOKEN=ptoken');
        putenv('DPD_CLOUD_USER_ID=123456');
        putenv('DPD_CLOUD_USER_TOKEN=utoken');
        putenv('DPD_CLOUD_ENV=sandbox');

        $config = DpdCloudConfig::fromEnv();

        self::assertTrue($config->sandbox);
    }

    public function testFromEnvThrowsWhenPartnerNameMissing(): void
    {
        putenv('DPD_CLOUD_PARTNER_TOKEN=ptoken');
        putenv('DPD_CLOUD_USER_ID=123456');
        putenv('DPD_CLOUD_USER_TOKEN=utoken');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DPD_CLOUD_PARTNER_NAME/');

        DpdCloudConfig::fromEnv();
    }

    public function testFromEnvThrowsWhenUserIdMissing(): void
    {
        putenv('DPD_CLOUD_PARTNER_NAME=Partner');
        putenv('DPD_CLOUD_PARTNER_TOKEN=ptoken');
        putenv('DPD_CLOUD_USER_TOKEN=utoken');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DPD_CLOUD_USER_ID/');

        DpdCloudConfig::fromEnv();
    }

    public function testFromEnvReadsDebugFlag(): void
    {
        putenv('DPD_CLOUD_PARTNER_NAME=Partner');
        putenv('DPD_CLOUD_PARTNER_TOKEN=ptoken');
        putenv('DPD_CLOUD_USER_ID=123456');
        putenv('DPD_CLOUD_USER_TOKEN=utoken');
        putenv('DPD_CLOUD_DEBUG=true');

        $config = DpdCloudConfig::fromEnv();

        self::assertTrue($config->debug);
    }

    // -- fromArray() -----------------------------------------------------------

    public function testFromArraySandbox(): void
    {
        $config = DpdCloudConfig::fromArray([
            'partner_name' => 'Partner',
            'partner_token' => 'ptoken',
            'user_id' => 123456,
            'user_token' => 'utoken',
            'env' => 'sandbox',
            'language' => 'en_US',
            'timeout' => 45,
        ]);

        self::assertTrue($config->sandbox);
        self::assertSame('en_US', $config->language);
        self::assertSame(45, $config->timeout);
    }

    public function testFromArrayThrowsWhenUserTokenMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"user_token"/');

        DpdCloudConfig::fromArray([
            'partner_name' => 'Partner',
            'partner_token' => 'ptoken',
            'user_id' => 123456,
        ]);
    }

    public function testApiVersionConstant(): void
    {
        self::assertSame(100, DpdCloudConfig::API_VERSION);
    }
}
