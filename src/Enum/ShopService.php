<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/**
 * `ShopServiceType`: services a specific ParcelShop offers, as returned in
 * getParcelShopFinder responses (`ShopServiceList`).
 */
enum ShopService: string
{
    case SearchAll                     = 'SearchAll';
    case PickupByConsignee              = 'PickupByConsignee';
    case ReturnService                  = 'ReturnService';
    case ExpressService                 = 'ExpressService';
    case PrepaidService                 = 'PrepaidService';
    case CashOnDeliveryCash              = 'CashOnDelivery_Cash';
    case CashOnDeliveryCheque            = 'CashOnDelivery_Cheque';
    case CashOnDeliveryCreditCard        = 'CashOnDelivery_CreditCard';
    case PayInShopService                = 'PayInShopService';
    case ShopIdentService                = 'ShopIdentService';
    case VoucherService                  = 'VoucherService';
    case Paketstation                    = 'Paketstation';
    case PaketstationPickupService       = 'Paketstation_PickupService';
    case PaketstationParcelLockService   = 'Paketstation_ParcelLockService';
}
