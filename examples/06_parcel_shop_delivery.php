<?php

/**
 * Example 06. Ship to a Pickup ParcelShop (Shop_Delivery).
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - finding a nearby ParcelShop and picking one that offers the service you need
 *   - reading opening hours, holidays and the Express cut-off of a shop
 *   - creating a Shop_Delivery order, which REQUIRES a ParcelShopID
 *   - telling parcel lockers (DPD Paketstation / myflexbox) apart from staffed shops
 *
 * Run:
 *   DPD_CLOUD_PARTNER_NAME="DPD Sandbox" \
 *   DPD_CLOUD_PARTNER_TOKEN=xxx DPD_CLOUD_USER_ID=xxx DPD_CLOUD_USER_TOKEN=xxx \
 *     php examples/06_parcel_shop_delivery.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SearchAddress;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\Enum\NeedService;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Enum\ShopService;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;

$client = DpdCloudClient::sandbox(
    partnerName:  getenv('DPD_CLOUD_PARTNER_NAME')  ?: 'DPD Sandbox',
    partnerToken: getenv('DPD_CLOUD_PARTNER_TOKEN') ?: 'your-partner-token',
    userId:       (int) (getenv('DPD_CLOUD_USER_ID') ?: 123456),
    userToken:    getenv('DPD_CLOUD_USER_TOKEN')    ?: 'your-user-token',
);

// ---------------------------------------------------------------------------
// 1. Find shops near the recipient. NeedService filters by what the shop offers -
//    Standard is enough for a plain Shop_Delivery.
// ---------------------------------------------------------------------------
$recipientAddress = new SearchAddress(
    street:  'Luitpoldstr.',
    houseNo: '3',
    zipCode: '97318',
    city:    'Kitzingen',
    country: 'DEU',
);

try {
    $shops = $client->findParcelShops(
        ParcelShopQuery::byAddress($recipientAddress, maxReturnValues: 5, needService: NeedService::Standard)
    );
} catch (DpdCloudException $e) {
    fwrite(STDERR, "ParcelShop lookup failed: {$e->getMessage()}\n");
    exit(1);
}

if ($shops === []) {
    fwrite(STDERR, "No ParcelShops found near that address.\n");
    exit(1);
}

echo 'Found ' . count($shops) . " ParcelShop(s):\n";
foreach ($shops as $shop) {
    $addr = $shop->shopAddress;
    $kind = $shop->isParcelLocker() ? 'locker' : 'staffed shop';

    printf(
        "  #%d  %-32s %s %s, %s %s  [%s]\n",
        $shop->parcelShopId,
        $addr?->company ?? $addr?->name ?? '-',
        $addr?->street ?? '',
        $addr?->houseNo ?? '',
        $addr?->zipCode ?? '',
        $addr?->city ?? '',
        $kind,
    );

    if ($shop->geoData !== null) {
        printf("       %.1f km away (%.5f, %.5f)\n", $shop->geoData->distance, $shop->geoData->latitude, $shop->geoData->longitude);
    }
    if ($shop->expressCutOff !== null && $shop->expressCutOff !== '') {
        echo "       Express cut-off: {$shop->expressCutOff}\n";
    }

    // ShopServiceList tells you what the shop can actually do.
    $services = array_map(static fn (ShopService $s): string => $s->value, $shop->shopServiceList);
    if ($services !== []) {
        echo '       Services: ' . implode(', ', $services) . "\n";
    }

    foreach ($shop->openingHoursList as $day) {
        $slots = array_map(
            static fn ($slot): string => trim(($slot->timeFrom ?? '') . '-' . ($slot->timeEnd ?? '')),
            $day->openTimeList,
        );
        if ($slots !== []) {
            printf("       %-10s %s\n", $day->weekDay ?? '?', implode(', ', $slots));
        }
    }

    foreach ($shop->holidayList as $holiday) {
        echo '       Closed: ' . $holiday->holidayFrom->format('d.m.Y') . ' to ' . $holiday->holidayEnd->format('d.m.Y') . "\n";
    }
}

// ---------------------------------------------------------------------------
// 2. Pick one and ship to it. Two things matter for Shop_Delivery:
//      - ShipService::ShopDelivery
//      - parcelShopId set to the chosen shop (0 is rejected: CLOUD_API_ORDER_PARCELSHOP)
//    The ship address stays the *recipient's* address. DPD prints the shop address
//    on the label with a "zu Händen <recipient>" note.
// ---------------------------------------------------------------------------
$chosen = $shops[0];

$item = new OrderItem(
    shipAddress: new Address(
        name:    'Max Mustermann',
        street:  'Luitpoldstr.',
        houseNo: '3',
        country: 'DE',
        zipCode: '97318',
        city:    'Kitzingen',
        mail:    'max.mustermann@example.com',
    ),
    parcelShopId: $chosen->parcelShopId,
    parcel: new Parcel(
        shipService:    ShipService::ShopDelivery,
        weightKg:       1.2,
        content:        'Books',
        yourInternalId: 'SHOP-' . date('YmdHis'),
        reference1:     'Shop delivery demo',
    ),
);

// Local validation catches a missing ParcelShopID before any network call.
$localErrors = $client->validateLocally([$item]);
if ($localErrors !== []) {
    fwrite(STDERR, "Local validation failed:\n  - " . implode("\n  - ", $localErrors) . "\n");
    exit(1);
}

try {
    $result = $client->createShipment($item);
} catch (DpdCloudException $e) {
    fwrite(STDERR, "Shipment failed: {$e->getMessage()}\n");
    exit(1);
}

echo "\nShipped to ParcelShop #{$chosen->parcelShopId}\n";
echo "Parcel number: {$result->firstParcelNo()}\n";

$labelPath = sys_get_temp_dir() . '/dpd-shop-delivery-label.pdf';
file_put_contents($labelPath, $result->labelPdf);
echo "Label saved to: {$labelPath}\n";
