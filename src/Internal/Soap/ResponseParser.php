<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Internal\Soap;

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\ContentItem;
use VeryCodeCom\DpdDe\Dto\ContentLine;
use VeryCodeCom\DpdDe\Dto\DepotData;
use VeryCodeCom\DpdDe\Dto\ErrorData;
use VeryCodeCom\DpdDe\Dto\GeoData;
use VeryCodeCom\DpdDe\Dto\Holiday;
use VeryCodeCom\DpdDe\Dto\OpeningHours;
use VeryCodeCom\DpdDe\Dto\OpenTime;
use VeryCodeCom\DpdDe\Dto\OrderInformation;
use VeryCodeCom\DpdDe\Dto\OrderResult;
use VeryCodeCom\DpdDe\Dto\OrderStatus;
use VeryCodeCom\DpdDe\Dto\ParcelShop;
use VeryCodeCom\DpdDe\Dto\SetOrderResult;
use VeryCodeCom\DpdDe\Dto\ShipmentInfo;
use VeryCodeCom\DpdDe\Dto\StatusInfo;
use VeryCodeCom\DpdDe\Dto\StatusInfoContainer;
use VeryCodeCom\DpdDe\Dto\StatusInfoDetail;
use VeryCodeCom\DpdDe\Dto\TrackingProperty;
use VeryCodeCom\DpdDe\Dto\TrackingResult;
use VeryCodeCom\DpdDe\Dto\ZipCodeRules;
use VeryCodeCom\DpdDe\Enum\ParcelStationType;
use VeryCodeCom\DpdDe\Enum\ShopService;
use VeryCodeCom\DpdDe\Enum\TrackingStatus;
use VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException;

