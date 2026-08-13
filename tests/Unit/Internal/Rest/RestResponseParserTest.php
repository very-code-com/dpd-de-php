<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit\Internal\Rest;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\Enum\ParcelStationType;
use VeryCodeCom\DpdDe\Enum\ShopService;
use VeryCodeCom\DpdDe\Internal\Rest\RestResponseParser;

final class RestResponseParserTest extends TestCase
{
    private RestResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RestResponseParser();
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/' . $name);
        self::assertIsString($contents, "Fixture {$name} could not be read.");
        return $contents;
    }

    // -- decode / isSuccess / errorDataList --------------------------------------------------

    public function testDecodeThrowsOnInvalidJson(): void
    {
        $this->expectException(\VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException::class);
        $this->parser->decode('{not valid json');
    }

    public function testDecodeThrowsWhenNotAnObject(): void
    {
        $this->expectException(\VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException::class);
        $this->parser->decode('"just a string"');
    }

    public function testIsSuccessTrue(): void
    {
        $data = $this->parser->decode($this->fixture('get_zip_code_rules_success.json'));
        self::assertTrue($this->parser->isSuccess($data));
    }

    public function testIsSuccessFalseWhenAckMissing(): void
    {
        self::assertFalse($this->parser->isSuccess([]));
    }

    public function testErrorDataListParsesErrors(): void
    {
        $json = json_encode([
            'Ack' => false,
            'ErrorDataList' => [
                ['ErrorID' => 2121, 'ErrorCode' => 'CLOUD_API_ORDER_WEIGHT', 'ErrorMsgShort' => 'Invalid weight', 'ErrorMsgLong' => 'Gewicht: 0 bis 31,5 Kg.'],
            ],
        ]);
        self::assertIsString($json);

        $data = $this->parser->decode($json);
        $errors = $this->parser->errorDataList($data);

        self::assertCount(1, $errors);
        self::assertSame(2121, $errors[0]->errorId);
        self::assertSame('CLOUD_API_ORDER_WEIGHT', $errors[0]->errorCode);
    }

    public function testErrorDataListEmptyWhenMissing(): void
    {
        self::assertSame([], $this->parser->errorDataList([]));
    }

    // -- zipCodeRules --------------------------------------------------

    public function testZipCodeRules(): void
    {
        $data = $this->parser->decode($this->fixture('get_zip_code_rules_success.json'));
        $rules = $this->parser->zipCodeRules($data);

        self::assertSame('DE', $rules->country);
        self::assertSame('12345', $rules->zipCode);
        self::assertSame('16:00', $rules->expressCutOff);
        self::assertSame('17:00', $rules->classicCutOff);
        self::assertSame('0123', $rules->pickupDepot);
        self::assertSame('BE', $rules->state);
    }

    // -- setOrderResult --------------------------------------------------

    public function testSetOrderResultDecodesLabelPdf(): void
    {
        $data = $this->parser->decode($this->fixture('set_order_success.json'));
        $result = $this->parser->setOrderResult($data);

        self::assertSame('%PDF-1.4-fake-pdf-content', $result->labelPdf);
        self::assertCount(1, $result->items);
        self::assertSame('ORDER-1', $result->items[0]->yourInternalId);
        self::assertSame('01234567890123', $result->items[0]->parcelNo);
    }

    // -- parcelShops --------------------------------------------------

    public function testParcelShops(): void
    {
        $data = $this->parser->decode($this->fixture('get_parcel_shop_finder_success.json'));
        $shops = $this->parser->parcelShops($data);

        self::assertCount(1, $shops);
        $shop = $shops[0];

        self::assertSame(987654, $shop->parcelShopId);
        self::assertNotNull($shop->shopAddress);
        self::assertSame('Kiosk Mustermann', $shop->shopAddress->company);
        self::assertSame('Berlin', $shop->shopAddress->city);

        self::assertNotNull($shop->geoData);
        self::assertEqualsWithDelta(0.42, $shop->geoData->distance, 0.0001);

        self::assertCount(2, $shop->shopServiceList);
        self::assertSame(ShopService::SearchAll, $shop->shopServiceList[0]);
        self::assertSame(ShopService::Paketstation, $shop->shopServiceList[1]);

        self::assertCount(1, $shop->openingHoursList);
        self::assertSame('Monday', $shop->openingHoursList[0]->weekDay);

        self::assertCount(1, $shop->holidayList);
        self::assertSame('2024-12-24', $shop->holidayList[0]->holidayFrom->format('Y-m-d'));

        self::assertSame(ParcelStationType::Standard, $shop->parcelStation);
        self::assertTrue($shop->isParcelLocker());
    }

    public function testParcelShopsEmptyWhenListMissing(): void
    {
        self::assertSame([], $this->parser->parcelShops([]));
    }

    // -- orderStatus --------------------------------------------------

    public function testOrderStatus(): void
    {
        $data = $this->parser->decode($this->fixture('get_order_status_success.json'));
        $status = $this->parser->orderStatus($data);

        self::assertSame('01234567890123', $status->parcelNo);

        self::assertNotNull($status->orderInformation);
        self::assertSame(101, $status->orderInformation->serviceCode);
        self::assertSame('Classic', $status->orderInformation->productName);
        self::assertTrue($status->orderInformation->completeDelivery);

        self::assertNotNull($status->shipAddress);
        self::assertSame('Max Mustermann', $status->shipAddress->name);

        self::assertNotNull($status->lastStatusInfo);
        self::assertSame('DELIVERED', $status->lastStatusInfo->statusId);

        self::assertNotNull($status->statusInfoContainer);
        self::assertNotNull($status->statusInfoContainer->start);
        self::assertSame('SHIPMENT', $status->statusInfoContainer->start->statusId);
        self::assertNull($status->statusInfoContainer->onTheRoad);
    }
}
