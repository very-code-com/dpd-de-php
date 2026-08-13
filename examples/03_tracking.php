<?php

/**
 * Example 03. Tracking: getParcelLifeCycle vs. getOrderStatus.
 * ---------------------------------------------------------------------------
 * Demonstrates the two DPD tracking models side by side:
 *   - fetchParcelLifeCycle(): older, UI-rendering-oriented model with
 *     pre-formatted ContentLine/ContentItem text blocks (bold/paragraph flags)
 *   - fetchOrderStatus(): newer, structured model with named milestones
 *     (Start / OnTheRoad / DeliveryDepot / CarLoad / Delivered)
 *
 * Run:
 *   DPD_CLOUD_PARTNER_NAME="DPD Cloud Service Alpha2" \
 *   DPD_CLOUD_PARTNER_TOKEN=xxx DPD_CLOUD_USER_ID=123456 DPD_CLOUD_USER_TOKEN=xxx \
 *     php examples/03_tracking.php 01234567890123
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;

$parcelNo = $argv[1] ?? '01234567890123';

$client = DpdCloudClient::sandbox(
    partnerName:  getenv('DPD_CLOUD_PARTNER_NAME')  ?: 'DPD Cloud Service Alpha2',
    partnerToken: getenv('DPD_CLOUD_PARTNER_TOKEN') ?: 'your-partner-token',
    userId:       (int) (getenv('DPD_CLOUD_USER_ID') ?: 123456),
    userToken:    getenv('DPD_CLOUD_USER_TOKEN')    ?: 'your-user-token',
);

// ---------------------------------------------------------------------------
// 1. getParcelLifeCycle, "Parcel Life Cycle Service 2.0" (UI-oriented model).
// ---------------------------------------------------------------------------
try {
    $tracking = $client->fetchParcelLifeCycle($parcelNo);

    echo "=== getParcelLifeCycle ===\n";
    if ($tracking->shipmentInfo !== null) {
        echo 'Status: ' . $tracking->shipmentInfo->status->value . "\n";
        echo 'Headline: ' . ($tracking->shipmentInfo->label?->content ?? '-') . "\n";
        echo 'Description: ' . ($tracking->shipmentInfo->description?->getText() ?? '-') . "\n";
        echo 'Reached: ' . ($tracking->shipmentInfo->statusHasBeenReached ? 'yes' : 'no') . "\n";
    }
    foreach ($tracking->statusInfo as $milestone) {
        $marker = $milestone->isCurrentStatus ? '*' : ' ';
        echo "  [{$marker}] {$milestone->status->value}\n";
    }
} catch (DpdCloudApiException $e) {
    echo "getParcelLifeCycle failed: {$e->getMessage()}\n";
} catch (DpdCloudException $e) {
    echo "getParcelLifeCycle error: {$e->getMessage()}\n";
}

echo "\n";

// ---------------------------------------------------------------------------
// 2. getOrderStatus, "Parcel Life Cycle Service 3.1" (structured model).
//    Pass deliveryZipCode to receive full (non-anonymised) tracking data.
// ---------------------------------------------------------------------------
try {
    $status = $client->fetchOrderStatus($parcelNo, deliveryZipCode: '10115');

    echo "=== getOrderStatus ===\n";
    if ($status->orderInformation !== null) {
        echo "Product: {$status->orderInformation->productName}\n";
        echo "Weight: {$status->orderInformation->weight} kg\n";
        echo 'Complete delivery: ' . ($status->orderInformation->completeDelivery ? 'yes' : 'no') . "\n";
    }
    if ($status->lastStatusInfo !== null) {
        echo "Last status: {$status->lastStatusInfo->statusId}, {$status->lastStatusInfo->headline}\n";
    }
    if ($status->statusInfoContainer !== null) {
        $milestones = [
            'Start' => $status->statusInfoContainer->start,
            'OnTheRoad' => $status->statusInfoContainer->onTheRoad,
            'DeliveryDepot' => $status->statusInfoContainer->deliveryDepot,
            'CarLoad' => $status->statusInfoContainer->carLoad,
            'Delivered' => $status->statusInfoContainer->delivered,
        ];
        foreach ($milestones as $name => $detail) {
            $reached = $detail?->statusReached ? 'reached' : 'pending';
            echo "  {$name}: {$reached}\n";
        }
    }
} catch (DpdCloudApiException $e) {
    echo "getOrderStatus failed: {$e->getMessage()}\n";
} catch (DpdCloudException $e) {
    echo "getOrderStatus error: {$e->getMessage()}\n";
}
