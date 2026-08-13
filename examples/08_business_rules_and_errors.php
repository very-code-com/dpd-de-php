<?php

/**
 * Example 08. Business rules and error handling.
 * ---------------------------------------------------------------------------
 * Demonstrates every rule the local validator enforces before a request leaves your
 * server, and how DPD's own errors surface as typed exceptions. Runs entirely offline
 * except for the last section.
 *
 * Run:
 *   php examples/08_business_rules_and_errors.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\Cod;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\Enum\PaymentType;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;
use VeryCodeCom\DpdDe\Exception\DpdCloudAuthException;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;
use VeryCodeCom\DpdDe\Exception\DpdCloudRateLimitException;
use VeryCodeCom\DpdDe\Exception\DpdCloudTransportException;
use VeryCodeCom\DpdDe\Exception\DpdCloudValidationException;

$client = DpdCloudClient::sandbox(
    partnerName:  getenv('DPD_CLOUD_PARTNER_NAME')  ?: 'DPD Sandbox',
    partnerToken: getenv('DPD_CLOUD_PARTNER_TOKEN') ?: 'your-partner-token',
    userId:       (int) (getenv('DPD_CLOUD_USER_ID') ?: 123456),
    userToken:    getenv('DPD_CLOUD_USER_TOKEN')    ?: 'your-user-token',
);

$germanAddress = static fn (?string $state = null, ?string $phone = null, ?string $mail = null): Address => new Address(
    name:    'Max Mustermann',
    street:  'Musterstr.',
    houseNo: '1',
    country: 'DE',
    zipCode: '10115',
    city:    'Berlin',
    state:   $state,
    phone:   $phone,
    mail:    $mail,
);

$parcel = static fn (ShipService $service, ?Cod $cod = null): Parcel => new Parcel(
    shipService:    $service,
    weightKg:       2.5,
    content:        'Books',
    yourInternalId: 'DEMO-1',
    reference1:     'REF-1',
    cod:            $cod,
);

/** Print what validateLocally() says about a single item, no network involved. */
$check = static function (string $title, OrderItem $item) use ($client): void {
    $errors = $client->validateLocally([$item]);

    echo "\n{$title}\n";
    if ($errors === []) {
        echo "  valid\n";
        return;
    }
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
};

echo "=== Local business rules (no network calls) ===\n";

// 1. Content / YourInternalID / Reference1 look optional in the WSDL but DPD rejects
//    orders without them (2125 / 2122 / 2123).
$check('Missing mandatory parcel fields', new OrderItem(
    shipAddress: $germanAddress(),
    parcelShopId: 0,
    parcel: new Parcel(ShipService::Classic, weightKg: 2.5),
));

// 2. State is mandatory for USA/Canada and forbidden everywhere else.
$check('US address without a state', new OrderItem(
    shipAddress: new Address(name: 'John Doe', street: 'Main St', houseNo: '1', country: 'USA', zipCode: '10001', city: 'New York'),
    parcelShopId: 0,
    parcel: $parcel(ShipService::Classic),
));

$check('German address with a state', new OrderItem(
    shipAddress: $germanAddress(state: 'BE'),
    parcelShopId: 0,
    parcel: $parcel(ShipService::Classic),
));

// 3. Predict needs a way to notify the recipient.
$check('Predict without e-mail or phone', new OrderItem(
    shipAddress: $germanAddress(),
    parcelShopId: 0,
    parcel: $parcel(ShipService::ClassicPredict),
));

$check('Predict with an e-mail address', new OrderItem(
    shipAddress: $germanAddress(mail: 'max@example.com'),
    parcelShopId: 0,
    parcel: $parcel(ShipService::ClassicPredict),
));

// 4. Shop_Delivery must address a ParcelShop.
$check('Shop_Delivery without a ParcelShopID', new OrderItem(
    shipAddress: $germanAddress(),
    parcelShopId: 0,
    parcel: $parcel(ShipService::ShopDelivery),
));

