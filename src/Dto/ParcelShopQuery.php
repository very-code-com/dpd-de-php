<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

use VeryCodeCom\DpdDe\Enum\NeedService;
use VeryCodeCom\DpdDe\Enum\SearchMode;

/**
 * Request parameters for {@see \VeryCodeCom\DpdDe\DpdCloudClient::findParcelShops()}
 * (`getParcelShopFinderRequestType` in the WSDL).
 *
 * Exactly one of {@see self::$searchAddress} / {@see self::$searchGeoData} must be provided,
 * matching {@see self::$searchMode}.
 */
final class ParcelShopQuery
{
    /** DPD returns at most 100 ParcelShops per call (CLOUD_API_PARCELSHOPFINDER_MAXRETURNVALUES). */
    public const MAX_RETURN_VALUES = 100;

    public function __construct(
        public readonly SearchMode $searchMode,
        public readonly ?SearchAddress $searchAddress = null,
        public readonly ?SearchGeoData $searchGeoData = null,
        public readonly int $maxReturnValues = 10,
        public readonly NeedService $needService = NeedService::Standard,
        /** DPD-documented as a free-form string flag; pass a truthy value (e.g. "true") to hide shops already closed. */
        public readonly ?string $hideOnClosedAt = null,
    ) {
        if ($searchMode === SearchMode::SearchByAddress && $searchAddress === null) {
            throw new \InvalidArgumentException('ParcelShopQuery: searchAddress is required when searchMode is SearchByAddress.');
        }
        if ($searchMode === SearchMode::SearchByGeoData && $searchGeoData === null) {
            throw new \InvalidArgumentException('ParcelShopQuery: searchGeoData is required when searchMode is SearchByGeoData.');
        }
        // CLOUD_API_PARCELSHOPFINDER_MAXRETURNVALUES: DPD returns at most 100 shops per call.
        if ($maxReturnValues < 0 || $maxReturnValues > self::MAX_RETURN_VALUES) {
            throw new \InvalidArgumentException(sprintf(
                'ParcelShopQuery: maxReturnValues must be between 0 and %d, got %d.',
                self::MAX_RETURN_VALUES,
                $maxReturnValues,
            ));
        }
    }

    public static function byAddress(SearchAddress $address, int $maxReturnValues = 10, NeedService $needService = NeedService::Standard): self
    {
        return new self(SearchMode::SearchByAddress, searchAddress: $address, maxReturnValues: $maxReturnValues, needService: $needService);
    }

    public static function byGeoData(SearchGeoData $geoData, int $maxReturnValues = 10, NeedService $needService = NeedService::Standard): self
    {
        return new self(SearchMode::SearchByGeoData, searchGeoData: $geoData, maxReturnValues: $maxReturnValues, needService: $needService);
    }
}
