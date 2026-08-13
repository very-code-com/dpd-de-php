<?php

/**
 * Example 04. Finding ParcelShop pickup points + fetching account pickup rules.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - findParcelShops() by address AND by geo-coordinates
 *   - fetchZipCodeRules() for your own account's pickup address
 *
 * Run:
 *   DPD_CLOUD_PARTNER_NAME="DPD Cloud Service Alpha2" \
 *   DPD_CLOUD_PARTNER_TOKEN=xxx DPD_CLOUD_USER_ID=123456 DPD_CLOUD_USER_TOKEN=xxx \
 *     php examples/04_parcel_shops_and_zip_rules.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SearchAddress;
use VeryCodeCom\DpdDe\Dto\SearchGeoData;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;

$client = DpdCloudClient::sandbox(
    partnerName:  getenv('DPD_CLOUD_PARTNER_NAME')  ?: 'DPD Cloud Service Alpha2',
    partnerToken: getenv('DPD_CLOUD_PARTNER_TOKEN') ?: 'your-partner-token',
    userId:       (int) (getenv('DPD_CLOUD_USER_ID') ?: 123456),
    userToken:    getenv('DPD_CLOUD_USER_TOKEN')    ?: 'your-user-token',
);

try {
    // -------------------------------------------------------------------
    // 1. Search by address.
    // -------------------------------------------------------------------
    echo "=== ParcelShops near Berlin 10115 (by address) ===\n";
    $shops = $client->findParcelShops(
        ParcelShopQuery::byAddress(
            new SearchAddress(zipCode: '10115', city: 'Berlin', country: 'DE'),
            maxReturnValues: 5,
        )
    );

    foreach ($shops as $shop) {
        $locker = $shop->isParcelLocker() ? ' [Parcel Locker]' : '';
        echo "  #{$shop->parcelShopId}{$locker}: {$shop->shopAddress?->company}, "
            . "{$shop->shopAddress?->street} {$shop->shopAddress?->houseNo}, "
            . "{$shop->shopAddress?->zipCode} {$shop->shopAddress?->city}\n";
    }

    // -------------------------------------------------------------------
    // 2. Search by geo-coordinates (Brandenburg Gate, Berlin).
    // -------------------------------------------------------------------
    echo "\n=== ParcelShops near 52.5163,13.3777 (by geo-coordinates) ===\n";
    $shopsByGeo = $client->findParcelShops(
        ParcelShopQuery::byGeoData(new SearchGeoData(longitude: 13.3777, latitude: 52.5163), maxReturnValues: 5)
    );

    foreach ($shopsByGeo as $shop) {
        $distance = $shop->geoData?->distance ?? 0.0;
        echo "  #{$shop->parcelShopId}: {$distance} km away\n";
    }

    // -------------------------------------------------------------------
    // 3. Fetch pickup rules for your own account.
    // -------------------------------------------------------------------
    echo "\n=== Account pickup rules (getZipCodeRules) ===\n";
    $rules = $client->fetchZipCodeRules();

    echo "Country: {$rules->country}, ZIP: {$rules->zipCode}\n";
    echo "Express cut-off: {$rules->expressCutOff}, Classic cut-off: {$rules->classicCutOff}\n";
    echo "Pickup depot: {$rules->pickupDepot}\n";

    $noPickupDays = $rules->getNoPickupDaysList();
    if ($noPickupDays !== []) {
        echo "No-pickup days:\n";
        foreach ($noPickupDays as $date) {
            echo '  - ' . $date->format('Y-m-d') . "\n";
        }
    }
} catch (DpdCloudException $e) {
    echo "DPD Cloud Service error: {$e->getMessage()}\n";
}
