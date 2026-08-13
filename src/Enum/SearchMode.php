<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/** `SearchModeType`: how getParcelShopFinder should search for pickup points. */
enum SearchMode: string
{
    case SearchByAddress = 'SearchByAddress';
    case SearchByGeoData = 'SearchByGeoData';
}
