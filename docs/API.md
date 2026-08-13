# API Reference

Full reference for `very-code-com/dpd-de-php`. See the [README](../README.md) for
installation, configuration and credentials, and [DPD-NOTES.md](DPD-NOTES.md) for
carrier-specific behaviour worth knowing before you integrate.

---

## Client methods

### `createShipment(OrderItem $item, ?OrderSettings $settings = null): SetOrderResult`
### `createShipments(array $items, ?OrderSettings $settings = null): SetOrderResult`

Creates one shipment, or up to **30** in a single call (DPD method: `setOrder`,
`OrderAction=startOrder`). Runs local pre-flight validation first (see [Local validation](#local-validation)).

> **Note:** DPD Cloud Service does **not** support multi-parcel shipments (MPS), each
> physical package needs its own `OrderItem` with its own `shipAddress`.

`SetOrderResult` exposes `labelPdf` (already Base64-decoded PDF bytes; DPD returns a PDF even
when `LabelSize::ZplA6` is requested, see [DPD-NOTES.md](DPD-NOTES.md#known-dpd-cloud-service-api-quirks)) and `items` (one `OrderResult` per parcel,
`yourInternalId` + `parcelNo`); `firstParcelNo()` is a convenience shortcut.

**`OrderItem` fields:**

| Field           | Type       | Required | Notes |
|------------------|------------|----------|-------|
| `shipAddress`    | `Address`  | yes      | Recipient address |
| `parcelShopId`   | `int`      | yes      | Pickup point ID; `0` for classic home delivery |
| `parcel`         | `Parcel`   | yes      | Shipping product, weight, references |
| `pudoId`         | `?string`  | no       | Newer PUDO/locker identifier (undocumented in the PDF, present in the live WSDL) |

**`Parcel` fields:** `shipService` (`ShipService` enum), `weightKg` (0-31.5), `content`,
`yourInternalId`, `reference1`, `reference2` (all max 35 chars), and a deprecated `cod`
(DPD discontinued cash-on-delivery on 2020-05-11).

### `checkOrderData(array $items, ?OrderSettings $settings = null): void`

Server-side dry run (DPD method: `setOrder`, `OrderAction=checkOrderData`), asks DPD to
validate the order data **without** creating a real shipment or consuming a parcel number.
Throws `DpdCloudApiException` if DPD rejects the data.

### `validateLocally(array $items): string[]`

Runs the same local field/weight checks `createShipment()` performs, with **no** network
call. Returns an empty array when the items are locally valid.

### `fetchParcelLifeCycle(string $parcelNo): TrackingResult`

Tracking via the older, UI-rendering-oriented **"Parcel Life Cycle Service 2.0"** (DPD
method: `getParcelLifeCycle`). Returns a `TrackingResult` with a `shipmentInfo` header, a
`statusInfo` list (one per milestone) and a `contactInfo` list, each built from
pre-formatted `ContentLine`/`ContentItem` text blocks (bold/paragraph flags included) meant
for direct UI rendering. **Always uses SOAP**, regardless of the configured transport mode
(see [REST caveats](DPD-NOTES.md#rest-transport)).

### `fetchOrderStatus(string $parcelNo, ?string $deliveryZipCode = null): OrderStatus`

Tracking via the newer, structured **"Parcel Life Cycle Service 3.1"** (DPD method:
`getOrderStatus`). Returns an `OrderStatus` with `orderInformation` (service/weight/
reference/receiver), `shipAddress`, `lastStatusInfo` and a `statusInfoContainer` with five
named milestones (`start`, `onTheRoad`, `deliveryDepot`, `carLoad`, `delivered`). Provide
`deliveryZipCode` to receive full (non-anonymised) tracking data, per DPD's privacy rules.

### `findParcelShops(ParcelShopQuery $query): ParcelShop[]`

Searches for DPD ParcelShop pickup points (DPD method: `getParcelShopFinder`), either by
address or by geo-coordinates:

```php
use VeryCodeCom\DpdDe\Dto\{ParcelShopQuery, SearchAddress, SearchGeoData};

$shops = $client->findParcelShops(
    ParcelShopQuery::byAddress(new SearchAddress(zipCode: '10115', city: 'Berlin', country: 'DE'))
);

$shops = $client->findParcelShops(
    ParcelShopQuery::byGeoData(new SearchGeoData(longitude: 13.405, latitude: 52.52))
);
```

Each `ParcelShop` carries `shopAddress`, `geoData` (distance + coordinates),
`openingHoursList`, `holidayList`, `shopServiceList` (`ShopService[]`), and
`isParcelLocker(): bool`.

### `lastSystemInformation(): ?string`

DPD's free-text `SystemInformation` from the most recent response, or `null` when the last
call carried none. DPD uses it for service announcements (planned maintenance, upcoming API
changes); it is also logged at `notice` level through the injected PSR-3 logger.

### `fetchZipCodeRules(): ZipCodeRules`

Fetches pickup rules for **your own account's pickup address** (DPD method:
`getZipCodeRules`; no parameters needed), no-pickup days, Express/Classic cut-off times,
pickup depot, state. `getNoPickupDaysList(): DateTimeImmutable[]` parses the raw comma-
separated date list.

---

## Local validation

Every `createShipment()`/`createShipments()`/`checkOrderData()` call is pre-validated
locally (no network call) against the field constraints documented in the DPD Cloud
Service Webservice documentation (error-code appendix):

### Field constraints

| Field                                   | Constraint            | DPD error |
|------------------------------------------|------------------------|-----------|
| Weight                                   | 0-31.5 kg           | `CLOUD_API_ORDER_WEIGHT` |
| `Content` / `YourInternalID` / `Reference1` | **required**, 1-35 chars | `CLOUD_API_ORDER_CONTENT` / `_INTERNALID` / `_REFERENCE1` |
| `Reference2`                             | optional, max 35 chars | `CLOUD_API_ORDER_REFERENCE2` |
| `COD.Purpose`                            | max 14 chars (deprecated) | `CLOUD_API_ORDER_CODPURPOSE` |
| `ShipAddress.Company`                    | 2-50 chars, when set  | `CLOUD_ADDRESS_COMPANY` |
| `ShipAddress.Name` (first+last combined) | 2-50 chars            | `CLOUD_ADDRESS_NAME` |
| `ShipAddress.Salutation`                 | 2-10 chars, when set  | `CLOUD_ADDRESS_SEXCODE` |
| `ShipAddress.Street`                     | 1-50 chars, required  | `CLOUD_ADDRESS_STREET` |
| `ShipAddress.HouseNo`                    | 1-8 chars, required   | `CLOUD_ADDRESS_HOUSENO` |
| `ShipAddress.City`                       | 1-50 chars, required  | `CLOUD_ADDRESS_CITY` |
| `ShipAddress.ZipCode` / `Country`        | required              | `CLOUD_ADDRESS_ZIPCODE` / `_COUNTRY` |
| `ShipAddress.Phone`                      | 5-20 chars, digits and `+-()` only | `CLOUD_ADDRESS_PHONE` |
| `ShipAddress.Mail`                       | valid address, max 50 chars | `CLOUD_ADDRESS_MAIL` |
| `ShipAddress.State` (ISO 3166-2)         | exactly 2 chars, when set | `CLOUD_STATE_STATESHORT` |
| Order batch size                         | max 30 `OrderItem`s per call | `CLOUD_API_ORDER_MAXORDERS` |

Lengths are counted in **characters**, not bytes. DPD does the same, so `str_repeat('ä', 35)`
is a valid 35-character `Content`.

### Business rules

| Rule | DPD error |
|------|-----------|
| `State` is mandatory for USA and Canada, and must not be set for any other country. The country is matched across all spellings DPD accepts (`US`, `USA`, `840`, `United States`, `U.S.A.` and so on) | `CLOUD_ADDRESS_STATE` |
| `Gender` may only be set together with a name | `CLOUD_ADDRESS_GENDER` |
| Predict products (`Classic_Predict`, `Classic_COD_Predict`) need an e-mail address or a phone number | `CLOUD_ADDRESS_NEEDMAILORSMS` |
| `Classic_Return` needs a phone number, and cannot be batched with other items | `CLOUD_API_ORDER_CLASSICRETURN_NOBULKPRINT` |
| `Shop_Delivery` needs a non-zero `parcelShopId`: look one up with `findParcelShops()` | `CLOUD_API_ORDER_PARCELSHOP` |
| Express 8:30-18:00 products are Germany-domestic only; use `Express_International` abroad | `CLOUD_API_ORDER_EXPRESS_DEU_COUNTRY` |
| COD amount must be 1.00-5000.00 EUR, and cash is capped at 2500.00 EUR (use a cheque above that) | `CLOUD_API_ORDER_CODAMOUNT` / `CLOUD_API_ORDER_COD_PAYMENT` |
| COD data and `*_COD` products must be used together | `CLOUD_API_ORDER_SHIPSERVICE` |
| `ParcelShopQuery::$maxReturnValues` must be 0-100 (enforced in the constructor) | `CLOUD_API_PARCELSHOPFINDER_MAXRETURNVALUES` |

This is a best-effort local check mirroring DPD's own validation; it does not replace
`checkOrderData()` for a full server-side dry run, and it cannot cover rules that depend on
DPD's own data (address existence, per-country product availability, depot routing). Failures
throw `DpdCloudValidationException` before any network call is made.

See [`examples/08_business_rules_and_errors.php`](../examples/08_business_rules_and_errors.php)
for each rule triggered in turn.

---

## Exceptions

All exceptions extend `VeryCodeCom\DpdDe\Exception\DpdCloudException`.

| Exception                        | Trigger                                                        |
|-----------------------------------|------------------------------------------------------------------|
| `DpdCloudValidationException`     | Local pre-flight validation failed, `$errors: string[]`         |
| `DpdCloudAuthException`           | DPD rejects Partner/User credentials (`CLOUD_API_PARTNERCREDENTIALS`, `CLOUD_API_USERCREDENTIALS`, `CLOUD_API_NOLOGIN`, `CLOUD_API_NOUSERACCESS`), carries `$errorCode` |
| `DpdCloudRateLimitException`      | Account call limit reached (`CLOUD_API_USERCALLLIMIT`), `retryAfterSeconds()` returns DPD's fixed 600 s cool-down. Deliberately **not** an `DpdCloudAuthException`: back off and retry instead of asking for new credentials |
| `DpdCloudApiException`            | Other DPD business-logic errors (`Ack=false`), carries `$errors` (structured `ErrorID`/`ErrorCode`/messages); `hasCode(string): bool` and `getFormattedErrors(): string[]` helpers |
| `DpdCloudTransportException`      | Network error or non-2xx HTTP response                          |
| `DpdCloudResponseParseException`  | DPD returned unparseable / unexpected-shape XML or JSON          |

Every exception exposes `getRawResponse(): ?string` (the exact response DPD returned,
when captured) and `getDebugReport(): string` (message + raw response + stack trace) -
see [Debug mode](../README.md#debug-mode).
