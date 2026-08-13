<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/**
 * `dpdServiceType`: the service a pickup ParcelShop must offer, used as the mandatory
 * `NeedService` filter in getParcelShopFinder requests.
 */
enum NeedService: string
{
    case Standard              = 'StandardService';
    case ConsigneePickup       = 'ConsigneePickup';
    case ReturnService         = 'ReturnService';
    case ExpressService        = 'ExpressService';
    case PrepaidService        = 'PrepaidService';
    case CashOnDeliveryService = 'CashOnDeliveryService';
}
