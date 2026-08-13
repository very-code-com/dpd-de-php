<?php

/**
 * Example 05. Dependency injection & testing (no network required).
 * ---------------------------------------------------------------------------
 * Demonstrates:
 *   - swapping in a scripted fake TransportInterface (the exact pattern used
 *     by this library's own tests/Unit/DpdCloudClientTest.php)
 *   - injecting a PSR-3 logger
 *   - switching to the REST transport via TransportMode
 *
 * Run (fully offline, no credentials needed):
 *   php examples/05_di_and_testing.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Log\AbstractLogger;
use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\DpdCloudConfig;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Transport\TransportInterface;
use VeryCodeCom\DpdDe\Transport\TransportRequest;
use VeryCodeCom\DpdDe\Transport\TransportResponse;

// ---------------------------------------------------------------------------
// 1. A minimal fake transport that returns a canned setOrder SOAP response,
//    without ever touching the network. Useful for unit-testing code that
//    depends on DpdCloudClient.
// ---------------------------------------------------------------------------
final class FakeDpdTransport implements TransportInterface
{
    public ?TransportRequest $lastRequest = null;

    public function send(TransportRequest $request): TransportResponse
    {
        $this->lastRequest = $request;

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
              <soap:Body>
                <setOrderResponse xmlns="https://cloud.dpd.com/">
                  <setOrderResult>
                    <Ack>true</Ack>
                    <SystemInformation>OK</SystemInformation>
                    <LabelResponse>
                      <LabelPDF>JVBERi1mYWtl</LabelPDF>
                      <LabelDataList>
                        <LabelData>
                          <YourInternalID>DEMO-1</YourInternalID>
                          <ParcelNo>01234567890123</ParcelNo>
                        </LabelData>
                      </LabelDataList>
                    </LabelResponse>
                  </setOrderResult>
                </setOrderResponse>
              </soap:Body>
            </soap:Envelope>
            XML;

        return new TransportResponse(200, $xml);
    }
}

// ---------------------------------------------------------------------------
// 2. A trivial PSR-3 logger that just echoes to stdout.
// ---------------------------------------------------------------------------
final class EchoLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        echo "[{$level}] {$message}\n";
    }
}

$fakeTransport = new FakeDpdTransport();

$client = new DpdCloudClient(
    config: DpdCloudConfig::sandbox('Test Partner', 'test-token', 1, 'test-token'),
    transport: $fakeTransport,
    logger: new EchoLogger(),
);

$item = new OrderItem(
    shipAddress: new Address(name: 'Max Mustermann', street: 'Musterstr.', houseNo: '1', country: 'DE', zipCode: '12345', city: 'Berlin'),
    parcelShopId: 0,
    parcel: new Parcel(ShipService::Classic, weightKg: 1.5, content: 'Books', yourInternalId: 'DEMO-1', reference1: 'DEMO-REF-1'),
);

$result = $client->createShipment($item);

echo "Parcel No: {$result->firstParcelNo()}\n";
echo 'Request sent to: ' . ($fakeTransport->lastRequest?->url ?? '-') . "\n";
echo 'No real network call was made; the fake transport served a canned response.' . "\n";
