<?php

/**
 * Example 01. Create a shipment and download its label.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - building an OrderItem with a fully-populated ship Address
 *   - the DPD Cloud Service credential model (PartnerCredentials + UserCredentials)
 *   - handling every exception type the client can throw
 *   - saving the already-decoded label PDF to disk
 *
 * Run:
 *   DPD_CLOUD_PARTNER_NAME="DPD Cloud Service Alpha2" \
 *   DPD_CLOUD_PARTNER_TOKEN=xxx DPD_CLOUD_USER_ID=123456 DPD_CLOUD_USER_TOKEN=xxx \
 *     php examples/01_create_shipment.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;
use VeryCodeCom\DpdDe\Exception\DpdCloudAuthException;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;
use VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException;
use VeryCodeCom\DpdDe\Exception\DpdCloudTransportException;
use VeryCodeCom\DpdDe\Exception\DpdCloudValidationException;

// ---------------------------------------------------------------------------
// 1. Build the client. ::sandbox() targets DPD's Testsystem
//    (https://cloud-stage.dpd.com); use ::production() for the live service.
//    Note the two separate credential pairs DPD issues:
//      - PartnerCredentials (Name + Token): identifies the software/integration
//      - UserCredentials (cloudUserID + Token): identifies your own DPD account
// ---------------------------------------------------------------------------
$client = DpdCloudClient::sandbox(
    partnerName:  getenv('DPD_CLOUD_PARTNER_NAME')  ?: 'DPD Cloud Service Alpha2',
    partnerToken: getenv('DPD_CLOUD_PARTNER_TOKEN') ?: 'your-partner-token',
    userId:       (int) (getenv('DPD_CLOUD_USER_ID') ?: 123456),
    userToken:    getenv('DPD_CLOUD_USER_TOKEN')    ?: 'your-user-token',
);

// ---------------------------------------------------------------------------
// 2. Describe the recipient. Name (or Company), Street, HouseNo, ZipCode, City
//    and Country are effectively required (enforced by local validation before
//    any network call is made).
// ---------------------------------------------------------------------------
$shipAddress = new Address(
    name:    'Max Mustermann',
    street:  'Musterstraße',
    houseNo: '1',
    country: 'DE',
    zipCode: '10115',
    city:    'Berlin',
    phone:   '+493012345678',
);

// ---------------------------------------------------------------------------
// 3. Describe the parcel. weightKg (0-31.5 kg) plus content, yourInternalId and
//    reference1 are all required by DPD, see the README's "Known API quirks".
//    yourInternalId is echoed back in the result's LabelDataList.
// ---------------------------------------------------------------------------
$item = new OrderItem(
    shipAddress:  $shipAddress,
    parcelShopId: 0, // 0 = classic home delivery (not a ParcelShop pickup)
    parcel:       new Parcel(
        shipService:     ShipService::Classic,
        weightKg:        2.5,
        content:         'Books',
        yourInternalId:  'ORDER-' . date('YmdHis'),
        reference1:      'Order #12345',
    ),
);

// ---------------------------------------------------------------------------
// 4. Send it. Every failure mode has its own exception subtype so you can
//    react precisely; all of them extend DpdCloudException.
// ---------------------------------------------------------------------------
try {
    $result = $client->createShipment($item);

    echo "Shipment created successfully!\n";
    echo "  Parcel No : {$result->firstParcelNo()}\n";

    file_put_contents(__DIR__ . '/label.pdf', $result->labelPdf);
    echo "  Label saved to: examples/label.pdf\n";
} catch (DpdCloudValidationException $e) {
    // Thrown BEFORE any network call when the order violates a local rule.
    echo "Local validation failed:\n";
    foreach ($e->errors as $error) {
        echo "  - {$error}\n";
    }
} catch (DpdCloudAuthException $e) {
    echo "Authentication failed [{$e->errorCode}]: {$e->getMessage()}\n";
    echo "Check your Partner/User credentials.\n";
} catch (DpdCloudApiException $e) {
    // Any other business error returned by DPD, e.g. CLOUD_API_ORDER_WEIGHT.
    echo "DPD rejected the order: {$e->getMessage()}\n";
    foreach ($e->getFormattedErrors() as $line) {
        echo "  - {$line}\n";
    }
} catch (DpdCloudTransportException $e) {
    echo "Network/HTTP problem talking to DPD: {$e->getMessage()}\n";
} catch (DpdCloudResponseParseException $e) {
    echo "DPD returned an unparseable response: {$e->getMessage()}\n";
} catch (DpdCloudException $e) {
    echo "Unexpected DPD error: {$e->getMessage()}\n";
}
