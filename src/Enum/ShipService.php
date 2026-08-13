<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/**
 * DPD Cloud Service shipping products (`ShipServiceType` in the WSDL).
 *
 * Notes from the official documentation:
 *  - Express_830 .. Express_18 (and their COD variants) are Germany-domestic only
 *    (see {@see \VeryCodeCom\DpdDe\Exception\DpdCloudApiException} code
 *    CLOUD_API_ORDER_EXPRESS_DEU_COUNTRY).
 *  - For international express shipments use Express_International (no customs data supported).
 *  - All `*_COD` variants are effectively deprecated: DPD fully discontinued the "Nachnahme"
 *    (cash on delivery) service on 11.05.2020. They remain here 1:1 with the WSDL for completeness.
 *  - Packages under 3kg are automatically handled as "Kleinpaket" by DPD; no explicit flag needed.
 */
enum ShipService: string
{
    case Classic                 = 'Classic';
    /** @deprecated DPD discontinued Nachnahme (COD) on 2020-05-11; kept for WSDL compatibility. */
    case ClassicCod               = 'Classic_COD';
    case ClassicPredict            = 'Classic_Predict';
    /** @deprecated DPD discontinued Nachnahme (COD) on 2020-05-11; kept for WSDL compatibility. */
    case ClassicCodPredict         = 'Classic_COD_Predict';
    case ClassicReturn             = 'Classic_Return';
    case ShopDelivery              = 'Shop_Delivery';
    case ShopReturn                = 'Shop_Return';
    case Express830                = 'Express_830';
    /** @deprecated DPD discontinued Nachnahme (COD) on 2020-05-11; kept for WSDL compatibility. */
    case Express830Cod             = 'Express_830_COD';
    case Express10                 = 'Express_10';
    /** @deprecated DPD discontinued Nachnahme (COD) on 2020-05-11; kept for WSDL compatibility. */
    case Express10Cod              = 'Express_10_COD';
    case Express12                 = 'Express_12';
    /** @deprecated DPD discontinued Nachnahme (COD) on 2020-05-11; kept for WSDL compatibility. */
    case Express12Cod              = 'Express_12_COD';
    case Express18                 = 'Express_18';
    /** @deprecated DPD discontinued Nachnahme (COD) on 2020-05-11; kept for WSDL compatibility. */
    case Express18Cod              = 'Express_18_COD';
    case Express12Saturday         = 'Express_12_Saturday';
    /** @deprecated DPD discontinued Nachnahme (COD) on 2020-05-11; kept for WSDL compatibility. */
    case Express12CodSaturday      = 'Express_12_COD_Saturday';
    case ExpressInternational      = 'Express_International';

    /** Whether this product is one of the (deprecated) cash-on-delivery variants. */
    public function isCod(): bool
    {
        return str_contains($this->value, 'COD');
    }

    /** Whether this product is restricted to Germany-domestic shipments only. */
    public function isDomesticExpressOnly(): bool
    {
        return str_starts_with($this->value, 'Express_')
            && $this !== self::ExpressInternational;
    }

    /**
     * Whether this is a Predict product, which requires an e-mail address or a mobile number on
     * the ship address (CLOUD_ADDRESS_NEEDMAILORSMS).
     */
    public function isPredict(): bool
    {
        return str_contains($this->value, 'Predict');
    }

    /** Whether this is a return product (Classic_Return / Shop_Return). */
    public function isReturn(): bool
    {
        return $this === self::ClassicReturn || $this === self::ShopReturn;
    }

    /**
     * Whether a ParcelShop pickup point must be addressed for this product
     * (CLOUD_API_ORDER_PARCELSHOP).
     */
    public function requiresParcelShopId(): bool
    {
        return $this === self::ShopDelivery;
    }
}
