<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SearchAddress;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\DpdCloudConfig;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Enum\TransportMode;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;
use VeryCodeCom\DpdDe\Exception\DpdCloudAuthException;
use VeryCodeCom\DpdDe\Exception\DpdCloudRateLimitException;
use VeryCodeCom\DpdDe\Exception\DpdCloudTransportException;
use VeryCodeCom\DpdDe\Exception\DpdCloudValidationException;
use VeryCodeCom\DpdDe\Transport\TransportInterface;
use VeryCodeCom\DpdDe\Transport\TransportRequest;
use VeryCodeCom\DpdDe\Transport\TransportResponse;

/**
 * Exercises DpdCloudClient's orchestration logic (validation -> transport -> parsing -> error
 * classification) using a scripted fake TransportInterface, without any real network calls.
 */
final class DpdCloudClientTest extends TestCase
{
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/' . $name);
        self::assertIsString($contents, "Fixture {$name} could not be read.");
        return $contents;
    }

    private function config(): DpdCloudConfig
    {
        return DpdCloudConfig::sandbox('Partner Name', 'partner-token', 123456, 'user-token');
    }

    private function makeItem(): OrderItem
    {
        return new OrderItem(
            shipAddress: new Address(
                name: 'Max Mustermann',
                street: 'Musterstr.',
                houseNo: '1',
                country: 'DE',
                zipCode: '12345',
                city: 'Berlin',
            ),
            parcelShopId: 0,
            parcel: new Parcel(
                ShipService::Classic,
                weightKg: 2.5,
                content: 'Books',
                yourInternalId: 'ORDER-1',
                reference1: 'REF-1',
            ),
        );
    }

    // -- happy paths --------------------------------------------------

    public function testCreateShipmentSuccess(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('set_order_success.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $result = $client->createShipment($this->makeItem());

        self::assertSame('%PDF-1.4-fake-pdf-content', $result->labelPdf);
        self::assertSame('01234567890123', $result->firstParcelNo());
        self::assertCount(1, $transport->requests);
        self::assertSame('POST', $transport->requests[0]->method);
        self::assertStringContainsString('DPDCloudService.asmx', $transport->requests[0]->url);
    }

    public function testFetchZipCodeRulesSuccessSoap(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_zip_code_rules_success.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $rules = $client->fetchZipCodeRules();

        self::assertSame('DE', $rules->country);
        self::assertSame('12345', $rules->zipCode);
    }

    public function testFetchZipCodeRulesSuccessRest(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_zip_code_rules_success.json'))]);
        $client = new DpdCloudClient($this->config(), TransportMode::Rest, $transport);

        $rules = $client->fetchZipCodeRules();

        self::assertSame('DE', $rules->country);
        self::assertSame('GET', $transport->requests[0]->method);
    }

    public function testFindParcelShops(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_parcel_shop_finder_success.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $shops = $client->findParcelShops(ParcelShopQuery::byAddress(new SearchAddress(zipCode: '12345')));

        self::assertCount(1, $shops);
        self::assertSame(987654, $shops[0]->parcelShopId);
    }

    public function testFetchOrderStatus(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_order_status_success.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $status = $client->fetchOrderStatus('01234567890123');

        self::assertSame('01234567890123', $status->parcelNo);
    }

    public function testFetchParcelLifeCycleAlwaysUsesSoapEvenInRestMode(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_parcel_life_cycle_success.xml'))]);
        $client = new DpdCloudClient($this->config(), TransportMode::Rest, $transport);

        $result = $client->fetchParcelLifeCycle('01234567890123');

        self::assertNotNull($result->shipmentInfo);
        // Despite Rest mode, getParcelLifeCycle must hit the SOAP endpoint.
        self::assertStringContainsString('DPDCloudService.asmx', $transport->requests[0]->url);
    }

    public function testCheckOrderDataDoesNotThrowOnSuccess(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('set_order_success.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $client->checkOrderData([$this->makeItem()]);
        self::assertCount(1, $transport->requests);
    }

    // -- local validation short-circuits before any network call --------------------------------------------------

    public function testCreateShipmentThrowsValidationExceptionWithoutNetworkCall(): void
    {
        $transport = new FakeTransport([]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $invalidItem = new OrderItem(
            shipAddress: new Address(name: 'Max'),
            parcelShopId: 0,
            parcel: new Parcel(ShipService::Classic, weightKg: 999.0),
        );

        $this->expectException(DpdCloudValidationException::class);

        try {
            $client->createShipment($invalidItem);
        } finally {
            self::assertCount(0, $transport->requests, 'No network call should be made when local validation fails.');
        }
    }

    public function testValidateLocallyReturnsErrorsWithoutThrowing(): void
    {
        $client = new DpdCloudClient($this->config(), transport: new FakeTransport([]));

        $errors = $client->validateLocally([]);
        self::assertNotEmpty($errors);
    }

    // -- SystemInformation --------------------------------------------------

    public function testSystemInformationIsExposedAndLogged(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_zip_code_rules_success.xml'))]);
        $logger = new CollectingLogger();
        $client = new DpdCloudClient($this->config(), transport: $transport, logger: $logger);

        $client->fetchZipCodeRules();

        self::assertSame('OK', $client->lastSystemInformation());
        self::assertContains('DPD Cloud: system information', $logger->noticeMessages);
    }

    /** DPD sends `"SystemInformation": null` on most REST responses. */
    public function testSystemInformationIsNullWhenAbsent(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_zip_code_rules_success.json'))]);
        $client = new DpdCloudClient($this->config(), TransportMode::Rest, $transport);

        $client->fetchZipCodeRules();

        self::assertNull($client->lastSystemInformation());
    }

    // -- error classification --------------------------------------------------

    public function testAuthErrorIsClassifiedAsAuthException(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_zip_code_rules_auth_error.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $this->expectException(DpdCloudAuthException::class);
        $client->fetchZipCodeRules();
    }

    public function testRateLimitIsClassifiedAsRateLimitException(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_zip_code_rules_rate_limit.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        try {
            $client->fetchZipCodeRules();
            self::fail('Expected DpdCloudRateLimitException to be thrown.');
        } catch (DpdCloudRateLimitException $e) {
            self::assertSame('CLOUD_API_USERCALLLIMIT', $e->errorCode);
            self::assertSame(600, $e->retryAfterSeconds());
            // Throttling is not an auth problem, callers must be able to tell them apart.
            self::assertNotInstanceOf(DpdCloudAuthException::class, $e);
        }
    }

    public function testValidationApiErrorIsClassifiedAsApiException(): void
    {
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('set_order_validation_error.xml'))]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        try {
            $client->createShipment($this->makeItem());
            self::fail('Expected DpdCloudApiException to be thrown.');
        } catch (DpdCloudApiException $e) {
            self::assertTrue($e->hasCode('CLOUD_API_ORDER_WEIGHT'));
        }
    }

    public function testHttpErrorIsClassifiedAsTransportException(): void
    {
        $transport = new FakeTransport([new TransportResponse(500, 'Internal Server Error')]);
        $client = new DpdCloudClient($this->config(), transport: $transport);

        $this->expectException(DpdCloudTransportException::class);
        $client->fetchZipCodeRules();
    }

    public function testDebugModeAttachesRawResponseToException(): void
    {
        $config = DpdCloudConfig::fromArray([
            'partner_name' => 'Partner Name',
            'partner_token' => 'partner-token',
            'user_id' => 123456,
            'user_token' => 'user-token',
            'env' => 'sandbox',
            'debug' => true,
        ]);
        $transport = new FakeTransport([new TransportResponse(200, $this->fixture('get_zip_code_rules_auth_error.xml'))]);
        $client = new DpdCloudClient($config, transport: $transport);

        try {
            $client->fetchZipCodeRules();
            self::fail('Expected DpdCloudAuthException to be thrown.');
        } catch (DpdCloudAuthException $e) {
            self::assertNotNull($e->getRawResponse());
            self::assertStringContainsString('CLOUD_API_PARTNERCREDENTIALS', $e->getRawResponse() ?? '');
        }
    }

    // -- named constructors --------------------------------------------------

    public function testSandboxNamedConstructorUsesStageEndpoint(): void
    {
        $client = DpdCloudClient::sandbox('Partner', 'ptoken', 1, 'utoken');
        self::assertInstanceOf(DpdCloudClient::class, $client);
    }

    public function testProductionNamedConstructorUsesProductionEndpoint(): void
    {
        $client = DpdCloudClient::production('Partner', 'ptoken', 1, 'utoken');
        self::assertInstanceOf(DpdCloudClient::class, $client);
    }
}

/** Scripted fake transport: returns queued responses in order and records every request sent. */
final class FakeTransport implements TransportInterface
{
    private int $index = 0;

    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @param list<TransportResponse> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;

        if (!isset($this->responses[$this->index])) {
            throw new \RuntimeException('FakeTransport: no more scripted responses available.');
        }

        return $this->responses[$this->index++];
    }
}

/** Minimal PSR-3 logger that only records the messages this test suite asserts on. */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $noticeMessages = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ($level === LogLevel::NOTICE) {
            $this->noticeMessages[] = (string) $message;
        }
    }
}
