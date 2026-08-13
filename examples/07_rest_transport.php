<?php

/**
 * Example 07. Using the REST transport instead of SOAP.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - switching the whole client to REST with a single constructor argument
 *   - that both transports return the exact same DTOs
 *   - the one operation that always stays on SOAP (fetchParcelLifeCycle)
 *   - reading DPD's SystemInformation service message
 *
 * SOAP is the default and is what you should use unless you have a reason not to;
 * REST is useful when your infrastructure cannot post XML or you want smaller payloads.
 *
 * Run:
 *   DPD_CLOUD_PARTNER_NAME="DPD Sandbox" \
 *   DPD_CLOUD_PARTNER_TOKEN=xxx DPD_CLOUD_USER_ID=xxx DPD_CLOUD_USER_TOKEN=xxx \
 *     php examples/07_rest_transport.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SearchGeoData;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\DpdCloudConfig;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Enum\TransportMode;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;

$config = DpdCloudConfig::sandbox(
    partnerName:  getenv('DPD_CLOUD_PARTNER_NAME')  ?: 'DPD Sandbox',
    partnerToken: getenv('DPD_CLOUD_PARTNER_TOKEN') ?: 'your-partner-token',
    userId:       (int) (getenv('DPD_CLOUD_USER_ID') ?: 123456),
    userToken:    getenv('DPD_CLOUD_USER_TOKEN')    ?: 'your-user-token',
);

// The only difference from every other example: TransportMode::Rest.
$rest = new DpdCloudClient($config, TransportMode::Rest);
$soap = new DpdCloudClient($config, TransportMode::Soap);

// ---------------------------------------------------------------------------
// 1. The same call over both transports returns identical data.
// ---------------------------------------------------------------------------
try {
    $viaRest = $rest->fetchZipCodeRules();
    $viaSoap = $soap->fetchZipCodeRules();
} catch (DpdCloudException $e) {
    fwrite(STDERR, "getZipCodeRules failed: {$e->getMessage()}\n");
    exit(1);
}

echo "getZipCodeRules\n";
printf("  REST: %s %s, depot %s, classic cut-off %s\n", $viaRest->country, $viaRest->zipCode, $viaRest->pickupDepot, $viaRest->classicCutOff);
printf("  SOAP: %s %s, depot %s, classic cut-off %s\n", $viaSoap->country, $viaSoap->zipCode, $viaSoap->pickupDepot, $viaSoap->classicCutOff);
printf("  identical: %s\n", $viaRest == $viaSoap ? 'yes' : 'no');

// ---------------------------------------------------------------------------
// 2. Geo search over REST. Note the different URL layout DPD uses per search mode -
//    the client builds those paths for you.
// ---------------------------------------------------------------------------
try {
    $shops = $rest->findParcelShops(
        ParcelShopQuery::byGeoData(new SearchGeoData(longitude: 9.0960013, latitude: 49.9447), maxReturnValues: 3)
    );
} catch (DpdCloudException $e) {
    fwrite(STDERR, "findParcelShops failed: {$e->getMessage()}\n");
    exit(1);
}

echo "\nfindParcelShops (geo search, REST): " . count($shops) . " result(s)\n";
foreach ($shops as $shop) {
    printf("  #%d %s (%.1f km)\n", $shop->parcelShopId, $shop->shopAddress?->city ?? '-', $shop->geoData?->distance ?? 0.0);
}

// ---------------------------------------------------------------------------
// 3. setOrder over REST. checkOrderData validates server-side without producing
//    a label, so it is the safe way to try this out.
// ---------------------------------------------------------------------------
$item = new OrderItem(
    shipAddress: new Address(
        name:    'Max Mustermann',
        street:  'Musterstr.',
        houseNo: '1',
        country: 'DE',
        zipCode: '10115',
        city:    'Berlin',
    ),
    parcelShopId: 0,
    parcel: new Parcel(
        shipService:    ShipService::Classic,
        weightKg:       2.5,
        content:        'Books',
        yourInternalId: 'REST-' . date('YmdHis'),
        reference1:     'REST transport demo',
    ),
);

try {
    $rest->checkOrderData([$item]);
    echo "\ncheckOrderData over REST: accepted\n";
} catch (DpdCloudException $e) {
    fwrite(STDERR, "checkOrderData failed: {$e->getMessage()}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 4. DPD attaches a free-text SystemInformation to every response (maintenance
//    windows, upcoming API changes). It is also logged at notice level.
// ---------------------------------------------------------------------------
echo 'SystemInformation: ' . ($rest->lastSystemInformation() ?? '(none)') . "\n";

// ---------------------------------------------------------------------------
// 5. fetchParcelLifeCycle() ignores TransportMode and always uses SOAP; its
//    deeply nested response has no REST parser in this library.
// ---------------------------------------------------------------------------
try {
    $rest->fetchParcelLifeCycle('09980000000000');
} catch (DpdCloudException $e) {
    echo "\nfetchParcelLifeCycle still went over SOAP and answered: {$e->getMessage()}\n";
}
