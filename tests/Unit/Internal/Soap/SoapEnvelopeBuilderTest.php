<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit\Internal\Soap;

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
use VeryCodeCom\DpdDe\Internal\Soap\SoapEnvelopeBuilder;

/**
 * Verifies the XML structure of all SOAP envelopes produced by SoapEnvelopeBuilder.
 *
 * Critical invariants tested:
 * - document/literal envelope shape (no xsi:type attributes, unlike suus-php's RPC/encoded style)
 * - default namespace https://cloud.dpd.com/ declared on the operation wrapper element
 * - Version/Language/PartnerCredentials/UserCredentials present on every request
 * - setOrder: OrderDataList with one OrderData per item, PudoID only when set
 * - ParcelShopFinder: SearchAddress vs SearchGeoData mutually exclusive branches
 */
final class SoapEnvelopeBuilderTest extends TestCase
{
    private SoapEnvelopeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SoapEnvelopeBuilder(
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

    // ----------------------------------------------
    // Envelope / namespace structure
    // ----------------------------------------------

    public function testEnvelopeHasSoapNamespace(): void
    {
        $xml = $this->builder->buildGetZipCodeRules();
        $this->assertStringContainsString('http://schemas.xmlsoap.org/soap/envelope/', $xml);
    }

    public function testEnvelopeHasDpdDefaultNamespace(): void
    {
        $xml = $this->builder->buildGetZipCodeRules();
        $this->assertStringContainsString('xmlns="https://cloud.dpd.com/"', $xml);
    }

    public function testEnvelopeDoesNotUseXsiTypeAttributes(): void
    {
        // Document/literal style: no xsi:type attributes anywhere (unlike suus-php's RPC/encoded style).
        $xml = $this->builder->buildGetZipCodeRules();
        $this->assertStringNotContainsString('xsi:type', $xml);
    }

    // ----------------------------------------------
    // Credentials block (present in every request)
    // ----------------------------------------------

    public function testCredentialsContainPartnerName(): void
    {
        $xml = $this->builder->buildGetZipCodeRules();
        $this->assertStringContainsString('<Name>Partner Name</Name>', $xml);
    }

    public function testCredentialsContainPartnerToken(): void
    {
        $xml = $this->builder->buildGetZipCodeRules();
        $this->assertStringContainsString('<Token>partner-token</Token>', $xml);
    }

    public function testCredentialsContainUserId(): void
    {
        $xml = $this->builder->buildGetZipCodeRules();
        $this->assertStringContainsString('<cloudUserID>123456</cloudUserID>', $xml);
    }

    public function testCredentialsContainVersionAndLanguage(): void
    {
        $xml = $this->builder->buildGetZipCodeRules();
        $this->assertStringContainsString('<Version>' . DpdCloudConfig::API_VERSION . '</Version>', $xml);
        $this->assertStringContainsString('<Language>de_DE</Language>', $xml);
    }

    // ----------------------------------------------
    // setOrder
    // ----------------------------------------------

    public function testSetOrderContainsOrderAction(): void
    {
        $xml = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);
        $this->assertStringContainsString('<OrderAction>startOrder</OrderAction>', $xml);
    }

