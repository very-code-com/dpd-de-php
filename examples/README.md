# Examples

Runnable scripts demonstrating every part of the `very-code-com/dpd-de-php` client.

Set your **sandbox** credentials once, then run any script:

```bash
export DPD_CLOUD_PARTNER_NAME="DPD Sandbox"
export DPD_CLOUD_PARTNER_TOKEN=064...
export DPD_CLOUD_USER_ID=279...
export DPD_CLOUD_USER_TOKEN=635...

php examples/01_create_shipment.php
```

Those are DPD's shared sandbox credentials (leading characters only, copy the full values
from DPD's developer portal, see
[Where to get sandbox credentials](../README.md#where-to-get-sandbox-testsystem-credentials)).
Scripts that talk to DPD need them set; the offline ones (05, 08) run with no configuration
at all.

| # | Script | What it shows | Needs network? |
|---|--------|----------------|-----------------|
| 01 | [`01_create_shipment.php`](01_create_shipment.php) | Creating a single shipment with a fully-populated ship address, downloading + saving the label PDF, and handling of every exception type | Yes (sandbox) |
| 02 | [`02_batch_and_validation.php`](02_batch_and_validation.php) | Batch `createShipments()` with several `OrderItem`s, local `validateLocally()` (no network), server-side `checkOrderData()` dry run, and custom `OrderSettings` (label size/position, ship date) | Yes (sandbox) |
| 03 | [`03_tracking.php`](03_tracking.php) | Both DPD tracking models side by side: `fetchParcelLifeCycle()` (UI-oriented `ContentLine`/`ContentItem` blocks) and `fetchOrderStatus()` (structured milestones) | Yes (sandbox) |
| 04 | [`04_parcel_shops_and_zip_rules.php`](04_parcel_shops_and_zip_rules.php) | `findParcelShops()` by address AND by geo-coordinates, plus `fetchZipCodeRules()` for your own account's pickup address | Yes (sandbox) |
| 05 | [`05_di_and_testing.php`](05_di_and_testing.php) | Dependency injection: a scripted fake `TransportInterface` (no network), PSR-3 logger injection, the exact pattern used by the unit tests | No |
| 06 | [`06_parcel_shop_delivery.php`](06_parcel_shop_delivery.php) | Finding a Pickup ParcelShop (opening hours, holidays, services, lockers vs. staffed shops) and shipping to it with `Shop_Delivery` | Yes (sandbox) |
| 07 | [`07_rest_transport.php`](07_rest_transport.php) | `TransportMode::Rest`: the same calls over REST and SOAP side by side, `lastSystemInformation()`, and the one method that always stays on SOAP | Yes (sandbox) |
| 08 | [`08_business_rules_and_errors.php`](08_business_rules_and_errors.php) | Every local business rule triggered in turn (State, Predict, Shop_Delivery, Express, COD limits, contact formats, Classic_Return batching) and the full exception hierarchy | Mostly no |

## Notes

- **Sandbox vs. production**: use `DpdCloudClient::sandbox(...)` (DPD Testsystem) while
  developing; `DpdCloudClient::production(...)` requires separate, DPD-issued production
  credentials.
- **Unique references**: the create examples derive `yourInternalId` from the current
  timestamp so you can re-run them freely.
- **No multi-parcel shipments (MPS)**: each physical package needs its own `OrderItem`
  with its own ship address; see example 02 for batching several items in one call.
- **Two tracking models**: `getParcelLifeCycle` ("Parcel Life Cycle Service 2.0") and
  `getOrderStatus` ("Parcel Life Cycle Service 3.1") return different, non-interchangeable
  shapes; see example 03 for both side by side.
- **REST transport**: pass `TransportMode::Rest` to the `DpdCloudClient` constructor to
  use REST instead of SOAP for all operations except `fetchParcelLifeCycle()` (which
  always uses SOAP); see example 07 and [docs/DPD-NOTES.md](../docs/DPD-NOTES.md).

See [docs/API.md](../docs/API.md) for the full API reference.
