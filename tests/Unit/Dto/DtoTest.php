<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\ContentItem;
use VeryCodeCom\DpdDe\Dto\ContentLine;
use VeryCodeCom\DpdDe\Dto\ErrorData;
use VeryCodeCom\DpdDe\Dto\GeoData;
use VeryCodeCom\DpdDe\Dto\OrderResult;
use VeryCodeCom\DpdDe\Dto\OrderSettings;
use VeryCodeCom\DpdDe\Dto\ParcelShop;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SearchAddress;
use VeryCodeCom\DpdDe\Dto\SearchGeoData;
use VeryCodeCom\DpdDe\Dto\SetOrderResult;
use VeryCodeCom\DpdDe\Dto\ZipCodeRules;
use VeryCodeCom\DpdDe\Enum\LabelSize;
use VeryCodeCom\DpdDe\Enum\LabelStartPosition;
use VeryCodeCom\DpdDe\Enum\NeedService;
use VeryCodeCom\DpdDe\Enum\ParcelStationType;
use VeryCodeCom\DpdDe\Enum\SearchMode;

/** Covers the DTOs that carry behaviour rather than plain data. */
final class DtoTest extends TestCase
{
    // -- Address --------------------------------------------------

    public function testGetCountryCodeUppercases(): void
    {
        self::assertSame('DE', (new Address(country: 'de'))->getCountryCode());
        self::assertSame('DEU', (new Address(country: 'deu'))->getCountryCode());
    }

    public function testGetCountryCodeIsNullWhenCountryMissing(): void
    {
        self::assertNull((new Address())->getCountryCode());
    }

    // -- SetOrderResult --------------------------------------------------

    public function testFirstParcelNoReturnsFirstItem(): void
    {
        $result = new SetOrderResult('%PDF-fake', [
            new OrderResult('ORDER-1', '01234567890123'),
            new OrderResult('ORDER-2', '01234567890124'),
        ]);

        self::assertSame('01234567890123', $result->firstParcelNo());
    }

    public function testFirstParcelNoIsNullWithoutItems(): void
    {
        self::assertNull((new SetOrderResult('%PDF-fake', []))->firstParcelNo());
    }

    // -- ParcelShop --------------------------------------------------

    /** @return iterable<string, array{ParcelStationType, bool}> */
    public static function parcelStationProvider(): iterable
    {
        yield 'shop' => [ParcelStationType::None, false];
        yield 'locker' => [ParcelStationType::Standard, true];
        yield 'myflexbox' => [ParcelStationType::Myflexbox, true];
    }

    #[DataProvider('parcelStationProvider')]
    public function testIsParcelLocker(ParcelStationType $type, bool $expected): void
    {
        self::assertSame($expected, $this->makeShop($type)->isParcelLocker());
    }

    private function makeShop(ParcelStationType $type): ParcelShop
    {
        return new ParcelShop(
            parcelShopId: 260538,
            shopAddress: new Address(company: 'NKD Deutschland GmbH', city: 'Kitzingen'),
            homepage: null,
            geoData: new GeoData(0.0, 10.1, 49.7, 0.0, 0.0, 0.0),
            expressCutOff: null,
            shopServiceList: [],
            openingHoursList: [],
            holidayList: [],
            extraInfo: null,
            serviceDetail: null,
            customerNo: null,
            parcelStation: $type,
        );
    }

    // -- ZipCodeRules --------------------------------------------------

    public function testNoPickupDaysListParsesGermanDates(): void
    {
        $rules = $this->makeRules('25.12.2025,26.12.2025,01.01.2026');
        $dates = $rules->getNoPickupDaysList();

        self::assertCount(3, $dates);
        self::assertSame('2025-12-25', $dates[0]->format('Y-m-d'));
        self::assertSame('2026-01-01', $dates[2]->format('Y-m-d'));
    }

    public function testNoPickupDaysListParsesIsoDates(): void
    {
        $dates = $this->makeRules('2025-12-25,2026-01-01')->getNoPickupDaysList();

        self::assertCount(2, $dates);
        self::assertSame('2025-12-25', $dates[0]->format('Y-m-d'));
    }

    public function testNoPickupDaysListSkipsUnparsableAndEmptyEntries(): void
    {
        $dates = $this->makeRules('25.12.2025,,not-a-date, 26.12.2025 ')->getNoPickupDaysList();

        self::assertCount(2, $dates);
    }

