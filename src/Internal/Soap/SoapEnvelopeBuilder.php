<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Internal\Soap;

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\OrderSettings;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\DpdCloudConfig;
use VeryCodeCom\DpdDe\Enum\OrderAction;

/**
 * Builds raw SOAP 1.1 XML envelopes for all five DPD Cloud Service operations.
 *
 * DPD Cloud Service's WSDL binding style is **document/literal** with
 * `elementFormDefault="qualified"` and default namespace `https://cloud.dpd.com/`. This is
 * fundamentally different from very-code-com/suus-php's RPC/encoded style:
 *  - No `xsi:type` attributes on elements (document/literal carries types via the schema, not the wire).
 *  - The outer request element (e.g. `<setOrder>`) and all its qualified descendants live in the
 *    `https://cloud.dpd.com/` namespace, declared once as the default `xmlns` on the request root.
 *  - `Version`/`Language`/`PartnerCredentials`/`UserCredentials` are repeated on every request type.
 *
 * All knowledge about the DPD Cloud Service XML structure lives here. DpdCloudClient
 * stays clean and only orchestrates calls.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class SoapEnvelopeBuilder
{
    private const NS = 'https://cloud.dpd.com/';

    public function __construct(private readonly DpdCloudConfig $config)
    {
    }

    // -----------------------------------------------------------------
    // Public builders (one per DPD Cloud Service operation)
    // -----------------------------------------------------------------

    public function buildGetZipCodeRules(): string
    {
        $body = '<getZipCodeRulesRequest>'
            . $this->credentialsXml()
            . '</getZipCodeRulesRequest>';

        return $this->envelope('getZipCodeRules', $body);
    }

    /** @param list<OrderItem> $items */
    public function buildSetOrder(array $items, OrderSettings $settings, OrderAction $action): string
    {
        $body = '<setOrderRequest>'
            . $this->credentialsXml()
            . '<OrderAction>' . self::xe($action->value) . '</OrderAction>'
            . '<OrderSettings>'
                . '<ShipDate>' . self::xe($settings->shipDate->format('Y-m-d\TH:i:s')) . '</ShipDate>'
                . '<LabelSize>' . self::xe($settings->labelSize->value) . '</LabelSize>'
                . '<LabelStartPosition>' . self::xe($settings->labelStartPosition->value) . '</LabelStartPosition>'
            . '</OrderSettings>'
            . '<OrderDataList>';

        foreach ($items as $item) {
            $body .= '<OrderData>'
                . $this->addressXml($item->shipAddress, 'ShipAddress')
                . '<ParcelShopID>' . $item->parcelShopId . '</ParcelShopID>';

            if ($item->pudoId !== null) {
                $body .= '<PudoID>' . self::xe($item->pudoId) . '</PudoID>';
            }

            $body .= '<ParcelData>'
                . '<ShipService>' . self::xe($item->parcel->shipService->value) . '</ShipService>'
                . '<Weight>' . self::formatDecimal($item->parcel->weightKg) . '</Weight>';

            if ($item->parcel->content !== null) {
                $body .= '<Content>' . self::xe($item->parcel->content) . '</Content>';
            }
            if ($item->parcel->yourInternalId !== null) {
                $body .= '<YourInternalID>' . self::xe($item->parcel->yourInternalId) . '</YourInternalID>';
            }
            if ($item->parcel->reference1 !== null) {
                $body .= '<Reference1>' . self::xe($item->parcel->reference1) . '</Reference1>';
            }
            if ($item->parcel->reference2 !== null) {
                $body .= '<Reference2>' . self::xe($item->parcel->reference2) . '</Reference2>';
            }
            if ($item->parcel->cod !== null) {
                $body .= '<COD>';
                if ($item->parcel->cod->purpose !== null) {
                    $body .= '<Purpose>' . self::xe($item->parcel->cod->purpose) . '</Purpose>';
                }
                $body .= '<Amount>' . self::formatDecimal($item->parcel->cod->amount) . '</Amount>'
                    . '<Payment>' . self::xe($item->parcel->cod->payment->value) . '</Payment>'
                    . '</COD>';
            }

            $body .= '</ParcelData>'
                . '</OrderData>';
        }

        $body .= '</OrderDataList>'
            . '</setOrderRequest>';

        return $this->envelope('setOrder', $body);
    }

    public function buildGetParcelShopFinder(ParcelShopQuery $query): string
    {
        $body = '<getParcelShopFinderRequest>'
            . $this->credentialsXml()
            . '<MaxReturnValues>' . $query->maxReturnValues . '</MaxReturnValues>'
            . '<SearchMode>' . self::xe($query->searchMode->value) . '</SearchMode>';

        if ($query->searchAddress !== null) {
            $sa = $query->searchAddress;
            $body .= '<SearchAddress>';
            if ($sa->street !== null) {
                $body .= '<Street>' . self::xe($sa->street) . '</Street>';
            }
            if ($sa->houseNo !== null) {
                $body .= '<HouseNo>' . self::xe($sa->houseNo) . '</HouseNo>';
            }
            if ($sa->zipCode !== null) {
                $body .= '<ZipCode>' . self::xe($sa->zipCode) . '</ZipCode>';
            }
            if ($sa->city !== null) {
                $body .= '<City>' . self::xe($sa->city) . '</City>';
            }
            if ($sa->country !== null) {
                $body .= '<Country>' . self::xe($sa->country) . '</Country>';
            }
            $body .= '</SearchAddress>';
        }

        if ($query->searchGeoData !== null) {
            $body .= '<SearchGeoData>'
                . '<Longitude>' . self::formatDecimal($query->searchGeoData->longitude) . '</Longitude>'
                . '<Latitude>' . self::formatDecimal($query->searchGeoData->latitude) . '</Latitude>'
                . '</SearchGeoData>';
        }

        if ($query->hideOnClosedAt !== null) {
            $body .= '<HideOnClosedAt>' . self::xe($query->hideOnClosedAt) . '</HideOnClosedAt>';
        }

        $body .= '<NeedService>' . self::xe($query->needService->value) . '</NeedService>'
            . '</getParcelShopFinderRequest>';

        return $this->envelope('getParcelShopFinder', $body);
    }

    public function buildGetParcelLifeCycle(string $parcelNo): string
    {
        $body = '<getParcelLifeCycleRequest>'
            . $this->credentialsXml()
            . '<ParcelNo>' . self::xe($parcelNo) . '</ParcelNo>'
            . '</getParcelLifeCycleRequest>';

        return $this->envelope('getParcelLifeCycle', $body);
    }

    public function buildGetOrderStatus(string $parcelNo, ?string $deliveryZipCode = null): string
    {
        $body = '<getOrderStatusRequest>'
            . $this->credentialsXml()
            . '<ParcelNo>' . self::xe($parcelNo) . '</ParcelNo>';

        if ($deliveryZipCode !== null) {
            $body .= '<DeliveryZipCode>' . self::xe($deliveryZipCode) . '</DeliveryZipCode>';
        }

        $body .= '</getOrderStatusRequest>';

        return $this->envelope('getOrderStatus', $body);
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    private function envelope(string $operation, string $requestXml): string
    {
        // The default xmlns declared on the outer <operation> element applies to all unprefixed
        // descendant elements (incl. <operationRequest> and everything inside it), which is
        // exactly what elementFormDefault="qualified" requires; no per-element prefixes are needed.
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope'
            . ' xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body>'
            . '<' . $operation . ' xmlns="' . self::NS . '">'
            . $requestXml
            . '</' . $operation . '>'
            . '</soap:Body>'
            . '</soap:Envelope>';
    }

    private function credentialsXml(): string
    {
        return '<Version>' . DpdCloudConfig::API_VERSION . '</Version>'
            . '<Language>' . self::xe($this->config->language) . '</Language>'
            . '<PartnerCredentials>'
                . '<Name>' . self::xe($this->config->partnerName) . '</Name>'
                . '<Token>' . self::xe($this->config->partnerToken) . '</Token>'
            . '</PartnerCredentials>'
            . '<UserCredentials>'
                . '<cloudUserID>' . $this->config->userId . '</cloudUserID>'
                . '<Token>' . self::xe($this->config->userToken) . '</Token>'
            . '</UserCredentials>';
    }

    private function addressXml(Address $addr, string $tag): string
    {
        $xml = "<{$tag}>";

        if ($addr->company !== null) {
            $xml .= '<Company>' . self::xe($addr->company) . '</Company>';
        }
        if ($addr->salutation !== null) {
            $xml .= '<Salutation>' . self::xe($addr->salutation) . '</Salutation>';
        }
        if ($addr->name !== null) {
            $xml .= '<Name>' . self::xe($addr->name) . '</Name>';
        }
        if ($addr->firstName !== null) {
            $xml .= '<FirstName>' . self::xe($addr->firstName) . '</FirstName>';
        }
        if ($addr->lastName !== null) {
            $xml .= '<LastName>' . self::xe($addr->lastName) . '</LastName>';
        }
        if ($addr->street !== null) {
            $xml .= '<Street>' . self::xe($addr->street) . '</Street>';
        }
        if ($addr->houseNo !== null) {
            $xml .= '<HouseNo>' . self::xe($addr->houseNo) . '</HouseNo>';
        }
        if ($addr->country !== null) {
            $xml .= '<Country>' . self::xe($addr->getCountryCode() ?? '') . '</Country>';
        }
        if ($addr->zipCode !== null) {
            $xml .= '<ZipCode>' . self::xe($addr->zipCode) . '</ZipCode>';
        }
        if ($addr->city !== null) {
            $xml .= '<City>' . self::xe($addr->city) . '</City>';
        }
        if ($addr->state !== null) {
            $xml .= '<State>' . self::xe($addr->state) . '</State>';
        }
        if ($addr->phone !== null) {
            $xml .= '<Phone>' . self::xe($addr->phone) . '</Phone>';
        }
        if ($addr->mail !== null) {
            $xml .= '<Mail>' . self::xe($addr->mail) . '</Mail>';
        }
        if ($addr->gender !== null) {
            $xml .= '<Gender>' . self::xe($addr->gender) . '</Gender>';
        }

        $xml .= "</{$tag}>";
        return $xml;
    }

    private static function formatDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function xe(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
