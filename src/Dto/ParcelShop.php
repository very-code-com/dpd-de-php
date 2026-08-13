<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

use VeryCodeCom\DpdDe\Enum\ParcelStationType;
use VeryCodeCom\DpdDe\Enum\ShopService;

/** `ParcelShopType`: a single pickup point returned by getParcelShopFinder. */
final class ParcelShop
{
    /**
     * @param list<ShopService> $shopServiceList
     * @param list<OpeningHours> $openingHoursList
     * @param list<Holiday> $holidayList
     */
    public function __construct(
        public readonly int $parcelShopId,
        public readonly ?Address $shopAddress,
        public readonly ?string $homepage,
        public readonly ?GeoData $geoData,
        public readonly ?string $expressCutOff,
        public readonly array $shopServiceList,
        public readonly array $openingHoursList,
        public readonly array $holidayList,
        public readonly ?string $extraInfo,
        public readonly ?string $serviceDetail,
        public readonly ?string $customerNo,
        public readonly ParcelStationType $parcelStation,
    ) {
    }

    public function isParcelLocker(): bool
    {
        return $this->parcelStation !== ParcelStationType::None;
    }
}