    /** @return iterable<string, array{?string}> */
    public static function emptyNoPickupDaysProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('emptyNoPickupDaysProvider')]
    public function testNoPickupDaysListIsEmptyWithoutData(?string $raw): void
    {
        self::assertSame([], $this->makeRules($raw)->getNoPickupDaysList());
    }

    private function makeRules(?string $noPickupDays): ZipCodeRules
    {
        return new ZipCodeRules(
            country: 'DEU',
            zipCode: '63110',
            noPickupDays: $noPickupDays,
            expressCutOff: '14:00',
            classicCutOff: '06:30',
            pickupDepot: '0163',
            state: 'HE',
        );
    }

    // -- ContentItem --------------------------------------------------

    public function testGetTextJoinsContentLines(): void
    {
        $item = new ContentItem(
            label: new ContentLine('Status', true, false),
            content: [new ContentLine('Zugestellt', false, false), new ContentLine('13.08.2026', false, true)],
            linkTarget: null,
        );

        self::assertSame("Zugestellt\n13.08.2026", $item->getText());
    }

    public function testGetTextIsEmptyWithoutContent(): void
    {
        self::assertSame('', (new ContentItem(null, [], null))->getText());
    }

    // -- ErrorData --------------------------------------------------

    public function testErrorDataToArray(): void
    {
        $error = new ErrorData(2125, 'CLOUD_API_ORDER_CONTENT', 'Paketinhalt: 1-35 Zeichen.', 'Der Paketinhalt muss zwischen 1 und 35 Zeichen lang sein.');

        self::assertSame([
            'errorId' => 2125,
            'errorCode' => 'CLOUD_API_ORDER_CONTENT',
            'messageShort' => 'Paketinhalt: 1-35 Zeichen.',
            'messageLong' => 'Der Paketinhalt muss zwischen 1 und 35 Zeichen lang sein.',
        ], $error->toArray());
    }

    // -- OrderSettings --------------------------------------------------

    public function testOrderSettingsDefaults(): void
    {
        $settings = OrderSettings::default();

        self::assertSame(LabelSize::PdfA4, $settings->labelSize);
        self::assertSame(LabelStartPosition::UpperLeft, $settings->labelStartPosition);
        self::assertEqualsWithDelta(time(), $settings->shipDate->getTimestamp(), 5);
    }

    // -- ParcelShopQuery --------------------------------------------------

    public function testByAddressFactorySetsSearchMode(): void
    {
        $query = ParcelShopQuery::byAddress(new SearchAddress(zipCode: '12345'), maxReturnValues: 5, needService: NeedService::ReturnService);

        self::assertSame(SearchMode::SearchByAddress, $query->searchMode);
        self::assertSame(5, $query->maxReturnValues);
        self::assertSame(NeedService::ReturnService, $query->needService);
        self::assertNull($query->searchGeoData);
    }

    public function testByGeoDataFactorySetsSearchMode(): void
    {
        $query = ParcelShopQuery::byGeoData(new SearchGeoData(longitude: 13.4, latitude: 52.52));

        self::assertSame(SearchMode::SearchByGeoData, $query->searchMode);
        self::assertNull($query->searchAddress);
        self::assertSame(13.4, $query->searchGeoData?->longitude);
    }

    public function testSearchModeWithoutMatchingCriteriaThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('searchAddress is required');

        new ParcelShopQuery(SearchMode::SearchByAddress);
    }

    public function testGeoSearchModeWithoutGeoDataThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('searchGeoData is required');

        new ParcelShopQuery(SearchMode::SearchByGeoData);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidMaxReturnValuesProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'above DPD limit' => [101];
    }

    #[DataProvider('invalidMaxReturnValuesProvider')]
    public function testMaxReturnValuesOutOfRangeThrows(int $maxReturnValues): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxReturnValues must be between 0 and 100');

        ParcelShopQuery::byAddress(new SearchAddress(zipCode: '12345'), maxReturnValues: $maxReturnValues);
    }

    public function testMaxReturnValuesAtLimitIsAccepted(): void
    {
        $query = ParcelShopQuery::byAddress(new SearchAddress(zipCode: '12345'), maxReturnValues: ParcelShopQuery::MAX_RETURN_VALUES);

        self::assertSame(100, $query->maxReturnValues);
    }
}
