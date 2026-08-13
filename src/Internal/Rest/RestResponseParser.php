<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Internal\Rest;

use VeryCodeCom\DpdDe\Dto\Address;
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
use VeryCodeCom\DpdDe\Dto\StatusInfoContainer;
use VeryCodeCom\DpdDe\Dto\StatusInfoDetail;
use VeryCodeCom\DpdDe\Dto\ZipCodeRules;
use VeryCodeCom\DpdDe\Enum\ParcelStationType;
use VeryCodeCom\DpdDe\Enum\ShopService;
use VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException;

/**
 * Parses REST (JSON) responses from DPD Cloud Service into the same typed DTOs used by the SOAP
 * transport. The JSON body uses the same PascalCase field names and nesting as the WSDL schema,
 * so this class largely mirrors {@see \VeryCodeCom\DpdDe\Internal\Soap\ResponseParser} but reads
 * plain decoded arrays instead of DOMXPath.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class RestResponseParser
{
    /**
     * @return array<string, mixed>
     * @throws DpdCloudResponseParseException
     */
    public function decode(string $json, string $methodContext = ''): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DpdCloudResponseParseException(
                'DPD Cloud Service returned invalid JSON' . ($methodContext ? " for {$methodContext}" : '') . '.',
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new DpdCloudResponseParseException(
                'DPD Cloud Service returned a JSON response that is not an object' . ($methodContext ? " for {$methodContext}" : '') . '.'
            );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, mixed> $data */
    public function isSuccess(array $data): bool
    {
        return (bool) ($data['Ack'] ?? false);
    }

    /**
     * DPD's free-text service message, present on every response and usually null.
     *
     * @param array<string, mixed> $data
     */
    public function systemInformation(array $data): string
    {
        $value = $data['SystemInformation'] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ErrorData>
     */
    public function errorDataList(array $data): array
    {
        $list = $data['ErrorDataList'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $errors = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $errors[] = new ErrorData(
                errorId: (int) ($entry['ErrorID'] ?? 0),
                errorCode: (string) ($entry['ErrorCode'] ?? ''),
                errorMsgShort: (string) ($entry['ErrorMsgShort'] ?? ''),
                errorMsgLong: (string) ($entry['ErrorMsgLong'] ?? ''),
            );
        }
        return $errors;
    }

    /** @param array<string, mixed> $data */
    public function zipCodeRules(array $data): ZipCodeRules
    {
        /** @var array<string, mixed> $z */
        $z = is_array($data['ZipCodeRules'] ?? null) ? $data['ZipCodeRules'] : [];

        return new ZipCodeRules(
            country: $this->str($z, 'Country'),
            zipCode: $this->str($z, 'ZipCode'),
            noPickupDays: $this->str($z, 'NoPickupDays'),
            expressCutOff: $this->str($z, 'ExpressCutOff'),
            classicCutOff: $this->str($z, 'ClassicCutOff'),
            pickupDepot: $this->str($z, 'PickupDepot'),
            state: $this->str($z, 'State'),
        );
    }

    /** @param array<string, mixed> $data */
    public function setOrderResult(array $data): SetOrderResult
    {
        /** @var array<string, mixed> $labelResponse */
        $labelResponse = is_array($data['LabelResponse'] ?? null) ? $data['LabelResponse'] : [];
        $base64 = (string) ($labelResponse['LabelPDF'] ?? '');

        $items = [];
        $labelDataList = $labelResponse['LabelDataList'] ?? [];
        if (is_array($labelDataList)) {
            foreach ($labelDataList as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $items[] = new OrderResult(
                    yourInternalId: $this->str($entry, 'YourInternalID'),
                    parcelNo: (string) ($entry['ParcelNo'] ?? ''),
                );
            }
        }

        $decoded = $base64 !== '' ? base64_decode($base64, strict: true) : '';
        if ($decoded === false) {
            throw new DpdCloudResponseParseException('DPD Cloud Service setOrder returned an invalid Base64 LabelPDF payload.');
        }

        return new SetOrderResult(labelPdf: $decoded, items: $items);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ParcelShop>
     */
    public function parcelShops(array $data): array
    {
        $list = $data['ParcelShopList'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $shops = [];
        foreach ($list as $entry) {
            if (is_array($entry)) {
                $shops[] = $this->parcelShop($entry);
            }
        }
        return $shops;
    }

    /** @param array<string, mixed> $entry */
    private function parcelShop(array $entry): ParcelShop
    {
        /** @var array<string, mixed>|null $shopAddress */
        $shopAddress = is_array($entry['ShopAddress'] ?? null) ? $entry['ShopAddress'] : null;
        /** @var array<string, mixed>|null $geo */
        $geo = is_array($entry['GeoData'] ?? null) ? $entry['GeoData'] : null;

        $shopServices = [];
        $svcList = $entry['ShopServiceList'] ?? [];
        if (is_array($svcList)) {
            foreach ($svcList as $svc) {
                $enum = is_string($svc) ? ShopService::tryFrom($svc) : null;
                if ($enum !== null) {
                    $shopServices[] = $enum;
                }
            }
        }

        $openingHours = [];
        $ohList = $entry['OpeningHoursList'] ?? [];
        if (is_array($ohList)) {
            foreach ($ohList as $oh) {
                if (!is_array($oh)) {
                    continue;
                }
                $openTimes = [];
                $otList = $oh['OpenTimeList'] ?? [];
                if (is_array($otList)) {
                    foreach ($otList as $ot) {
                        if (!is_array($ot)) {
                            continue;
                        }
                        $openTimes[] = new OpenTime(timeFrom: $this->str($ot, 'TimeFrom'), timeEnd: $this->str($ot, 'TimeEnd'));
                    }
                }
                $openingHours[] = new OpeningHours(weekDay: $this->str($oh, 'WeekDay'), openTimeList: $openTimes);
            }
        }

        $holidays = [];
        $holList = $entry['HolidayList'] ?? [];
        if (is_array($holList)) {
            foreach ($holList as $hol) {
                if (!is_array($hol) || !isset($hol['HolidayFrom'], $hol['HolidayEnd'])) {
                    continue;
                }
                $from = $this->parseDateTime((string) $hol['HolidayFrom']);
                $to = $this->parseDateTime((string) $hol['HolidayEnd']);
                if ($from !== null && $to !== null) {
                    $holidays[] = new Holiday($from, $to);
                }
            }
        }

        return new ParcelShop(
            parcelShopId: (int) ($entry['ParcelShopID'] ?? 0),
            shopAddress: $shopAddress !== null ? $this->address($shopAddress) : null,
            homepage: $this->str($entry, 'Homepage'),
            geoData: $geo !== null ? $this->geoData($geo) : null,
            expressCutOff: $this->str($entry, 'ExpressCutOff'),
            shopServiceList: $shopServices,
            openingHoursList: $openingHours,
            holidayList: $holidays,
            extraInfo: $this->str($entry, 'ExtraInfo'),
            serviceDetail: $this->str($entry, 'ServiceDetail'),
            customerNo: $this->str($entry, 'CustomerNo'),
            parcelStation: ParcelStationType::tryFrom((string) ($entry['ParcelStation'] ?? '')) ?? ParcelStationType::None,
        );
    }

    /** @param array<string, mixed> $data */
    private function geoData(array $data): GeoData
    {
        return new GeoData(
            distance: (float) ($data['Distance'] ?? 0),
            longitude: (float) ($data['Longitude'] ?? 0),
            latitude: (float) ($data['Latitude'] ?? 0),
            coordinateX: (float) ($data['CoordinateX'] ?? 0),
            coordinateY: (float) ($data['CoordinateY'] ?? 0),
            coordinateZ: (float) ($data['CoordinateZ'] ?? 0),
        );
    }

    /** @param array<string, mixed> $data */
    private function address(array $data): Address
    {
        return new Address(
            company: $this->str($data, 'Company'),
            salutation: $this->str($data, 'Salutation'),
            name: $this->str($data, 'Name'),
            firstName: $this->str($data, 'FirstName'),
            lastName: $this->str($data, 'LastName'),
            street: $this->str($data, 'Street'),
            houseNo: $this->str($data, 'HouseNo'),
            country: $this->str($data, 'Country'),
            zipCode: $this->str($data, 'ZipCode'),
            city: $this->str($data, 'City'),
            state: $this->str($data, 'State'),
            phone: $this->str($data, 'Phone'),
            mail: $this->str($data, 'Mail'),
            gender: $this->str($data, 'Gender'),
        );
    }

    /** @param array<string, mixed> $data */
    public function orderStatus(array $data): OrderStatus
    {
        /** @var array<string, mixed>|null $orderInfo */
        $orderInfo = is_array($data['OrderInformation'] ?? null) ? $data['OrderInformation'] : null;
        /** @var array<string, mixed>|null $shipAddress */
        $shipAddress = is_array($data['ShipAddress'] ?? null) ? $data['ShipAddress'] : null;
        /** @var array<string, mixed>|null $lastStatus */
        $lastStatus = is_array($data['LastStatusInfo'] ?? null) ? $data['LastStatusInfo'] : null;
        /** @var array<string, mixed>|null $container */
        $container = is_array($data['StatusInfoContainer'] ?? null) ? $data['StatusInfoContainer'] : null;

        return new OrderStatus(
            parcelNo: $this->str($data, 'ParcelNo'),
            orderInformation: $orderInfo !== null ? $this->orderInformation($orderInfo) : null,
            shipAddress: $shipAddress !== null ? $this->address($shipAddress) : null,
            lastStatusInfo: $lastStatus !== null ? $this->statusInfoDetail($lastStatus) : null,
            statusInfoContainer: $container !== null ? $this->statusInfoContainer($container) : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function orderInformation(array $data): OrderInformation
    {
        return new OrderInformation(
            parcelNo: $this->str($data, 'ParcelNo'),
            mpsId: $this->str($data, 'MPSID'),
            serviceCode: (int) ($data['ServiceCode'] ?? 0),
            productName: $this->str($data, 'ProductName'),
            reference: $this->str($data, 'Reference'),
            weight: $this->str($data, 'Weight'),
            codAmount: $this->str($data, 'CODAmount'),
            collis: (int) ($data['Collis'] ?? 0),
            parcelNoList: $this->str($data, 'ParcelNoList'),
            completeDelivery: (bool) ($data['CompleteDelivery'] ?? false),
            receiverName: $this->str($data, 'ReceiverName'),
            senderName: $this->str($data, 'SenderName'),
            estimatedDeliveryTime: $this->str($data, 'EstimatedDeliveryTime'),
        );
    }

    /** @param array<string, mixed> $data */
    private function statusInfoDetail(array $data): StatusInfoDetail
    {
        /** @var array<string, mixed>|null $depot */
        $depot = is_array($data['DepotData'] ?? null) ? $data['DepotData'] : null;

        return new StatusInfoDetail(
            statusReached: (bool) ($data['StatusReached'] ?? false),
            statusId: $this->str($data, 'StatusID'),
            headline: $this->str($data, 'Headline'),
            description: $this->str($data, 'Description'),
            statusTextMobile: $this->str($data, 'StatusText_Mobile'),
            statusTextDesktop: $this->str($data, 'StatusText_Desktop'),
            statusDate: $this->str($data, 'StatusDate'),
            depotData: $depot !== null ? $this->depotData($depot) : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function depotData(array $data): \VeryCodeCom\DpdDe\Dto\DepotData
    {
        /** @var array<string, mixed>|null $geo */
        $geo = is_array($data['GeoData'] ?? null) ? $data['GeoData'] : null;
        /** @var array<string, mixed>|null $address */
        $address = is_array($data['Address'] ?? null) ? $data['Address'] : null;

        return new \VeryCodeCom\DpdDe\Dto\DepotData(
            depot: $this->str($data, 'Depot'),
            geoData: $geo !== null ? $this->geoData($geo) : null,
            address: $address !== null ? $this->address($address) : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function statusInfoContainer(array $data): StatusInfoContainer
    {
        /** @var array<string, mixed>|null $start */
        $start = is_array($data['Start'] ?? null) ? $data['Start'] : null;
        /** @var array<string, mixed>|null $onTheRoad */
        $onTheRoad = is_array($data['OnTheRoad'] ?? null) ? $data['OnTheRoad'] : null;
        /** @var array<string, mixed>|null $deliveryDepot */
        $deliveryDepot = is_array($data['DeliveryDepot'] ?? null) ? $data['DeliveryDepot'] : null;
        /** @var array<string, mixed>|null $carLoad */
        $carLoad = is_array($data['CarLoad'] ?? null) ? $data['CarLoad'] : null;
        /** @var array<string, mixed>|null $delivered */
        $delivered = is_array($data['Delivered'] ?? null) ? $data['Delivered'] : null;

        return new StatusInfoContainer(
            start: $start !== null ? $this->statusInfoDetail($start) : null,
            onTheRoad: $onTheRoad !== null ? $this->statusInfoDetail($onTheRoad) : null,
            deliveryDepot: $deliveryDepot !== null ? $this->statusInfoDetail($deliveryDepot) : null,
            carLoad: $carLoad !== null ? $this->statusInfoDetail($carLoad) : null,
            delivered: $delivered !== null ? $this->statusInfoDetail($delivered) : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $str = (string) $value;
        return $str !== '' ? $str : null;
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
