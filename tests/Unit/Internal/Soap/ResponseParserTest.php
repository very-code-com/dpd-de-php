<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit\Internal\Soap;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\Enum\ParcelStationType;
use VeryCodeCom\DpdDe\Enum\ShopService;
use VeryCodeCom\DpdDe\Enum\TrackingStatus;
use VeryCodeCom\DpdDe\Internal\Soap\ResponseParser;

/**
 * Exercises ResponseParser against real fixture XML, in particular the getParcelLifeCycle
 * fixture that reflects the no-namespace quirk for ParcelLifeCycleService elements (see
 * ResponseParser's class docblock): shipmentInfo/statusInfo/contactInfo and all their
 * descendants carry no namespace at all, unlike every other DPD Cloud Service response element
 * which lives in the `https://cloud.dpd.com/` (tns) namespace.
 */
final class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ResponseParser();
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/' . $name);
        self::assertIsString($contents, "Fixture {$name} could not be read.");
        return $contents;
    }

    // ----------------------------------------------
    // Common envelope helpers
    // ----------------------------------------------

    public function testIsSuccessTrue(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_zip_code_rules_success.xml'));
        self::assertTrue($this->parser->isSuccess($xpath, 'getZipCodeRulesResult'));
    }

    public function testIsSuccessFalseOnAuthError(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_zip_code_rules_auth_error.xml'));
        self::assertFalse($this->parser->isSuccess($xpath, 'getZipCodeRulesResult'));
    }

    public function testSystemInformation(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_zip_code_rules_success.xml'));
        self::assertSame('OK', $this->parser->systemInformation($xpath, 'getZipCodeRulesResult'));
    }

    public function testErrorDataListParsesAuthError(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_zip_code_rules_auth_error.xml'));
        $errors = $this->parser->errorDataList($xpath, 'getZipCodeRulesResult');

        self::assertCount(1, $errors);
        self::assertSame(1, $errors[0]->errorId);
        self::assertSame('CLOUD_API_PARTNERCREDENTIALS', $errors[0]->errorCode);
        self::assertSame('Invalid partner credentials', $errors[0]->errorMsgShort);
        self::assertSame('Partner Credentials invalid.', $errors[0]->errorMsgLong);
    }

    public function testErrorDataListParsesValidationError(): void
    {
        $xpath = $this->parser->parse($this->fixture('set_order_validation_error.xml'));
        $errors = $this->parser->errorDataList($xpath, 'setOrderResult');

        self::assertCount(1, $errors);
        self::assertSame(2121, $errors[0]->errorId);
        self::assertSame('CLOUD_API_ORDER_WEIGHT', $errors[0]->errorCode);
    }

    public function testParseThrowsOnInvalidXml(): void
    {
        $this->expectException(\VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException::class);
        $this->parser->parse('<not-xml');
    }

    // ----------------------------------------------
    // getZipCodeRules
    // ----------------------------------------------

    public function testZipCodeRules(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_zip_code_rules_success.xml'));
        $rules = $this->parser->zipCodeRules($xpath);

        self::assertSame('DE', $rules->country);
        self::assertSame('12345', $rules->zipCode);
        self::assertSame('2024-01-01,2024-12-25', $rules->noPickupDays);
        self::assertSame('16:00', $rules->expressCutOff);
        self::assertSame('17:00', $rules->classicCutOff);
        self::assertSame('0123', $rules->pickupDepot);
        self::assertSame('BE', $rules->state);
    }

    public function testZipCodeRulesNoPickupDaysList(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_zip_code_rules_success.xml'));
        $rules = $this->parser->zipCodeRules($xpath);
        $dates = $rules->getNoPickupDaysList();

        self::assertCount(2, $dates);
        self::assertSame('2024-01-01', $dates[0]->format('Y-m-d'));
        self::assertSame('2024-12-25', $dates[1]->format('Y-m-d'));
    }

    // ----------------------------------------------
    // setOrder
    // ----------------------------------------------

    public function testSetOrderResultDecodesLabelPdf(): void
    {
        $xpath = $this->parser->parse($this->fixture('set_order_success.xml'));
        $result = $this->parser->setOrderResult($xpath);

        self::assertSame('%PDF-1.4-fake-pdf-content', $result->labelPdf);
        self::assertCount(1, $result->items);
        self::assertSame('ORDER-1', $result->items[0]->yourInternalId);
        self::assertSame('01234567890123', $result->items[0]->parcelNo);
        self::assertSame('01234567890123', $result->firstParcelNo());
    }

    // ----------------------------------------------
    // getParcelShopFinder
    // ----------------------------------------------

    public function testParcelShops(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_parcel_shop_finder_success.xml'));
        $shops = $this->parser->parcelShops($xpath);

        self::assertCount(1, $shops);
        $shop = $shops[0];

        self::assertSame(987654, $shop->parcelShopId);
        self::assertNotNull($shop->shopAddress);
        self::assertSame('Kiosk Mustermann', $shop->shopAddress->company);
        self::assertSame('Hauptstr.', $shop->shopAddress->street);
        self::assertSame('5', $shop->shopAddress->houseNo);
        self::assertSame('DE', $shop->shopAddress->country);
        self::assertSame('12345', $shop->shopAddress->zipCode);
        self::assertSame('Berlin', $shop->shopAddress->city);
        self::assertSame('https://example.com', $shop->homepage);

        self::assertNotNull($shop->geoData);
        self::assertEqualsWithDelta(0.42, $shop->geoData->distance, 0.0001);
        self::assertEqualsWithDelta(13.404954, $shop->geoData->longitude, 0.0001);
        self::assertEqualsWithDelta(52.520008, $shop->geoData->latitude, 0.0001);

        self::assertSame('16:00', $shop->expressCutOff);
        self::assertCount(2, $shop->shopServiceList);
        self::assertSame(ShopService::SearchAll, $shop->shopServiceList[0]);
        self::assertSame(ShopService::Paketstation, $shop->shopServiceList[1]);

        self::assertCount(1, $shop->openingHoursList);
        self::assertSame('Monday', $shop->openingHoursList[0]->weekDay);
        self::assertCount(1, $shop->openingHoursList[0]->openTimeList);
        self::assertSame('08:00', $shop->openingHoursList[0]->openTimeList[0]->timeFrom);
        self::assertSame('18:00', $shop->openingHoursList[0]->openTimeList[0]->timeEnd);

        self::assertCount(1, $shop->holidayList);
        self::assertSame('2024-12-24', $shop->holidayList[0]->holidayFrom->format('Y-m-d'));
        self::assertSame('2024-12-26', $shop->holidayList[0]->holidayEnd->format('Y-m-d'));

        self::assertSame('Ring the bell twice', $shop->extraInfo);
        self::assertSame('Parcel locker on ground floor', $shop->serviceDetail);
        self::assertSame('CUST-1', $shop->customerNo);
        self::assertSame(ParcelStationType::Standard, $shop->parcelStation);
        self::assertTrue($shop->isParcelLocker());
    }

    // ----------------------------------------------
    // getOrderStatus
    // ----------------------------------------------

    public function testOrderStatus(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_order_status_success.xml'));
        $status = $this->parser->orderStatus($xpath);

        self::assertSame('01234567890123', $status->parcelNo);

        self::assertNotNull($status->orderInformation);
        self::assertSame('01234567890123', $status->orderInformation->parcelNo);
        self::assertSame(101, $status->orderInformation->serviceCode);
        self::assertSame('Classic', $status->orderInformation->productName);
        self::assertSame('ORDER-1', $status->orderInformation->reference);
        self::assertSame('2.5', $status->orderInformation->weight);
        self::assertSame(1, $status->orderInformation->collis);
        self::assertTrue($status->orderInformation->completeDelivery);
        self::assertSame('Max Mustermann', $status->orderInformation->receiverName);
        self::assertSame('Very Code', $status->orderInformation->senderName);

        self::assertNotNull($status->shipAddress);
        self::assertSame('Max Mustermann', $status->shipAddress->name);
        self::assertSame('Berlin', $status->shipAddress->city);

        self::assertNotNull($status->lastStatusInfo);
        self::assertTrue($status->lastStatusInfo->statusReached);
        self::assertSame('DELIVERED', $status->lastStatusInfo->statusId);
        self::assertSame('Delivered', $status->lastStatusInfo->headline);

        self::assertNotNull($status->statusInfoContainer);
        self::assertNotNull($status->statusInfoContainer->start);
        self::assertSame('SHIPMENT', $status->statusInfoContainer->start->statusId);
        self::assertNotNull($status->statusInfoContainer->delivered);
        self::assertSame('DELIVERED', $status->statusInfoContainer->delivered->statusId);
        self::assertNull($status->statusInfoContainer->onTheRoad);
    }

    // ----------------------------------------------
    // getParcelLifeCycle (no-namespace quirk)
    // ----------------------------------------------

    public function testTrackingResultShipmentInfo(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_parcel_life_cycle_success.xml'));
        $result = $this->parser->trackingResult($xpath);

        self::assertNotNull($result->shipmentInfo);
        self::assertSame(TrackingStatus::Delivered, $result->shipmentInfo->status);
        self::assertTrue($result->shipmentInfo->statusHasBeenReached);
        self::assertTrue($result->shipmentInfo->isCurrentStatus);
        self::assertFalse($result->shipmentInfo->showContactInfo);

        self::assertNotNull($result->shipmentInfo->label);
        self::assertSame('Delivered', $result->shipmentInfo->label->content);
        self::assertTrue($result->shipmentInfo->label->bold);
        self::assertFalse($result->shipmentInfo->label->paragraph);

        self::assertNotNull($result->shipmentInfo->description);
        self::assertCount(1, $result->shipmentInfo->description->content);
        self::assertSame('Your parcel has been delivered.', $result->shipmentInfo->description->content[0]->content);

        self::assertNotNull($result->shipmentInfo->receiver);
        self::assertSame('Max Mustermann', $result->shipmentInfo->receiver->content[0]->content ?? null);

        self::assertCount(1, $result->shipmentInfo->trackingProperty);
        self::assertSame('weight', $result->shipmentInfo->trackingProperty[0]->key);
        self::assertSame('2.5', $result->shipmentInfo->trackingProperty[0]->value);
    }

    public function testTrackingResultStatusInfoList(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_parcel_life_cycle_success.xml'));
        $result = $this->parser->trackingResult($xpath);

        self::assertCount(2, $result->statusInfo);
        self::assertSame(TrackingStatus::Shipment, $result->statusInfo[0]->status);
        self::assertFalse($result->statusInfo[0]->isCurrentStatus);
        self::assertSame(TrackingStatus::Delivered, $result->statusInfo[1]->status);
        self::assertTrue($result->statusInfo[1]->isCurrentStatus);
    }

    public function testTrackingResultContactInfo(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_parcel_life_cycle_success.xml'));
        $result = $this->parser->trackingResult($xpath);

        self::assertCount(1, $result->contactInfo);
        self::assertNotNull($result->contactInfo[0]->label);
        self::assertSame('Support', $result->contactInfo[0]->label->content);
        self::assertSame('support@dpd.de', $result->contactInfo[0]->content[0]->content ?? null);
    }

    public function testGetTextConvenienceMethod(): void
    {
        $xpath = $this->parser->parse($this->fixture('get_parcel_life_cycle_success.xml'));
        $result = $this->parser->trackingResult($xpath);

        self::assertNotNull($result->shipmentInfo?->description);
        self::assertSame('Your parcel has been delivered.', $result->shipmentInfo->description->getText());
    }
}
