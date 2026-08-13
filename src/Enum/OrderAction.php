<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/**
 * `OrderActionType`: whether setOrder should actually create the shipment or only validate it.
 */
enum OrderAction: string
{
    /** Start the shipment for real: DPD creates the parcel and returns a label. */
    case StartOrder = 'startOrder';

    /** Pre-flight: validate the order data without creating a real shipment / consuming a parcel number. */
    case CheckOrderData = 'checkOrderData';
}
