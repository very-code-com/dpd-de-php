<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Internal\Rest;

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\OrderSettings;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\DpdCloudConfig;
use VeryCodeCom\DpdDe\Enum\OrderAction;
use VeryCodeCom\DpdDe\Transport\TransportRequest;

/**
 * Builds REST requests for the DPD Cloud Service HTTP API (as an alternative to SOAP).
 *
 * DPD does not document REST in the PDF; the contract below follows their code examples in the
 * developer portal (Entwickler / DPD Cloud Webservice / Code Beispiele / REST / PHP):
 *
 *   POST /api/v1/setOrder                     JSON body, same PascalCase shape as the SOAP payload
 *   GET  /api/v1/ZipCodeRules
 *   GET  /api/v1/ParcelShopFinder/{Max}/{Street}/{HouseNo}/{ZipCode}/{City}/{Country}/{NeedService}/{HideOnClosedAt}
 *   GET  /api/v1/ParcelShopFinder/{Max}/{Longitude}/{Latitude}/{NeedService}/{HideOnClosedAt}
 *   GET  /api/v1/ParcelLifeCycle/{ParcelNo}
 *   GET  /api/v1/getOrderStatus/{ParcelNo}/{DeliveryZipCode}
 *
 * Two REST-only rules apply to every path parameter, both enforced by {@see self::path()}:
 * an empty value is passed as the literal string `null`, and a "." may not appear directly
 * before a "/" (DPD's router answers such URLs with HTTP 404).
 *
 * `Version`, `Language` and the four credential values are sent as HTTP headers, with
 * **hyphens** in the credential header names (`PartnerCredentials-Name`, not
 * `PartnerCredentials.Name`: the dotted spelling is rejected with ErrorID 2000 /
 * CLOUD_API_PARTNERCREDENTIALS).
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class RestRequestBuilder
{
    public function __construct(private readonly DpdCloudConfig $config)
    {
    }

    public function buildGetZipCodeRules(): TransportRequest
    {
        return $this->get('/ZipCodeRules');
    }

    /** @param list<OrderItem> $items */
    public function buildSetOrder(array $items, OrderSettings $settings, OrderAction $action): TransportRequest
    {
        $body = [
            'OrderAction' => $action->value,
            'OrderSettings' => [
                'ShipDate' => $settings->shipDate->format('Y-m-d\TH:i:s'),
                'LabelSize' => $settings->labelSize->value,
                'LabelStartPosition' => $settings->labelStartPosition->value,
            ],
            'OrderDataList' => array_map(
                fn (OrderItem $item): array => $this->orderDataToArray($item),
                $items,
            ),
        ];

        return $this->post('/setOrder', $body);
    }

    public function buildGetParcelShopFinder(ParcelShopQuery $query): TransportRequest
    {
        // Two different path layouts, picked by search mode (DPD does not use a SearchMode
        // parameter over REST; the shape of the URL is the mode).
        if ($query->searchGeoData !== null) {
            $segments = [
                (string) $query->maxReturnValues,
                self::formatCoordinate($query->searchGeoData->longitude),
                self::formatCoordinate($query->searchGeoData->latitude),
                $query->needService->value,
                $query->hideOnClosedAt,
            ];
        } else {
            $sa = $query->searchAddress;
            $segments = [
                (string) $query->maxReturnValues,
                $sa?->street,
                $sa?->houseNo,
                $sa?->zipCode,
                $sa?->city,
                $sa?->country,
                $query->needService->value,
                $query->hideOnClosedAt,
            ];
        }

        return $this->get('/ParcelShopFinder' . self::path($segments));
    }

    public function buildGetParcelLifeCycle(string $parcelNo): TransportRequest
    {
        return $this->get('/ParcelLifeCycle' . self::path([$parcelNo]));
    }

    public function buildGetOrderStatus(string $parcelNo, ?string $deliveryZipCode = null): TransportRequest
    {
        // The zip segment is structurally required: omitting it yields HTTP 404, so an unknown
        // zip is sent as the literal "null" (which DPD answers with anonymised tracking data).
        return $this->get('/getOrderStatus' . self::path([$parcelNo, $deliveryZipCode]));
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function orderDataToArray(OrderItem $item): array
    {
        $data = [
            'ShipAddress' => $this->addressToArray($item->shipAddress),
            'ParcelShopID' => $item->parcelShopId,
            'ParcelData' => array_filter([
                'ShipService' => $item->parcel->shipService->value,
                'Weight' => $item->parcel->weightKg,
                'Content' => $item->parcel->content,
                'YourInternalID' => $item->parcel->yourInternalId,
                'Reference1' => $item->parcel->reference1,
                'Reference2' => $item->parcel->reference2,
            ], static fn ($v) => $v !== null),
        ];

        if ($item->pudoId !== null) {
            $data['PudoID'] = $item->pudoId;
        }

        if ($item->parcel->cod !== null) {
            $data['ParcelData']['COD'] = array_filter([
                'Purpose' => $item->parcel->cod->purpose,
                'Amount' => $item->parcel->cod->amount,
                'Payment' => $item->parcel->cod->payment->value,
            ], static fn ($v) => $v !== null);
        }

        return $data;
    }

    /** @return array<string, string> */
    private function addressToArray(Address $addr): array
    {
        return array_filter([
            'Company' => $addr->company,
            'Salutation' => $addr->salutation,
            'Name' => $addr->name,
            'FirstName' => $addr->firstName,
            'LastName' => $addr->lastName,
            'Street' => $addr->street,
            'HouseNo' => $addr->houseNo,
            'Country' => $addr->getCountryCode(),
            'ZipCode' => $addr->zipCode,
            'City' => $addr->city,
            'State' => $addr->state,
            'Phone' => $addr->phone,
            'Mail' => $addr->mail,
            'Gender' => $addr->gender,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Joins REST path parameters, applying DPD's two URL rules: an empty value is passed as the
     * literal string `null`, and a "." is not allowed directly before a "/" (a trailing dot makes
     * DPD's router return HTTP 404, so "Musterstr." becomes "Musterstr").
     *
     * @param list<string|null> $segments
     */
    private static function path(array $segments): string
    {
        $encoded = array_map(
            static function (?string $segment): string {
                $segment = $segment === null ? '' : trim($segment);
                $segment = rtrim($segment, '.');

                return $segment === '' ? 'null' : rawurlencode($segment);
            },
            $segments,
        );

        return '/' . implode('/', $encoded);
    }

    /** Coordinates must not use scientific notation or a locale-dependent decimal separator. */
    private static function formatCoordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') ?: '0';
    }

    private function get(string $path): TransportRequest
    {
        $url = $this->config->getRestBase() . $path;

        return new TransportRequest(
            url: $url,
            method: 'GET',
            headers: $this->credentialHeaders(),
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
            verifySsl: $this->config->verifySsl(),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): TransportRequest
    {
        $headers = $this->credentialHeaders();
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        return new TransportRequest(
            url: $this->config->getRestBase() . $path,
            method: 'POST',
            body: json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            headers: $headers,
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
            verifySsl: $this->config->verifySsl(),
        );
    }

    /** @return array<string, string> */
    private function credentialHeaders(): array
    {
        // Hyphens, not dots, dotted header names are rejected with
        // ErrorID 2000 / CLOUD_API_PARTNERCREDENTIALS.
        return [
            'Version' => (string) DpdCloudConfig::API_VERSION,
            'Language' => $this->config->language,
            'PartnerCredentials-Name' => $this->config->partnerName,
            'PartnerCredentials-Token' => $this->config->partnerToken,
            'UserCredentials-cloudUserID' => (string) $this->config->userId,
            'UserCredentials-Token' => $this->config->userToken,
        ];
    }
}
