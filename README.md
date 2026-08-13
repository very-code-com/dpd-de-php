# dpd-de-php

**PHP client library for the DPD Cloud Service Webservice (DPD Deutschland).**

An open-source PHP client for DPD Germany's Cloud Service: create shipments and download
their labels, pre-flight validate orders server-side, track parcels via two different tracking
models, find pickup ParcelShops, and fetch account pickup rules. SOAP by default, with an
optional REST transport.

[![Latest Version](https://img.shields.io/packagist/v/very-code-com/dpd-de-php.svg)](https://packagist.org/packages/very-code-com/dpd-de-php)
[![Total Downloads](https://img.shields.io/packagist/dt/very-code-com/dpd-de-php.svg)](https://packagist.org/packages/very-code-com/dpd-de-php)
[![CI](https://github.com/very-code-com/dpd-de-php/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/very-code-com/dpd-de-php/actions/workflows/ci.yml)
[![Integration](https://github.com/very-code-com/dpd-de-php/actions/workflows/integration.yml/badge.svg?branch=master)](https://github.com/very-code-com/dpd-de-php/actions/workflows/integration.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](https://phpstan.org)
[![PHP](https://img.shields.io/badge/PHP-8.2+-blue)](https://www.php.net)
[![License](https://img.shields.io/packagist/l/very-code-com/dpd-de-php.svg)](LICENSE)

---

## Requirements

- PHP 8.2+
- `ext-curl`
- `ext-dom`
- `ext-json`

---

## Installation

```bash
composer require very-code-com/dpd-de-php
```

---

## Quick Start

```php
use VeryCodeCom\DpdDe\DpdCloudClient;
use VeryCodeCom\DpdDe\Dto\{Address, OrderItem, Parcel};
use VeryCodeCom\DpdDe\Enum\ShipService;

$client = DpdCloudClient::sandbox('DPD Cloud Service Alpha2', 'partner-token', 123456, 'user-token');

$result = $client->createShipment(new OrderItem(
    shipAddress: new Address(
        name: 'Max Mustermann', street: 'Musterstr.', houseNo: '1',
        zipCode: '12345', city: 'Berlin', country: 'DE',
    ),
    parcelShopId: 0,
    parcel: new Parcel(
        ShipService::Classic,
        weightKg: 2.5,
        content: 'Testware',          // required by DPD (1-35 chars)
        yourInternalId: 'ORDER-1',    // required by DPD (1-35 chars)
        reference1: 'ORDER-1',        // required by DPD (1-35 chars)
    ),
));

echo $result->firstParcelNo();               // e.g. 01234567890123
file_put_contents('label.pdf', $result->labelPdf); // already Base64-decoded
```

See [`examples/`](examples/) for complete, runnable scripts.

---

## Configuration

```php
// Named constructors
$client = DpdCloudClient::sandbox($partnerName, $partnerToken, $userId, $userToken);
$client = DpdCloudClient::production($partnerName, $partnerToken, $userId, $userToken);

// From environment variables (recommended)
$config = DpdCloudConfig::fromEnv();
$client = new DpdCloudClient($config);

// From array (framework config)
$config = DpdCloudConfig::fromArray([
    'partner_name' => '...', 'partner_token' => '...',
    'user_id' => 123456, 'user_token' => '...', 'env' => 'production',
]);
```

DPD Cloud Service uses **two** credential pairs, both issued by DPD:

- **PartnerCredentials** (`Name` + `Token`): identifies the software/integration (your "Partner" slot, e.g. `DPD Cloud Service Alpha2`).
- **UserCredentials** (`cloudUserID` + `Token`): identifies the DPD customer account (your own account).

| Env variable                | Required | Default      | Description                              |
|------------------------------|----------|--------------|-------------------------------------------|
| `DPD_CLOUD_PARTNER_NAME`     | yes      | -            | `PartnerCredentials.Name`                 |
| `DPD_CLOUD_PARTNER_TOKEN`    | yes      | -            | `PartnerCredentials.Token`                |
| `DPD_CLOUD_USER_ID`          | yes      | -            | `UserCredentials.cloudUserID` (integer)   |
| `DPD_CLOUD_USER_TOKEN`       | yes      | -            | `UserCredentials.Token`                   |
| `DPD_CLOUD_ENV`              | no       | `production` | `sandbox` or `production`                 |
| `DPD_CLOUD_LANGUAGE`         | no       | `de_DE`      | Response language, e.g. `de_DE`, `en_US`  |
| `DPD_CLOUD_TIMEOUT`          | no       | `30`         | Request timeout (seconds)                 |
| `DPD_CLOUD_CONNECT_TIMEOUT`  | no       | `10`         | Connection timeout (seconds)              |
| `DPD_CLOUD_DEBUG`            | no       | `0`          | `1`/`true` to enable verbose debug output (see below) |

**Sandbox vs. production endpoints:**

| Environment | SOAP endpoint                                                   | REST base                              |
|-------------|------------------------------------------------------------------|-----------------------------------------|
| Sandbox (Testsystem) | `https://cloud-stage.dpd.com/services/v1/DPDCloudService.asmx` | `https://cloud-stage.dpd.com/api/v1` |
| Production  | `https://cloud.dpd.com/services/v1/DPDCloudService.asmx`        | `https://cloud.dpd.com/api/v1`       |

DPD issues **separate credentials per environment**: your production PartnerCredentials/UserCredentials will not work against the sandbox and vice versa. Using live credentials against `cloud-stage` returns `ErrorID 2000 / CLOUD_API_PARTNERCREDENTIALS`, and vice versa.

### Where to get sandbox (Testsystem) credentials

Register a free developer account at
[esolutions.dpd.com/entwickler/registrieren.aspx](https://esolutions.dpd.com/entwickler/registrieren.aspx?lng=deu),
then log in.

**Do not use the portal's "Benutzerdaten ändern" / "Sandbox Zugangsdaten" page.** Despite what
the official PDF (page 2) and DPD support both tell you, that page shows credentials for a
*different* API: DPD **Web Connect** (`public-ws-stage.dpd.com`, DelisID + password and a
`getAuth` call returning an `authToken`, i.e. `sandboxdpd` / `xMm...`). Those do not work with the Cloud
Service and are not convertible into Cloud credentials: the formats are incompatible
(`PartnerCredentials.Token` is 10-25 chars, `cloudUserID` is an integer).

The DPD **Cloud** sandbox credentials are pre-filled inside the portal's code examples:

> Entwickler / DPD Cloud Webservice / Code Beispiele / SOAP (or REST) / Microsoft .NET, Java or PHP
>
> `https://esolutions.dpd.com/entwickler/dpdcloudwebservice/code-beispiele-soap-microsoft.aspx?allowcloud=true`

Note the `?allowcloud=true` query parameter: the entire Cloud section of the portal is
unreachable through the normal logged-in navigation without it.

The snippets contain a shared, generic sandbox account: the same values for every developer,
not personalised to you. Copy the four values out of the snippet into your environment
(only their leading characters are shown here):

```bash
export DPD_CLOUD_ENV=sandbox
export DPD_CLOUD_PARTNER_NAME="DPD Sandbox"
export DPD_CLOUD_PARTNER_TOKEN="064..."   # 20 characters
export DPD_CLOUD_USER_ID=279...           # 7 digits
export DPD_CLOUD_USER_TOKEN="635..."      # 20 characters
```

That account covers all five operations over both SOAP and REST, including `setOrder` with
`startOrder`, which returns a real PDF label. Its own pickup address resolves to ZIP `63110`,
depot `0163`.

For **production** credentials, contact DPD via the eSolutions portal or `cit@dpd.de`; they
are issued by e-mail in exactly the same four-value shape.

### Debug mode

Set the `debug` flag (constructor arg, `DPD_CLOUD_DEBUG=1`, or `'debug' => true` in
`fromArray`) to make the client attach the **raw DPD response** to every thrown
exception and log a full **debug report** (message + raw XML/JSON + stack trace) at
`error` level via the injected PSR-3 logger:

```php
$config = DpdCloudConfig::fromArray([...], debug: true);
$client = new DpdCloudClient($config, logger: $myPsrLogger);

try {
    $client->createShipment($item);
} catch (\VeryCodeCom\DpdDe\Exception\DpdCloudException $e) {
    echo $e->getRawResponse();   // exact XML/JSON DPD returned (or null)
    echo $e->getDebugReport();   // class + message + raw response + stack trace
}
```

Leave `debug` off in production to keep exceptions and logs concise.

---

## Documentation

| Document | Contents |
|----------|----------|
| [docs/API.md](docs/API.md) | Every client method, the DTOs they take and return, the local validation rules, and the exception hierarchy |
| [docs/DPD-NOTES.md](docs/DPD-NOTES.md) | DPD's own quirks: the two tracking models, SOAP/REST transport details, fields that are mandatory despite being optional in the WSDL |
| [examples/](examples/) | Runnable scripts covering shipments, batching, tracking, ParcelShops, REST, business rules and error handling |

---

## Dependency Injection & Testing

The client accepts a custom `TransportInterface` and PSR-3 logger:

```php
new DpdCloudClient(
    config:        DpdCloudConfig,
    transportMode: TransportMode           = TransportMode::Soap,
    transport:     TransportInterface       = new CurlTransport(),
    logger:        ?Psr\Log\LoggerInterface = null,
)
```

Implement `TransportInterface::send(TransportRequest): TransportResponse` to swap in a
PSR-18 HTTP client adapter, or a scripted fake for tests, see
[`tests/Unit/DpdCloudClientTest.php`](tests/Unit/DpdCloudClientTest.php) for the pattern
used by this library's own test suite (no real network calls).

---

## Running Tests

```bash
composer install

# Unit tests (no network required)
vendor/bin/phpunit --testsuite unit

# Integration tests against the real DPD Cloud Service sandbox (Testsystem).
# Credentials: see "Where to get sandbox (Testsystem) credentials" above.
DPD_CLOUD_SANDBOX=1 \
DPD_CLOUD_PARTNER_NAME="DPD Sandbox" \
DPD_CLOUD_PARTNER_TOKEN=064... \
DPD_CLOUD_USER_ID=279... \
DPD_CLOUD_USER_TOKEN=635... \
  vendor/bin/phpunit --testsuite integration

# Static analysis (PHPStan level 8)
vendor/bin/phpstan analyse --memory-limit=512M
```

The integration suite exercises all five operations over **both** transports against
`cloud-stage.dpd.com`, including a real `setOrder`/`startOrder` that issues a label. Add
`DPD_CLOUD_ALLOW_LABELS=0` to skip the two label-issuing tests and keep the run read-only.

### Continuous integration

| Workflow | Runs | What it does |
|----------|------|--------------|
| [`ci.yml`](.github/workflows/ci.yml) | every push and pull request | Unit tests on PHP 8.2/8.3/8.4, PHPStan level 8, `composer validate`, lints every example |
| [`integration.yml`](.github/workflows/integration.yml) | pushes to `master`, nightly, manual | The sandbox integration suite |

The integration workflow is deliberately kept out of the pull-request gate: forks cannot read
repository secrets, and DPD enforces a per-account call limit (`2027 CLOUD_API_USERCALLLIMIT`,
10-minute cool-down) that a per-push, per-PHP-version run would walk straight into. It is
serialised through a concurrency group for the same reason.

To enable it, add the four sandbox values (`DPD_CLOUD_PARTNER_NAME`,
`DPD_CLOUD_PARTNER_TOKEN`, `DPD_CLOUD_USER_ID`, `DPD_CLOUD_USER_TOKEN`) to a repository
**environment** named `dpd-sandbox`, restricted to the `master` branch. Environment secrets
are only readable by jobs that opt into that environment, so a workflow added on a side
branch cannot reach them. Without them the job reports "not configured" and stops rather
than failing the build. Running it manually (`workflow_dispatch`) offers a checkbox to skip
the label-issuing tests.

---

## License

[Apache License 2.0](LICENSE), see [NOTICE](NOTICE) for attribution requirements.

You may use, distribute, and modify this library freely. You must retain the `NOTICE` file and copyright notices in any redistribution or derivative work.

---

*Built by [Very Code](https://very-code.com). Contributions welcome, open an issue or PR.*
