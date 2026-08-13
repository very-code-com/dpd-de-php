<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SearchAddress;
use VeryCodeCom\DpdDe\Dto\SearchGeoData;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\DpdCloudConfig;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Enum\TransportMode;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;

/**
 * Integration tests, make real calls to the DPD Cloud Service sandbox (Testsystem).
 *
 * Skipped unless DPD_CLOUD_SANDBOX=1 and sandbox credentials are provided. The credentials are
 * public test data from the DPD developer portal, see the README section "Where to get sandbox
 * (Testsystem) credentials".
 *
 * Usage:
 *   DPD_CLOUD_SANDBOX=1 \
 *   DPD_CLOUD_PARTNER_NAME="DPD Sandbox" \
 *   DPD_CLOUD_PARTNER_TOKEN=xxx \
 *   DPD_CLOUD_USER_ID=279... \
 *   DPD_CLOUD_USER_TOKEN=xxx \
 *   vendor/bin/phpunit --testsuite integration
 *
 * Never point these at production credentials: two of these tests issue a real label. On the
 * sandbox that is free and consequence-free; on production it would burn a parcel number. Set
 * DPD_CLOUD_ALLOW_LABELS=0 to skip them and run only the read-only and checkOrderData tests.
 */
final class DpdCloudClientIntegrationTest extends TestCase
{
    private DpdCloudConfig $config;

    protected function setUp(): void
    {
        if (getenv('DPD_CLOUD_SANDBOX') !== '1') {
            $this->markTestSkipped('Set DPD_CLOUD_SANDBOX=1 to run integration tests.');
        }

        $partnerName = getenv('DPD_CLOUD_PARTNER_NAME') ?: '';
        $partnerToken = getenv('DPD_CLOUD_PARTNER_TOKEN') ?: '';
        $userId = (int) (getenv('DPD_CLOUD_USER_ID') ?: 0);
        $userToken = getenv('DPD_CLOUD_USER_TOKEN') ?: '';

        if ($partnerName === '' || $partnerToken === '' || $userId <= 0 || $userToken === '') {
            $this->markTestSkipped('Set DPD_CLOUD_PARTNER_NAME/DPD_CLOUD_PARTNER_TOKEN/DPD_CLOUD_USER_ID/DPD_CLOUD_USER_TOKEN env vars to run integration tests.');
        }

        $this->config = DpdCloudConfig::sandbox($partnerName, $partnerToken, $userId, $userToken);
    }

    private function client(TransportMode $mode): DpdCloudClient
    {
        return new DpdCloudClient($this->config, $mode);
    }

    /**
     * Guard for the tests that call setOrder with OrderAction=startOrder: those consume a parcel
     * number from the account's range and produce a real label. Harmless on the sandbox, never
     * acceptable against production, so it takes a deliberate opt-out to disable
     * (DPD_CLOUD_ALLOW_LABELS=0).
     */
    private function skipUnlessLabelsAllowed(): void
    {
        if (getenv('DPD_CLOUD_ALLOW_LABELS') === '0') {
            $this->markTestSkipped('DPD_CLOUD_ALLOW_LABELS=0: skipping tests that issue real labels.');
        }
    }

    /** @return iterable<string, array{TransportMode}> */
    public static function transportProvider(): iterable
    {
        yield 'SOAP' => [TransportMode::Soap];
        yield 'REST' => [TransportMode::Rest];
    }

    private function validItem(string $tag): OrderItem
    {
        return new OrderItem(
            shipAddress: new Address(
                name: 'Max Mustermann',
                street: 'Musterstraße',
                houseNo: '1',
                country: 'DE',
                zipCode: '10115',
                city: 'Berlin',
            ),
            parcelShopId: 0,
            parcel: new Parcel(
                ShipService::Classic,
                weightKg: 2.5,
                content: 'Testware',
                yourInternalId: $tag . '-' . date('YmdHis'),
                reference1: 'INTEGRATION-' . $tag,
            ),
        );
    }

    // -- read-only operations, both transports --------------------------------------------------

    #[DataProvider('transportProvider')]
    public function testFetchZipCodeRulesReturnsAccountPickupRules(TransportMode $mode): void
    {
        $rules = $this->client($mode)->fetchZipCodeRules();

        self::assertNotNull($rules->country);
        self::assertNotNull($rules->pickupDepot);
    }

    #[DataProvider('transportProvider')]
    public function testFindParcelShopsByAddressReturnsResults(TransportMode $mode): void
    {
        $shops = $this->client($mode)->findParcelShops(
            ParcelShopQuery::byAddress(
                new SearchAddress(street: 'Luitpoldstr.', houseNo: '3', zipCode: '97318', city: 'Kitzingen', country: 'DEU'),
                maxReturnValues: 2,
            )
        );

        self::assertNotEmpty($shops);
        self::assertGreaterThan(0, $shops[0]->parcelShopId);
    }

    #[DataProvider('transportProvider')]
    public function testFindParcelShopsByGeoDataReturnsResults(TransportMode $mode): void
    {
        $shops = $this->client($mode)->findParcelShops(
            ParcelShopQuery::byGeoData(new SearchGeoData(longitude: 9.0960013, latitude: 49.9447), maxReturnValues: 2)
        );

        self::assertNotEmpty($shops);
    }

    // -- setOrder --------------------------------------------------

    #[DataProvider('transportProvider')]
    public function testCheckOrderDataValidatesWithoutCreatingRealShipment(TransportMode $mode): void
    {
        // OrderAction::CheckOrderData: DPD validates the payload server-side without consuming a
        // parcel number or generating a label.
        $this->client($mode)->checkOrderData([$this->validItem('CHECK')]);

        // No exception thrown = DPD accepted the order data as valid.
        $this->addToAssertionCount(1);
    }

    #[DataProvider('transportProvider')]
    public function testCreateShipmentIssuesRealLabel(TransportMode $mode): void
    {
        $this->skipUnlessLabelsAllowed();

        $result = $this->client($mode)->createShipment($this->validItem('LABEL'));

        $parcelNo = $result->firstParcelNo();
        self::assertNotNull($parcelNo);
        self::assertMatchesRegularExpression('/^\d{14}$/', $parcelNo);
        self::assertStringStartsWith('%PDF-', $result->labelPdf);
    }

    // -- tracking --------------------------------------------------

    public function testFetchOrderStatusReturnsDataForAFreshlyCreatedParcel(): void
    {
        $this->skipUnlessLabelsAllowed();

        $parcelNo = $this->client(TransportMode::Soap)->createShipment($this->validItem('TRACK'))->firstParcelNo();
        self::assertNotNull($parcelNo);

        $status = $this->client(TransportMode::Soap)->fetchOrderStatus($parcelNo, '10115');

        self::assertSame($parcelNo, $status->parcelNo);
    }

    /** A parcel that has only just been ordered has no Parcel Life Cycle 2.0 data yet. */
    public function testFetchParcelLifeCycleReportsNoDataForUnknownParcel(): void
    {
        $this->expectException(DpdCloudApiException::class);

        $this->client(TransportMode::Soap)->fetchParcelLifeCycle('09980000000000');
    }
}
