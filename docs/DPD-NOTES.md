# DPD Cloud Service notes

Behaviour of DPD's own service that shaped this library, read this before integrating.
See the [README](../README.md) for setup and [API.md](API.md) for the client reference.

---

## REST transport

DPD Cloud Service exposes both SOAP and REST endpoints for the same five operations, and this
library supports both via the `TransportMode` constructor argument:

```php
use VeryCodeCom\DpdDe\Enum\TransportMode;

$client = new DpdCloudClient($config, TransportMode::Rest);
```

The integration suite (`tests/Integration`) runs every operation over both transports
against the sandbox.

**SOAP remains the default.** It follows the live WSDL at
`https://cloud.dpd.com/services/v1/DPDCloudService.asmx?wsdl` for every field name, type and
namespace.

REST is not described in the PDF documentation; the contract below comes from DPD's own PHP
code examples in the developer portal:

| Operation | Method | URL |
|---|---|---|
| `setOrder` | POST | `/api/v1/setOrder` (JSON body, same PascalCase shape as SOAP) |
| `getZipCodeRules` | GET | `/api/v1/ZipCodeRules` |
| `getParcelShopFinder` (address) | GET | `/api/v1/ParcelShopFinder/{Max}/{Street}/{HouseNo}/{ZipCode}/{City}/{Country}/{NeedService}/{HideOnClosedAt}` |
| `getParcelShopFinder` (geo) | GET | `/api/v1/ParcelShopFinder/{Max}/{Longitude}/{Latitude}/{NeedService}/{HideOnClosedAt}` |
| `getParcelLifeCycle` | GET | `/api/v1/ParcelLifeCycle/{ParcelNo}` |
| `getOrderStatus` | GET | `/api/v1/getOrderStatus/{ParcelNo}/{DeliveryZipCode}` |

Two REST-only URL rules, both handled for you: an empty path parameter is passed as the
literal string `null`, and a `.` may not appear directly before a `/` (DPD's router answers
such URLs with HTTP 404), so a trailing dot is stripped, `Musterstr.` is sent as `Musterstr`.

Credentials go in HTTP headers, **hyphen-separated**:

```
Version: 100
Language: de_DE
PartnerCredentials-Name: <PartnerCredentials.Name>
PartnerCredentials-Token: <PartnerCredentials.Token>
UserCredentials-cloudUserID: <UserCredentials.cloudUserID>
UserCredentials-Token: <UserCredentials.Token>
```

Dotted header names (`PartnerCredentials.Name`) are rejected with
`ErrorID 2000 / CLOUD_API_PARTNERCREDENTIALS`: it must be hyphens.

`fetchParcelLifeCycle()` **always uses SOAP**, regardless of `TransportMode`: its deeply
nested, UI-oriented JSON response shape has no REST parser in this library.

---

## Known DPD Cloud Service API quirks

1. **Two tracking models.** `getParcelLifeCycle` ("Parcel Life Cycle Service 2.0") returns
   pre-formatted, UI-rendering-oriented text blocks (`ContentLine`/`ContentItem`, with
   bold/paragraph flags); `getOrderStatus` ("Parcel Life Cycle Service 3.1") returns a
   newer, more structured model with named milestones. They are not interchangeable and
   have different DTOs (`TrackingResult`/`StatusInfo` vs. `OrderStatus`/`StatusInfoDetail`).
2. **`ParcelLifeCycleService/2.0` namespace quirk.** The schema declares
   `elementFormDefault="qualified"` but every individual element inside `TrackingResult`
   (`shipmentInfo`, `statusInfo`, `status`, `label`, `content`, etc.) explicitly overrides
   this with `form="unqualified"`. In practice, these elements carry **no namespace at all**
   in the real SOAP response, not even the schema's own `ParcelLifeCycleService/2.0`
   namespace. `Internal\Soap\ResponseParser` queries them with unprefixed XPath
   expressions, not a registered prefix.
3. **Document/literal SOAP style.** Unlike some other German carrier APIs, DPD Cloud
   Service is plain document/literal: no `xsi:type` attributes, and the default namespace
   `https://cloud.dpd.com/` is declared once on the outer request element and inherited by
   every descendant.
4. **No multi-parcel shipment (MPS) support in this API.** Every physical package needs
   its own `OrderItem` / ship address (see the DPD Cloud Service FAQ).
5. **Cash-on-delivery is deprecated.** DPD fully discontinued "Nachnahme" (COD) on
   2020-05-11. The `Cod` DTO, `PaymentType` enum, and `*_COD` `ShipService` variants remain
   1:1 with the WSDL for completeness but should not be relied on for new integrations.
6. **Separate credentials per environment.** Sandbox and production each require their
   own PartnerCredentials/UserCredentials pair; they are not interchangeable.
7. **`Content`, `YourInternalID` and `Reference1` are de-facto mandatory.** The WSDL marks
   them `minOccurs="0"` and the PDF calls them "individuelle Angabe", but omitting any of
   them makes `setOrder` fail with `2125 CLOUD_API_ORDER_CONTENT`,
   `2122 CLOUD_API_ORDER_INTERNALID` or `2123 CLOUD_API_ORDER_REFERENCE1` respectively.
   Only `Reference2` is genuinely optional.
8. **`LabelSize::ZplA6` silently returns a PDF.** DPD accepts `ZPL_A6` without an error but
   the `LabelPDF` payload still starts with `%PDF-`. Matches the PDF's remark that ZPL "wird
   aktuell nicht unterstützt"; there is just no error to tell you.
9. **REST `getOrderStatus` has an extra response wrapper.** The live endpoint returns the
   tracking payload under the top-level `OrderStatus` property, even though DPD's older REST
   examples showed those fields at the top level. The parser accepts both shapes.
10. **Labels cannot be fetched again by parcel number.** The service exposes no label-download
    or reprint operation. `LabelPDF` is returned only by `setOrder` with
    `OrderAction=startOrder`; applications must persist those decoded bytes if a later reprint
    is required. Calling `startOrder` again creates a new parcel number rather than retrieving
    an existing label. As a manual fallback, DPD documents reprinting from the linked myDPD
    account's order list via the printer icon, provided the order is visible there:
    <https://www.dpd.com/de/de/faq/wie-kann-ich-einen-paketschein-nachdrucken/>.
