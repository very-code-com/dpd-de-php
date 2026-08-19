# Changelog

All notable changes to `very-code-com/dpd-de-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.0.1] - 2026-08-19

Fixes `fetchOrderStatus()` over REST against the live DPD Cloud Service. The production API
wraps tracking data in an `OrderStatus` property; the client expected those fields at the response
root and therefore returned an empty DTO even when DPD answered with `Ack=true`. SOAP was not
affected. Verified against the production REST endpoint and the current DPD WSDL.

### Fixed

- `RestResponseParser::orderStatus()` now reads the live API's top-level `OrderStatus` wrapper
  while retaining support for the historical flat response shape.
- A successful response without an order-status payload now raises
  `DpdCloudResponseParseException` instead of silently returning an empty `OrderStatus`.

### Documented

- Clarify that DPD Cloud Service has no API operation for downloading/reprinting a label by
  parcel number; the `LabelPDF` returned by `setOrder/startOrder` must be stored by the caller.
  The linked myDPD order list is documented as the manual reprint fallback when the order is
  available there.

## [1.0.0], 2026-08-13

Initial release.

### Added

- `DpdCloudClient`: main API client with named constructors `::sandbox()` / `::production()`
- `createShipment()` / `createShipments()`: DPD `setOrder` (`OrderAction=startOrder`), up to
  30 `OrderItem`s per call, with local pre-flight validation before the API call
- `checkOrderData()`: DPD `setOrder` (`OrderAction=checkOrderData`) server-side dry run,
  validates order data without creating a real shipment or consuming a parcel number
- `validateLocally()`: public pre-flight validation with no network call
- `fetchParcelLifeCycle()`: DPD `getParcelLifeCycle` ("Parcel Life Cycle Service 2.0"),
  always via SOAP
- `fetchOrderStatus()`: DPD `getOrderStatus` ("Parcel Life Cycle Service 3.1"), SOAP or REST
- `findParcelShops()`: DPD `getParcelShopFinder`, by address or geo-coordinates, SOAP or REST
- `fetchZipCodeRules()`: DPD `getZipCodeRules` (account pickup rules), SOAP or REST
- `lastSystemInformation()`: DPD's free-text service announcements, carried on every response
  and also logged at `notice` level
- `DpdCloudConfig` with `::sandbox()` / `::production()` / `::fromEnv()` / `::fromArray()`
  factories, dual credential model (`PartnerCredentials` + `UserCredentials`)
- `Internal\Soap\SoapEnvelopeBuilder`: raw cURL, document/literal SOAP 1.1 XML builder for
  all five operations
- `Internal\Soap\ResponseParser`: DOMXPath-based response parser; documents and works around
  the `ParcelLifeCycleService/2.0` schema's `form="unqualified"` override (tracking elements
  carry no namespace at all in the real response, despite the schema's own target namespace)
- `Internal\Rest\RestRequestBuilder` / `RestResponseParser`: REST transport for all
  operations except `getParcelLifeCycle` (which always falls back to SOAP). Implements DPD's
  path-parameter layout, its two URL rules (empty parameters sent as the literal `null`; no
  `.` directly before a `/`) and the hyphenated credential headers
- `Internal\Validator\OrderValidator`: local pre-flight validation of both the field
  constraints and the business rules from the DPD documentation's error-code appendix:
  weight and length limits (counted in characters, not bytes), `Content` / `YourInternalID` /
  `Reference1` being mandatory in practice, `State` mandatory for USA/Canada and forbidden
  elsewhere, Predict needing an e-mail or phone number, `Classic_Return` needing a phone
  number and refusing to be batched, `Shop_Delivery` needing a ParcelShopID, Express 8:30-18:00
  being Germany-only, and the COD amount/payment ceilings
- `TransportInterface` + `CurlTransport`: transport abstraction for easy test mocking
  (GET/POST, custom headers, shared by both SOAP and REST)
- Full exception hierarchy: `DpdCloudException` -> `DpdCloudValidationException`,
  `DpdCloudAuthException`, `DpdCloudRateLimitException` (with `retryAfterSeconds()`),
  `DpdCloudApiException`, `DpdCloudTransportException`, `DpdCloudResponseParseException`
- Debug mode (`DpdCloudConfig::$debug` / `DPD_CLOUD_DEBUG` env var), attaches the raw DPD
  response to every thrown exception and logs a full debug report (message + raw response +
  stack trace) via the injected PSR-3 logger
- PHP 8.1+ backed enums: `ShipService`, `OrderAction`, `LabelSize`, `LabelStartPosition`,
  `PaymentType`, `SearchMode`, `NeedService`, `ShopService`, `ParcelStationType`,
  `TrackingStatus`, `TransportMode`
- ~27 typed DTOs mirroring the WSDL 1:1 (`Address`, `Parcel`, `OrderItem`, `OrderSettings`,
  `SetOrderResult`, `ParcelShop`, `ZipCodeRules`, `TrackingResult`, `OrderStatus`, etc.),
  including fields present in the live WSDL but absent from the PDF documentation
  (`PudoID`, `ServiceDetail`, `CustomerNo`, `ParcelStation`, `HolidayList`)
- PSR-3 logger injection with `NullLogger` as default
- 206 unit tests (490 assertions) plus a 12-test integration suite covering all five
  operations over both transports against the sandbox (skipped unless `DPD_CLOUD_SANDBOX=1`)
- Eight runnable examples in [`examples/`](examples/), documentation in [`docs/`](docs/)
- GitHub Actions CI: unit tests on PHP 8.2/8.3/8.4, PHPStan level 8, `composer validate` and
  example linting on every push; a separate nightly/manual workflow runs the sandbox
  integration suite, serialised through a concurrency group so it cannot walk into DPD's
  per-account call limit

### Notes

- SOAP is the default transport and follows the live WSDL at
  `https://cloud.dpd.com/services/v1/DPDCloudService.asmx?wsdl`. REST covers the same
  operations except `getParcelLifeCycle`; both are exercised by the integration suite.
- Finding DPD **Cloud** sandbox credentials is not obvious: the portal's own
  "Sandbox Zugangsdaten" page serves DPD **Web Connect** credentials
  (`sandboxdpd` / `public-ws-stage.dpd.com`), which do not work with this API. The Cloud
  credentials are pre-filled in the portal's code examples, reachable only through the
  `?allowcloud=true` query parameter, see the README.
- `Content`, `YourInternalID` and `Reference1` are `minOccurs="0"` in the WSDL but rejected
  by DPD when missing (`2125` / `2122` / `2123`); only `Reference2` is genuinely optional.
- `LabelSize::ZplA6` is accepted by DPD without an error but still returns a PDF payload.
- DPD Cloud Service does not support multi-parcel shipments (MPS) through this API; each
  physical package needs its own `OrderItem`.
- Cash-on-delivery (`Cod` DTO, `PaymentType` enum, `*_COD` `ShipService` variants) is kept
  1:1 with the WSDL for completeness but is deprecated. DPD discontinued the service on
  2020-05-11.

[Unreleased]: https://github.com/very-code-com/dpd-de-php/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/very-code-com/dpd-de-php/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/very-code-com/dpd-de-php/releases/tag/v1.0.0
