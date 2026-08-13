<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/** `ParcelStationType`: whether a ParcelShop is (also) a self-service parcel locker. */
enum ParcelStationType: string
{
    case None      = 'no_parcelstation';
    case Standard  = 'parcelstation';
    case Myflexbox = 'myflexbox';
}
