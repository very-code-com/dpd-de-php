<?php

/**
 * Example 02. Batch shipment creation + server-side pre-flight validation.
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - createShipments() with several OrderItems in a single setOrder call
 *     (up to 30; DPD Cloud Service has no multi-parcel-shipment support, so each
 *     physical package needs its own OrderItem + ship address)
 *   - checkOrderData() as a true server-side dry run (no label issued, no
 *     parcel number consumed)
 *   - custom OrderSettings (label size/format, start position, ship date)
 *
 * Run:
 *   DPD_CLOUD_PARTNER_NAME="DPD Cloud Service Alpha2" \
 *   DPD_CLOUD_PARTNER_TOKEN=xxx DPD_CLOUD_USER_ID=123456 DPD_CLOUD_USER_TOKEN=xxx \
 *     php examples/02_batch_and_validation.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\OrderSettings;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\Enum\LabelSize;
use VeryCodeCom\DpdDe\Enum\LabelStartPosition;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;

$client = DpdCloudClient::sandbox(
    partnerName:  getenv('DPD_CLOUD_PARTNER_NAME')  ?: 'DPD Cloud Service Alpha2',
    partnerToken: getenv('DPD_CLOUD_PARTNER_TOKEN') ?: 'your-partner-token',
    userId:       (int) (getenv('DPD_CLOUD_USER_ID') ?: 123456),
    userToken:    getenv('DPD_CLOUD_USER_TOKEN')    ?: 'your-user-token',
);

// ---------------------------------------------------------------------------
// 1. Build several OrderItems, each parcel gets its own ship address, even if
//    they share the same recipient (no MPS support in this API).
// ---------------------------------------------------------------------------
$items = [
    new OrderItem(
        shipAddress: new Address(name: 'Max Mustermann', street: 'Musterstraße', houseNo: '1', country: 'DE', zipCode: '10115', city: 'Berlin'),
        parcelShopId: 0,
        parcel: new Parcel(ShipService::Classic, weightKg: 2.5, content: 'Books', yourInternalId: 'BATCH-1-' . date('YmdHis'), reference1: 'Order #1'),
    ),
    new OrderItem(
        shipAddress: new Address(name: 'Max Mustermann', street: 'Musterstraße', houseNo: '1', country: 'DE', zipCode: '10115', city: 'Berlin'),
        parcelShopId: 0,
        parcel: new Parcel(ShipService::Classic, weightKg: 4.0, content: 'Books', yourInternalId: 'BATCH-2-' . date('YmdHis'), reference1: 'Order #2'),
    ),
];

// ---------------------------------------------------------------------------
// 2. Public pre-flight validation with no network call, so you can surface
//    errors in your own UI before ever contacting DPD.
// ---------------------------------------------------------------------------
$localErrors = $client->validateLocally($items);
if ($localErrors !== []) {
    echo "Local validation failed:\n";
    foreach ($localErrors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
echo "Local validation passed.\n";

// ---------------------------------------------------------------------------
// 3. Server-side dry run (OrderAction=checkOrderData): DPD validates the data
//    without creating a real shipment or consuming a parcel number.
// ---------------------------------------------------------------------------
try {
    $client->checkOrderData($items);
    echo "Server-side validation passed (checkOrderData).\n";
} catch (DpdCloudApiException $e) {
    echo "DPD rejected the order data:\n";
    foreach ($e->getFormattedErrors() as $line) {
        echo "  - {$line}\n";
    }
    exit(1);
}

// ---------------------------------------------------------------------------
// 4. Now create the real shipments with custom OrderSettings: PDF/A6 labels
//    starting from the lower-right corner, shipped tomorrow.
// ---------------------------------------------------------------------------
$settings = new OrderSettings(
    shipDate:           new DateTimeImmutable('tomorrow 09:00'),
    labelSize:          LabelSize::PdfA6,
    labelStartPosition: LabelStartPosition::LowerRight,
);

try {
    $result = $client->createShipments($items, $settings);

    echo "Created " . count($result->items) . " shipment(s):\n";
    foreach ($result->items as $orderResult) {
        echo "  - {$orderResult->yourInternalId} -> Parcel No {$orderResult->parcelNo}\n";
    }

    file_put_contents(__DIR__ . '/labels-batch.pdf', $result->labelPdf);
    echo "Combined label PDF saved to: examples/labels-batch.pdf\n";
} catch (DpdCloudException $e) {
    echo "Failed to create shipments: {$e->getMessage()}\n";
}