    public function testSetOrderContainsCheckOrderDataAction(): void
    {
        $xml = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::CheckOrderData);
        $this->assertStringContainsString('<OrderAction>checkOrderData</OrderAction>', $xml);
    }

    public function testSetOrderContainsShipAddressFields(): void
    {
        $xml = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);
        $this->assertStringContainsString('<Name>Max Mustermann</Name>', $xml);
        $this->assertStringContainsString('<Street>Musterstr.</Street>', $xml);
        $this->assertStringContainsString('<HouseNo>1</HouseNo>', $xml);
        $this->assertStringContainsString('<ZipCode>12345</ZipCode>', $xml);
        $this->assertStringContainsString('<City>Berlin</City>', $xml);
    }

    public function testSetOrderUppercasesCountryCode(): void
    {
        $xml = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);
        $this->assertStringContainsString('<Country>DE</Country>', $xml);
    }

    public function testSetOrderContainsShipServiceAndWeight(): void
    {
        $xml = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);
        $this->assertStringContainsString('<ShipService>Classic</ShipService>', $xml);
        $this->assertStringContainsString('<Weight>2.5</Weight>', $xml);
    }

    public function testSetOrderContainsPudoIdWhenProvided(): void
    {
        $item = new OrderItem(
            shipAddress: $this->makeAddress(),
            parcelShopId: 42,
            parcel: new Parcel(ShipService::Classic, weightKg: 1.0),
            pudoId: 'PUDO-123',
        );

        $xml = $this->builder->buildSetOrder([$item], OrderSettings::default(), OrderAction::StartOrder);
        $this->assertStringContainsString('<PudoID>PUDO-123</PudoID>', $xml);
    }

    public function testSetOrderOmitsPudoIdWhenNotProvided(): void
    {
        $xml = $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder);
        $this->assertStringNotContainsString('<PudoID>', $xml);
    }

    public function testSetOrderContainsOneOrderDataPerItem(): void
    {
        $items = [$this->makeItem(), $this->makeItem(), $this->makeItem()];
        $xml = $this->builder->buildSetOrder($items, OrderSettings::default(), OrderAction::StartOrder);
        $this->assertSame(3, substr_count($xml, '<OrderData>'));
    }

    public function testSetOrderContainsOrderSettings(): void
    {
        $settings = new OrderSettings(new \DateTimeImmutable('2025-06-01T09:00:00'), LabelSize::PdfA6, LabelStartPosition::LowerRight);
        $xml = $this->builder->buildSetOrder([$this->makeItem()], $settings, OrderAction::StartOrder);

        $this->assertStringContainsString('<ShipDate>2025-06-01T09:00:00</ShipDate>', $xml);
        $this->assertStringContainsString('<LabelSize>PDF_A6</LabelSize>', $xml);
        $this->assertStringContainsString('<LabelStartPosition>LowerRight</LabelStartPosition>', $xml);
    }

    public function testSetOrderContainsCodBlockWhenPresent(): void
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

        $xml = $this->builder->buildSetOrder([$item], OrderSettings::default(), OrderAction::StartOrder);

        $this->assertStringContainsString('<COD>', $xml);
        $this->assertStringContainsString('<Amount>49.99</Amount>', $xml);
        $this->assertStringContainsString('<Payment>Cash</Payment>', $xml);
        $this->assertStringContainsString('<Purpose>Invoice #1</Purpose>', $xml);
    }

    // ----------------------------------------------
    // getParcelShopFinder
    // ----------------------------------------------

    public function testParcelShopFinderByAddress(): void
    {
        $query = ParcelShopQuery::byAddress(new SearchAddress(street: 'Hauptstr.', zipCode: '12345', city: 'Berlin', country: 'DE'));
        $xml = $this->builder->buildGetParcelShopFinder($query);

        $this->assertStringContainsString('<SearchMode>SearchByAddress</SearchMode>', $xml);
        $this->assertStringContainsString('<SearchAddress>', $xml);
        $this->assertStringContainsString('<ZipCode>12345</ZipCode>', $xml);
        $this->assertStringNotContainsString('<SearchGeoData>', $xml);
    }

    public function testParcelShopFinderByGeoData(): void
    {
        $query = ParcelShopQuery::byGeoData(new SearchGeoData(longitude: 13.4, latitude: 52.52));
        $xml = $this->builder->buildGetParcelShopFinder($query);

        $this->assertStringContainsString('<SearchMode>SearchByGeoData</SearchMode>', $xml);
        $this->assertStringContainsString('<SearchGeoData>', $xml);
        $this->assertStringContainsString('<Longitude>13.4</Longitude>', $xml);
        $this->assertStringNotContainsString('<SearchAddress>', $xml);
    }

    // ----------------------------------------------
    // getParcelLifeCycle / getOrderStatus
    // ----------------------------------------------

    public function testGetParcelLifeCycleContainsParcelNo(): void
    {
        $xml = $this->builder->buildGetParcelLifeCycle('01234567890123');
        $this->assertStringContainsString('<ParcelNo>01234567890123</ParcelNo>', $xml);
    }

    public function testGetOrderStatusContainsParcelNo(): void
    {
        $xml = $this->builder->buildGetOrderStatus('01234567890123');
        $this->assertStringContainsString('<ParcelNo>01234567890123</ParcelNo>', $xml);
    }

    public function testGetOrderStatusContainsDeliveryZipCodeWhenProvided(): void
    {
        $xml = $this->builder->buildGetOrderStatus('01234567890123', '12345');
        $this->assertStringContainsString('<DeliveryZipCode>12345</DeliveryZipCode>', $xml);
    }

    public function testGetOrderStatusOmitsDeliveryZipCodeWhenNotProvided(): void
    {
        $xml = $this->builder->buildGetOrderStatus('01234567890123');
        $this->assertStringNotContainsString('<DeliveryZipCode>', $xml);
    }

    // ----------------------------------------------
    // Well-formedness
    // ----------------------------------------------

    public function testAllBuildersProduceWellFormedXml(): void
    {
        $xmls = [
            $this->builder->buildGetZipCodeRules(),
            $this->builder->buildSetOrder([$this->makeItem()], OrderSettings::default(), OrderAction::StartOrder),
            $this->builder->buildGetParcelShopFinder(ParcelShopQuery::byAddress(new SearchAddress(zipCode: '12345'))),
            $this->builder->buildGetParcelLifeCycle('123'),
            $this->builder->buildGetOrderStatus('123'),
        ];

        foreach ($xmls as $xml) {
            $dom = new \DOMDocument();
            $this->assertTrue($dom->loadXML($xml), 'Expected well-formed XML: ' . $xml);
        }
    }
}
