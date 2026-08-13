<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit\Internal\Rest;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\Cod;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\OrderSettings;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SearchAddress;
use VeryCodeCom\DpdDe\Dto\SearchGeoData;
use VeryCodeCom\DpdDe\DpdCloudConfig;
use VeryCodeCom\DpdDe\Enum\LabelSize;
use VeryCodeCom\DpdDe\Enum\LabelStartPosition;
use VeryCodeCom\DpdDe\Enum\OrderAction;
use VeryCodeCom\DpdDe\Enum\PaymentType;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Internal\Rest\RestRequestBuilder;

final class RestRequestBuilderTest extends TestCase
{
    private RestRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new RestRequestBuilder(
            DpdCloudConfig::sandbox('Partner Name', 'partner-token', 123456, 'user-token')
        );
    }

    private function makeAddress(): Address
    {
        return new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'de',
            zipCode: '12345',
            city: 'Berlin',
        );
    }

    private function makeItem(): OrderItem
    {
        return new OrderItem(
            shipAddress: $this->makeAddress(),
            parcelShopId: 0,
            parcel: new Parcel(ShipService::Classic, weightKg: 2.5, content: 'Books', yourInternalId: 'ORDER-1'),
        );
    }

    // -- credential headers (present on every request) --------------------------------------------------

    public function testCredentialHeadersPresentOnGet(): void
    {
        $req = $this->builder->buildGetZipCodeRules();

        self::assertSame((string) DpdCloudConfig::API_VERSION, $req->headers['Version']);
        self::assertSame('de_DE', $req->headers['Language']);
        self::assertSame('Partner Name', $req->headers['PartnerCredentials-Name']);
        self::assertSame('partner-token', $req->headers['PartnerCredentials-Token']);
        self::assertSame('123456', $req->headers['UserCredentials-cloudUserID']);
        self::assertSame('user-token', $req->headers['UserCredentials-Token']);
    }

    /** DPD rejects dotted credential header names with ErrorID 2000 / CLOUD_API_PARTNERCREDENTIALS. */
    public function testCredentialHeadersUseHyphensNotDots(): void
    {
        $req = $this->builder->buildGetZipCodeRules();

        foreach (array_keys($req->headers) as $name) {
            self::assertStringNotContainsString('.', $name, "Header '{$name}' must not contain a dot.");
        }
    }

    public function testGetZipCodeRulesUsesGetMethod(): void
    {
        $req = $this->builder->buildGetZipCodeRules();

        self::assertSame('GET', $req->method);
        self::assertSame(DpdCloudConfig::REST_BASE_SANDBOX . '/ZipCodeRules', $req->url);
    }

    // -- setOrder --------------------------------------------------

    public function testSetOrderUsesPostMethodWithJsonContentType(): void
    {
        $req = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);

        self::assertSame('POST', $req->method);
        self::assertStringEndsWith('/setOrder', $req->url);
        self::assertSame('application/json; charset=utf-8', $req->headers['Content-Type']);
    }

    public function testSetOrderBodyStructure(): void
    {
        $req = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);
        self::assertIsString($req->body);
        $body = json_decode($req->body, true);

        self::assertSame('startOrder', $body['OrderAction']);
        self::assertCount(1, $body['OrderDataList']);

        $item = $body['OrderDataList'][0];
        self::assertSame('Max Mustermann', $item['ShipAddress']['Name']);
        self::assertSame('DE', $item['ShipAddress']['Country']);
        self::assertSame(0, $item['ParcelShopID']);
        self::assertSame('Classic', $item['ParcelData']['ShipService']);
        self::assertSame(2.5, $item['ParcelData']['Weight']);
        self::assertSame('Books', $item['ParcelData']['Content']);
        self::assertSame('ORDER-1', $item['ParcelData']['YourInternalID']);
    }

    public function testSetOrderBodyIncludesOrderSettings(): void
    {
        $settings = new OrderSettings(new \DateTimeImmutable('2025-06-01T09:00:00'), LabelSize::PdfA6, LabelStartPosition::LowerRight);
        $req = $this->builder->buildSetOrder([$this->makeItem()], $settings, OrderAction::StartOrder);
        $body = json_decode($req->body ?? '', true);

        self::assertSame('2025-06-01T09:00:00', $body['OrderSettings']['ShipDate']);
        self::assertSame('PDF_A6', $body['OrderSettings']['LabelSize']);
        self::assertSame('LowerRight', $body['OrderSettings']['LabelStartPosition']);
    }

    public function testSetOrderBodyIncludesPudoIdWhenProvided(): void
    {
        $item = new OrderItem(
            shipAddress: $this->makeAddress(),
            parcelShopId: 42,
            parcel: new Parcel(ShipService::Classic, weightKg: 1.0),
            pudoId: 'PUDO-123',
        );

        $req = $this->builder->buildSetOrder([$item], OrderSettings::default(), OrderAction::StartOrder);
        $body = json_decode($req->body ?? '', true);

        self::assertSame('PUDO-123', $body['OrderDataList'][0]['PudoID']);
    }

    public function testSetOrderBodyOmitsPudoIdWhenNotProvided(): void
    {
        $req = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);
        $body = json_decode($req->body ?? '', true);

        self::assertArrayNotHasKey('PudoID', $body['OrderDataList'][0]);
    }

    public function testSetOrderBodyIncludesCodWhenPresent(): void
    {
        $item = new OrderItem(
            shipAddress: $this->makeAddress(),
            parcelShopId: 0,
            parcel: new Parcel(
                ShipService::ClassicCod,
                weightKg: 1.0,
                cod: new Cod(amount: 49.99, payment: PaymentType::Cash, purpose: 'Invoice #1'),
            ),
        );

        $req = $this->builder->buildSetOrder([$item], OrderSettings::default(), OrderAction::StartOrder);
        $body = json_decode($req->body ?? '', true);

        self::assertSame(49.99, $body['OrderDataList'][0]['ParcelData']['COD']['Amount']);
        self::assertSame('Cash', $body['OrderDataList'][0]['ParcelData']['COD']['Payment']);
        self::assertSame('Invoice #1', $body['OrderDataList'][0]['ParcelData']['COD']['Purpose']);
    }

    // -- getParcelShopFinder --------------------------------------------------

    public function testParcelShopFinderByAddressBuildsPathSegments(): void
    {
        $query = ParcelShopQuery::byAddress(
            new SearchAddress(street: 'Luitpoldstr', houseNo: '3', zipCode: '97318', city: 'Kitzingen', country: 'DEU'),
            maxReturnValues: 2,
        );
        $req = $this->builder->buildGetParcelShopFinder($query);

        self::assertSame('GET', $req->method);
        self::assertSame(
            DpdCloudConfig::REST_BASE_SANDBOX . '/ParcelShopFinder/2/Luitpoldstr/3/97318/Kitzingen/DEU/StandardService/null',
            $req->url,
        );
    }

    /** DPD's router returns HTTP 404 when a "." sits directly before a "/". */
    public function testParcelShopFinderStripsTrailingDotFromSegment(): void
    {
        $query = ParcelShopQuery::byAddress(new SearchAddress(street: 'Musterstr.', houseNo: '1', zipCode: '12345', city: 'Berlin', country: 'DE'));
        $req = $this->builder->buildGetParcelShopFinder($query);

        self::assertStringContainsString('/Musterstr/1/', $req->url);
        self::assertStringNotContainsString('./', substr($req->url, strlen(DpdCloudConfig::REST_BASE_SANDBOX)));
    }

    public function testParcelShopFinderPassesEmptySegmentsAsNullLiteral(): void
    {
        $query = ParcelShopQuery::byAddress(new SearchAddress(zipCode: '12345', city: 'Berlin', country: 'DE'));
        $req = $this->builder->buildGetParcelShopFinder($query);

        self::assertStringContainsString('/ParcelShopFinder/10/null/null/12345/Berlin/DE/', $req->url);
    }

    public function testParcelShopFinderUrlEncodesSegments(): void
    {
        $query = ParcelShopQuery::byAddress(new SearchAddress(street: 'Unter den Linden', houseNo: '1', zipCode: '10117', city: 'Berlin', country: 'DE'));
        $req = $this->builder->buildGetParcelShopFinder($query);

        self::assertStringContainsString('/Unter%20den%20Linden/', $req->url);
    }

    public function testParcelShopFinderByGeoDataBuildsShorterPath(): void
    {
        $query = ParcelShopQuery::byGeoData(new SearchGeoData(longitude: 13.4, latitude: 52.52), maxReturnValues: 2);
        $req = $this->builder->buildGetParcelShopFinder($query);

        self::assertSame(
            DpdCloudConfig::REST_BASE_SANDBOX . '/ParcelShopFinder/2/13.4/52.52/StandardService/null',
            $req->url,
        );
    }

    // -- getParcelLifeCycle / getOrderStatus --------------------------------------------------

    public function testGetParcelLifeCycleBuildsUrlWithParcelNo(): void
    {
        $req = $this->builder->buildGetParcelLifeCycle('01234567890123');

        self::assertSame(DpdCloudConfig::REST_BASE_SANDBOX . '/ParcelLifeCycle/01234567890123', $req->url);
    }

    public function testGetOrderStatusBuildsUrlWithParcelNo(): void
    {
        $req = $this->builder->buildGetOrderStatus('01234567890123', '12345');

        self::assertSame(DpdCloudConfig::REST_BASE_SANDBOX . '/getOrderStatus/01234567890123/12345', $req->url);
    }

    /** The zip segment is structurally required, omitting it entirely yields HTTP 404. */
    public function testGetOrderStatusSendsNullLiteralWhenZipCodeMissing(): void
    {
        $req = $this->builder->buildGetOrderStatus('01234567890123');

        self::assertSame(DpdCloudConfig::REST_BASE_SANDBOX . '/getOrderStatus/01234567890123/null', $req->url);
    }
}