/**
 * Parses raw SOAP XML responses from DPD Cloud Service into typed DTOs.
 *
 * DPD Cloud Service responses are document/literal and namespace-qualified: the main response
 * elements live in `https://cloud.dpd.com/` (registered here as `tns`, `elementFormDefault="qualified"`).
 *
 * Namespace quirk (similar in spirit to very-code-com/suus-php's own namespace quirk, but the
 * opposite direction): the separate `ParcelLifeCycleService/2.0` schema used by
 * getParcelLifeCycle's `ParcelLifeCycleData` payload declares `elementFormDefault="qualified"`
 * at the schema level, but every individual element inside it explicitly overrides that with
 * `form="unqualified"` (see the WSDL). This means `shipmentInfo`, `statusInfo`, `status`,
 * `label`, `content`, etc. all appear in the **no-namespace** (empty) namespace in the actual
 * response, not `http://dpd.com/common/service/types/ParcelLifeCycleService/2.0` as one might
 * expect from the schema's `targetNamespace`: hence these are queried here with unprefixed
 * XPath expressions (which match the null namespace), not a `pcl:` prefix.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class ResponseParser
{
    private const NS_TNS = 'https://cloud.dpd.com/';
    private const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * Parse a raw XML string into a DOMXPath ready for querying.
     *
     * @throws DpdCloudResponseParseException if the XML is invalid or unparseable.
     */
    public function parse(string $xml, string $methodContext = ''): \DOMXPath
    {
        $dom = new \DOMDocument();
        $loaded = @$dom->loadXML($xml);

        if (!$loaded) {
            throw new DpdCloudResponseParseException(
                'DPD Cloud Service returned invalid XML' . ($methodContext ? " for {$methodContext}" : '') . '.'
            );
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('soap', self::NS_SOAP);
        $xpath->registerNamespace('tns', self::NS_TNS);

        return $xpath;
    }

    // -----------------------------------------------------------------
    // Common response envelope helpers
    // -----------------------------------------------------------------

    public function isSuccess(\DOMXPath $xpath, string $resultElement): bool
    {
        return strtolower($this->textOf($xpath, "//tns:{$resultElement}/tns:Ack")) === 'true';
    }

    public function systemInformation(\DOMXPath $xpath, string $resultElement): string
    {
        return $this->textOf($xpath, "//tns:{$resultElement}/tns:SystemInformation");
    }

    /** @return list<ErrorData> */
    public function errorDataList(\DOMXPath $xpath, string $resultElement): array
    {
        $errors = [];
        $nodes = $xpath->query("//tns:{$resultElement}/tns:ErrorDataList/tns:*");
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $errors[] = new ErrorData(
                errorId: (int) $this->textOf($xpath, 'tns:ErrorID', $node),
                errorCode: $this->textOf($xpath, 'tns:ErrorCode', $node),
                errorMsgShort: $this->textOf($xpath, 'tns:ErrorMsgShort', $node),
                errorMsgLong: $this->textOf($xpath, 'tns:ErrorMsgLong', $node),
            );
        }
        return $errors;
    }

    // -----------------------------------------------------------------
    // getZipCodeRules
    // -----------------------------------------------------------------

    public function zipCodeRules(\DOMXPath $xpath): ZipCodeRules
    {
        $prefix = '//tns:getZipCodeRulesResult/tns:ZipCodeRules';
        return new ZipCodeRules(
            country: $this->nullableTextOf($xpath, "{$prefix}/tns:Country"),
            zipCode: $this->nullableTextOf($xpath, "{$prefix}/tns:ZipCode"),
            noPickupDays: $this->nullableTextOf($xpath, "{$prefix}/tns:NoPickupDays"),
            expressCutOff: $this->nullableTextOf($xpath, "{$prefix}/tns:ExpressCutOff"),
            classicCutOff: $this->nullableTextOf($xpath, "{$prefix}/tns:ClassicCutOff"),
            pickupDepot: $this->nullableTextOf($xpath, "{$prefix}/tns:PickupDepot"),
            state: $this->nullableTextOf($xpath, "{$prefix}/tns:State"),
        );
    }

    // -----------------------------------------------------------------
    // setOrder
    // -----------------------------------------------------------------

    public function setOrderResult(\DOMXPath $xpath): SetOrderResult
    {
        $prefix = '//tns:setOrderResult/tns:LabelResponse';
        $labelBase64 = $this->textOf($xpath, "{$prefix}/tns:LabelPDF");

        $items = [];
        $nodes = $xpath->query("{$prefix}/tns:LabelDataList/tns:LabelData");
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $items[] = new OrderResult(
                    yourInternalId: $this->nullableTextOf($xpath, 'tns:YourInternalID', $node),
                    parcelNo: $this->textOf($xpath, 'tns:ParcelNo', $node),
                );
            }
        }

        $decoded = $labelBase64 !== '' ? base64_decode($labelBase64, strict: true) : '';
        if ($decoded === false) {
            throw new DpdCloudResponseParseException('DPD Cloud Service setOrder returned an invalid Base64 LabelPDF payload.');
        }

        return new SetOrderResult(labelPdf: $decoded, items: $items);
    }

    // -----------------------------------------------------------------
    // getParcelShopFinder
    // -----------------------------------------------------------------

    /** @return list<ParcelShop> */
    public function parcelShops(\DOMXPath $xpath): array
    {
        $shops = [];
        $nodes = $xpath->query('//tns:getParcelShopFinderResult/tns:ParcelShopList/tns:ParcelShop');
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $shops[] = $this->parcelShop($xpath, $node);
        }
        return $shops;
    }

    private function parcelShop(\DOMXPath $xpath, \DOMElement $node): ParcelShop
    {
        $addressNode = $this->firstElement($xpath, 'tns:ShopAddress', $node);
        $geoNode = $this->firstElement($xpath, 'tns:GeoData', $node);

        $shopServices = [];
        $svcNodes = $xpath->query('tns:ShopServiceList/tns:ShopService', $node);
        if ($svcNodes !== false) {
            foreach ($svcNodes as $svcNode) {
                if (!$svcNode instanceof \DOMElement) {
                    continue;
                }
                $value = trim($svcNode->textContent);
                $enum = ShopService::tryFrom($value);
                if ($enum !== null) {
                    $shopServices[] = $enum;
                }
            }
        }

        $openingHours = [];
        $ohNodes = $xpath->query('tns:OpeningHoursList/tns:OpeningHours', $node);
        if ($ohNodes !== false) {
            foreach ($ohNodes as $ohNode) {
                if (!$ohNode instanceof \DOMElement) {
                    continue;
                }
                $openTimes = [];
                $otNodes = $xpath->query('tns:OpenTimeList/tns:OpenTimeType', $ohNode);
                if ($otNodes !== false) {
                    foreach ($otNodes as $otNode) {
                        if (!$otNode instanceof \DOMElement) {
                            continue;
                        }
                        $openTimes[] = new OpenTime(
                            timeFrom: $this->nullableTextOf($xpath, 'tns:TimeFrom', $otNode),
                            timeEnd: $this->nullableTextOf($xpath, 'tns:TimeEnd', $otNode),
                        );
                    }
                }
                $openingHours[] = new OpeningHours(
                    weekDay: $this->nullableTextOf($xpath, 'tns:WeekDay', $ohNode),
                    openTimeList: $openTimes,
                );
            }
        }

        $holidays = [];
        $holNodes = $xpath->query('tns:HolidayList/tns:Holiday', $node);
        if ($holNodes !== false) {
            foreach ($holNodes as $holNode) {
                if (!$holNode instanceof \DOMElement) {
                    continue;
                }
                $from = $this->parseDateTime($this->textOf($xpath, 'tns:HolidayFrom', $holNode));
                $to = $this->parseDateTime($this->textOf($xpath, 'tns:HolidayEnd', $holNode));
                if ($from !== null && $to !== null) {
                    $holidays[] = new Holiday($from, $to);
                }
            }
        }

        $parcelStationValue = $this->textOf($xpath, 'tns:ParcelStation', $node);

        return new ParcelShop(
            parcelShopId: (int) $this->textOf($xpath, 'tns:ParcelShopID', $node),
            shopAddress: $addressNode !== null ? $this->address($xpath, $addressNode) : null,
            homepage: $this->nullableTextOf($xpath, 'tns:Homepage', $node),
            geoData: $geoNode !== null ? $this->geoData($xpath, $geoNode) : null,
            expressCutOff: $this->nullableTextOf($xpath, 'tns:ExpressCutOff', $node),
            shopServiceList: $shopServices,
            openingHoursList: $openingHours,
            holidayList: $holidays,
            extraInfo: $this->nullableTextOf($xpath, 'tns:ExtraInfo', $node),
            serviceDetail: $this->nullableTextOf($xpath, 'tns:ServiceDetail', $node),
            customerNo: $this->nullableTextOf($xpath, 'tns:CustomerNo', $node),
            parcelStation: ParcelStationType::tryFrom($parcelStationValue) ?? ParcelStationType::None,
        );
    }

    private function geoData(\DOMXPath $xpath, \DOMElement $node): GeoData
    {
        return new GeoData(
            distance: (float) $this->textOf($xpath, 'tns:Distance', $node),
            longitude: (float) $this->textOf($xpath, 'tns:Longitude', $node),
            latitude: (float) $this->textOf($xpath, 'tns:Latitude', $node),
            coordinateX: (float) $this->textOf($xpath, 'tns:CoordinateX', $node),
            coordinateY: (float) $this->textOf($xpath, 'tns:CoordinateY', $node),
            coordinateZ: (float) $this->textOf($xpath, 'tns:CoordinateZ', $node),
        );
    }

    private function address(\DOMXPath $xpath, \DOMElement $node): Address
    {
        return new Address(
            company: $this->nullableTextOf($xpath, 'tns:Company', $node),
            salutation: $this->nullableTextOf($xpath, 'tns:Salutation', $node),
            name: $this->nullableTextOf($xpath, 'tns:Name', $node),
            firstName: $this->nullableTextOf($xpath, 'tns:FirstName', $node),
            lastName: $this->nullableTextOf($xpath, 'tns:LastName', $node),
            street: $this->nullableTextOf($xpath, 'tns:Street', $node),
            houseNo: $this->nullableTextOf($xpath, 'tns:HouseNo', $node),
            country: $this->nullableTextOf($xpath, 'tns:Country', $node),
            zipCode: $this->nullableTextOf($xpath, 'tns:ZipCode', $node),
            city: $this->nullableTextOf($xpath, 'tns:City', $node),
            state: $this->nullableTextOf($xpath, 'tns:State', $node),
            phone: $this->nullableTextOf($xpath, 'tns:Phone', $node),
            mail: $this->nullableTextOf($xpath, 'tns:Mail', $node),
            gender: $this->nullableTextOf($xpath, 'tns:Gender', $node),
        );
    }

    // -----------------------------------------------------------------
    // getOrderStatus
    // -----------------------------------------------------------------

    public function orderStatus(\DOMXPath $xpath): OrderStatus
    {
        $node = $this->firstElement($xpath, '//tns:getOrderStatusResult/tns:OrderStatus');
        if ($node === null) {
            throw new DpdCloudResponseParseException('DPD Cloud Service getOrderStatus response is missing the OrderStatus element.');
        }

        $orderInfoNode = $this->firstElement($xpath, 'tns:OrderInformation', $node);
        $shipAddressNode = $this->firstElement($xpath, 'tns:ShipAddress', $node);
        $lastStatusNode = $this->firstElement($xpath, 'tns:LastStatusInfo', $node);
        $containerNode = $this->firstElement($xpath, 'tns:StatusInfoContainer', $node);

        return new OrderStatus(
            parcelNo: $this->nullableTextOf($xpath, 'tns:ParcelNo', $node),
            orderInformation: $orderInfoNode !== null ? $this->orderInformation($xpath, $orderInfoNode) : null,
            shipAddress: $shipAddressNode !== null ? $this->address($xpath, $shipAddressNode) : null,
            lastStatusInfo: $lastStatusNode !== null ? $this->statusInfoDetail($xpath, $lastStatusNode) : null,
            statusInfoContainer: $containerNode !== null ? $this->statusInfoContainer($xpath, $containerNode) : null,
        );
    }

    private function orderInformation(\DOMXPath $xpath, \DOMElement $node): OrderInformation
    {
        return new OrderInformation(
            parcelNo: $this->nullableTextOf($xpath, 'tns:ParcelNo', $node),
            mpsId: $this->nullableTextOf($xpath, 'tns:MPSID', $node),
            serviceCode: (int) $this->textOf($xpath, 'tns:ServiceCode', $node),
            productName: $this->nullableTextOf($xpath, 'tns:ProductName', $node),
            reference: $this->nullableTextOf($xpath, 'tns:Reference', $node),
            weight: $this->nullableTextOf($xpath, 'tns:Weight', $node),
            codAmount: $this->nullableTextOf($xpath, 'tns:CODAmount', $node),
            collis: (int) $this->textOf($xpath, 'tns:Collis', $node),
            parcelNoList: $this->nullableTextOf($xpath, 'tns:ParcelNoList', $node),
            completeDelivery: strtolower($this->textOf($xpath, 'tns:CompleteDelivery', $node)) === 'true',
            receiverName: $this->nullableTextOf($xpath, 'tns:ReceiverName', $node),
            senderName: $this->nullableTextOf($xpath, 'tns:SenderName', $node),
            estimatedDeliveryTime: $this->nullableTextOf($xpath, 'tns:EstimatedDeliveryTime', $node),
        );
    }

    private function statusInfoDetail(\DOMXPath $xpath, \DOMElement $node): StatusInfoDetail
    {
        $depotNode = $this->firstElement($xpath, 'tns:DepotData', $node);

        return new StatusInfoDetail(
            statusReached: strtolower($this->textOf($xpath, 'tns:StatusReached', $node)) === 'true',
            statusId: $this->nullableTextOf($xpath, 'tns:StatusID', $node),
            headline: $this->nullableTextOf($xpath, 'tns:Headline', $node),
            description: $this->nullableTextOf($xpath, 'tns:Description', $node),
            statusTextMobile: $this->nullableTextOf($xpath, 'tns:StatusText_Mobile', $node),
            statusTextDesktop: $this->nullableTextOf($xpath, 'tns:StatusText_Desktop', $node),
            statusDate: $this->nullableTextOf($xpath, 'tns:StatusDate', $node),
            depotData: $depotNode !== null ? $this->depotData($xpath, $depotNode) : null,
        );
    }

    private function depotData(\DOMXPath $xpath, \DOMElement $node): DepotData
    {
        $geoNode = $this->firstElement($xpath, 'tns:GeoData', $node);
        $addressNode = $this->firstElement($xpath, 'tns:Address', $node);

        return new DepotData(
            depot: $this->nullableTextOf($xpath, 'tns:Depot', $node),
            geoData: $geoNode !== null ? $this->geoData($xpath, $geoNode) : null,
            address: $addressNode !== null ? $this->address($xpath, $addressNode) : null,
        );
    }

    private function statusInfoContainer(\DOMXPath $xpath, \DOMElement $node): StatusInfoContainer
    {
        $start = $this->firstElement($xpath, 'tns:Start', $node);
        $onTheRoad = $this->firstElement($xpath, 'tns:OnTheRoad', $node);
        $deliveryDepot = $this->firstElement($xpath, 'tns:DeliveryDepot', $node);
        $carLoad = $this->firstElement($xpath, 'tns:CarLoad', $node);
        $delivered = $this->firstElement($xpath, 'tns:Delivered', $node);

        return new StatusInfoContainer(
            start: $start !== null ? $this->statusInfoDetail($xpath, $start) : null,
            onTheRoad: $onTheRoad !== null ? $this->statusInfoDetail($xpath, $onTheRoad) : null,
            deliveryDepot: $deliveryDepot !== null ? $this->statusInfoDetail($xpath, $deliveryDepot) : null,
            carLoad: $carLoad !== null ? $this->statusInfoDetail($xpath, $carLoad) : null,
            delivered: $delivered !== null ? $this->statusInfoDetail($xpath, $delivered) : null,
        );
    }

    // -----------------------------------------------------------------
    // getParcelLifeCycle
    // -----------------------------------------------------------------

    public function trackingResult(\DOMXPath $xpath): TrackingResult
    {
        $node = $this->firstElement($xpath, '//tns:getParcelLifeCycleResult/tns:ParcelLifeCycleData');
        if ($node === null) {
            throw new DpdCloudResponseParseException('DPD Cloud Service getParcelLifeCycle response is missing the ParcelLifeCycleData element.');
        }

        $shipmentInfoNode = $this->firstElement($xpath, 'shipmentInfo', $node);

        $statusInfoList = [];
        $statusNodes = $xpath->query('statusInfo', $node);
        if ($statusNodes !== false) {
            foreach ($statusNodes as $statusNode) {
                if (!$statusNode instanceof \DOMElement) {
                    continue;
                }
                $statusInfoList[] = $this->statusInfo($xpath, $statusNode);
            }
        }

        $contactInfoList = [];
        $contactNodes = $xpath->query('contactInfo', $node);
        if ($contactNodes !== false) {
            foreach ($contactNodes as $contactNode) {
                if (!$contactNode instanceof \DOMElement) {
                    continue;
                }
                $contactInfoList[] = $this->contentItem($xpath, $contactNode);
            }
        }

        return new TrackingResult(
            shipmentInfo: $shipmentInfoNode !== null ? $this->shipmentInfo($xpath, $shipmentInfoNode) : null,
            statusInfo: $statusInfoList,
            contactInfo: $contactInfoList,
        );
    }

    private function statusInfo(\DOMXPath $xpath, \DOMElement $node): StatusInfo
    {
        $statusValue = $this->textOf($xpath, 'status', $node);

        return new StatusInfo(
            status: TrackingStatus::tryFrom($statusValue) ?? TrackingStatus::Shipment,
            label: $this->contentLineOrNull($xpath, 'label', $node),
            description: $this->contentItemOrNull($xpath, 'description', $node),
            statusHasBeenReached: strtolower($this->textOf($xpath, 'statusHasBeenReached', $node)) === 'true',
            isCurrentStatus: strtolower($this->textOf($xpath, 'isCurrentStatus', $node)) === 'true',
            showContactInfo: strtolower($this->textOf($xpath, 'showContactInfo', $node)) === 'true',
            location: $this->contentLineOrNull($xpath, 'location', $node),
            date: $this->contentLineOrNull($xpath, 'date', $node),
            normalItems: $this->contentItemList($xpath, 'normalItems', $node),
            importantItems: $this->contentItemList($xpath, 'importantItems', $node),
            errorItems: $this->contentItemList($xpath, 'errorItems', $node),
        );
    }

    private function shipmentInfo(\DOMXPath $xpath, \DOMElement $node): ShipmentInfo
    {
        $trackingProps = [];
        $propNodes = $xpath->query('trackingProperty', $node);
        if ($propNodes !== false) {
            foreach ($propNodes as $propNode) {
                if (!$propNode instanceof \DOMElement) {
                    continue;
                }
                $trackingProps[] = new TrackingProperty(
                    key: $this->nullableTextOf($xpath, 'key', $propNode),
                    value: $this->nullableTextOf($xpath, 'value', $propNode),
                );
            }
        }

        $statusValue = $this->textOf($xpath, 'status', $node);

        return new ShipmentInfo(
            status: TrackingStatus::tryFrom($statusValue) ?? TrackingStatus::Shipment,
            label: $this->contentLineOrNull($xpath, 'label', $node),
            description: $this->contentItemOrNull($xpath, 'description', $node),
            statusHasBeenReached: strtolower($this->textOf($xpath, 'statusHasBeenReached', $node)) === 'true',
            isCurrentStatus: strtolower($this->textOf($xpath, 'isCurrentStatus', $node)) === 'true',
            showContactInfo: strtolower($this->textOf($xpath, 'showContactInfo', $node)) === 'true',
            location: $this->contentLineOrNull($xpath, 'location', $node),
            date: $this->contentLineOrNull($xpath, 'date', $node),
            normalItems: $this->contentItemList($xpath, 'normalItems', $node),
            importantItems: $this->contentItemList($xpath, 'importantItems', $node),
            errorItems: $this->contentItemList($xpath, 'errorItems', $node),
            receiver: $this->contentItemOrNull($xpath, 'receiver', $node),
            predictInformation: $this->contentItemOrNull($xpath, 'predictInformation', $node),
            serviceDescription: $this->contentItemOrNull($xpath, 'serviceDescription', $node),
            additionalServiceElements: $this->contentItemOrNull($xpath, 'additionalServiceElements', $node),
            trackingProperty: $trackingProps,
        );
    }

    /** @return list<ContentItem> */
    private function contentItemList(\DOMXPath $xpath, string $query, \DOMElement $context): array
    {
        $items = [];
        $nodes = $xpath->query($query, $context);
        if ($nodes === false) {
            return [];
        }
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $items[] = $this->contentItem($xpath, $node);
        }
        return $items;
    }

    private function contentItem(\DOMXPath $xpath, \DOMElement $node): ContentItem
    {
        $lines = [];
        $lineNodes = $xpath->query('content', $node);
        if ($lineNodes !== false) {
            foreach ($lineNodes as $lineNode) {
                if (!$lineNode instanceof \DOMElement) {
                    continue;
                }
                $lines[] = $this->contentLine($xpath, $lineNode);
            }
        }

        return new ContentItem(
            label: $this->contentLineOrNull($xpath, 'label', $node),
            content: $lines,
            linkTarget: $this->nullableTextOf($xpath, 'linkTarget', $node),
        );
    }

    private function contentItemOrNull(\DOMXPath $xpath, string $query, \DOMElement $context): ?ContentItem
    {
        $node = $this->firstElement($xpath, $query, $context);
        return $node !== null ? $this->contentItem($xpath, $node) : null;
    }

    private function contentLine(\DOMXPath $xpath, \DOMElement $node): ContentLine
    {
        return new ContentLine(
            content: $this->nullableTextOf($xpath, 'content', $node),
            bold: strtolower($this->textOf($xpath, 'bold', $node)) === 'true',
            paragraph: strtolower($this->textOf($xpath, 'paragraph', $node)) === 'true',
        );
    }

    private function contentLineOrNull(\DOMXPath $xpath, string $query, \DOMElement $context): ?ContentLine
    {
        $node = $this->firstElement($xpath, $query, $context);
        return $node !== null ? $this->contentLine($xpath, $node) : null;
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    private function firstElement(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?\DOMElement
    {
        $list = $context !== null ? $xpath->query($query, $context) : $xpath->query($query);
        if ($list === false || $list->length === 0) {
            return null;
        }
        $item = $list->item(0);
        return $item instanceof \DOMElement ? $item : null;
    }

    private function textOf(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): string
    {
        $list = $context !== null ? $xpath->query($query, $context) : $xpath->query($query);
        if ($list === false || $list->length === 0) {
            return '';
        }
        $item = $list->item(0);
        if (!$item instanceof \DOMElement) {
            return '';
        }
        return trim($item->textContent);
    }

    private function nullableTextOf(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?string
    {
        $list = $context !== null ? $xpath->query($query, $context) : $xpath->query($query);
        if ($list === false || $list->length === 0) {
            return null;
        }
        $item = $list->item(0);
        if (!$item instanceof \DOMElement) {
            return null;
        }
        $text = trim($item->textContent);
        return $text !== '' ? $text : null;
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);
        return $dt !== false ? $dt : null;
    }
}
