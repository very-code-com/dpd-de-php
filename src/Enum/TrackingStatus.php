<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/**
 * DPD Parcel Life Cycle `status` values, as used in getParcelLifeCycle's `statusInfo[].status`.
 * Note: getOrderStatus uses free-form `StatusID` strings instead (see {@see \VeryCodeCom\DpdDe\Dto\StatusInfo}).
 */
enum TrackingStatus: string
{
    case Shipment        = 'SHIPMENT';
    case Accepted        = 'ACCEPTED';
    case AtSendingDepot  = 'AT_SENDING_DEPOT';
    case OnTheRoad       = 'ON_THE_ROAD';
    case AtDeliveryDepot = 'AT_DELIVERY_DEPOT';
    case Delivered       = 'DELIVERED';
}