// 5. Express 8:30-18:00 is Germany-only; use Express_International abroad.
$check('Express_12 to Austria', new OrderItem(
    shipAddress: new Address(name: 'Max Mustermann', street: 'Hauptstrasse', houseNo: '1', country: 'AT', zipCode: '1010', city: 'Wien'),
    parcelShopId: 0,
    parcel: $parcel(ShipService::Express12),
));

// 6. COD (deprecated by DPD, still validated): 1-5000 EUR, cash capped at 2500.
$check('COD of 3000 EUR in cash', new OrderItem(
    shipAddress: $germanAddress(),
    parcelShopId: 0,
    parcel: $parcel(ShipService::ClassicCod, new Cod(amount: 3000.0, payment: PaymentType::Cash)),
));

$check('COD of 3000 EUR by cheque', new OrderItem(
    shipAddress: $germanAddress(),
    parcelShopId: 0,
    parcel: $parcel(ShipService::ClassicCod, new Cod(amount: 3000.0, payment: PaymentType::Cheque)),
));

// 7. Contact detail formats.
$check('Malformed e-mail and phone', new OrderItem(
    shipAddress: $germanAddress(phone: '030-CALL-DPD', mail: 'not-an-email'),
    parcelShopId: 0,
    parcel: $parcel(ShipService::Classic),
));

// 8. Classic_Return needs a phone number and cannot be batched.
echo "\nClassic_Return batched with another parcel\n";
$returnItem = new OrderItem(
    shipAddress: $germanAddress(phone: '+49 30 1234567'),
    parcelShopId: 0,
    parcel: $parcel(ShipService::ClassicReturn),
);
foreach ($client->validateLocally([$returnItem, new OrderItem($germanAddress(), 0, $parcel(ShipService::Classic))]) as $error) {
    echo "  - {$error}\n";
}

// ---------------------------------------------------------------------------
// Exception types: each failure mode has its own class.
// ---------------------------------------------------------------------------
echo "\n=== Exception handling ===\n";

try {
    // Fails locally: no network call is made at all.
    $client->createShipment(new OrderItem($germanAddress(), 0, new Parcel(ShipService::Classic, weightKg: 99.0)));
} catch (DpdCloudValidationException $e) {
    echo "\nDpdCloudValidationException: caught before any request was sent\n";
    foreach ($e->errors as $error) {
        echo "  - {$error}\n";
    }
}

try {
    // Reaches DPD and is rejected there.
    $client->fetchOrderStatus('not-a-parcel-number');
} catch (DpdCloudRateLimitException $e) {
    // Throttling, not bad credentials: back off instead of asking for new tokens.
    echo "\nRate limited; retry in {$e->retryAfterSeconds()} s\n";
} catch (DpdCloudAuthException $e) {
    echo "\nCredentials rejected [{$e->errorCode}]: {$e->getMessage()}\n";
} catch (DpdCloudApiException $e) {
    echo "\nDpdCloudApiException: {$e->getMessage()}\n";
    echo '  DPD error codes: ' . implode(', ', array_column($e->errors, 'errorCode')) . "\n";
    echo '  is CLOUD_API_PARCELNO_NOT_VALID? ' . ($e->hasCode('CLOUD_API_PARCELNO_NOT_VALID') ? 'yes' : 'no') . "\n";
} catch (DpdCloudTransportException $e) {
    echo "\nNetwork/HTTP problem: {$e->getMessage()}\n";
} catch (DpdCloudException $e) {
    echo "\nOther DPD failure: {$e->getMessage()}\n";
}

echo "\nEvery exception also carries the raw DPD response when debug mode is on:\n";
echo "  new DpdCloudClient(DpdCloudConfig::fromArray([...], debug: true))\n";
echo "  then \$e->getRawResponse() / \$e->getDebugReport()\n";
