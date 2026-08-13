<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Internal\Validator;

use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Enum\PaymentType;
use VeryCodeCom\DpdDe\Enum\ShipService;

/**
 * Local pre-flight validation for setOrder items, applying the field constraints and business
 * rules documented in the DPD Cloud Service Webservice documentation (error code appendix), so
 * mistakes are caught before any network call.
 *
 * Field constraints:
 *   - Weight: 0-31.5 kg                                          (CLOUD_API_ORDER_WEIGHT)
 *   - Content / YourInternalID / Reference1: required, 1-35 chars  (CLOUD_API_ORDER_CONTENT /
 *     _INTERNALID / _REFERENCE1, mandatory in practice although the WSDL marks them optional)
 *   - Reference2: optional, max 35 chars                           (CLOUD_API_ORDER_REFERENCE2)
 *   - COD Purpose: max 14 chars                                    (CLOUD_API_ORDER_CODPURPOSE)
 *   - Address Company: 2-50 chars                                  (CLOUD_ADDRESS_COMPANY)
 *   - Address Name (first+last combined): 2-50 chars               (CLOUD_ADDRESS_NAME)
 *   - Address Salutation: 2-10 chars                               (CLOUD_ADDRESS_SEXCODE)
 *   - Address Street: 1-50 chars                                   (CLOUD_ADDRESS_STREET)
 *   - Address HouseNo: 1-8 chars                                   (CLOUD_ADDRESS_HOUSENO)
 *   - Address City: 1-50 chars                                     (CLOUD_ADDRESS_CITY)
 *   - Address Phone: 5-20 chars, digits and " +-()" only            (CLOUD_ADDRESS_PHONE)
 *   - Address Mail: syntactically valid, max 50 chars               (CLOUD_ADDRESS_MAIL)
 *   - Address State (ISO 3166-2): exactly 2 chars, when provided    (CLOUD_STATE_STATESHORT)
 *
 * Business rules:
 *   - State is mandatory for USA/Canada and forbidden everywhere else
 *                                                    (CLOUD_ADDRESS_STATE / CLOUD_STATE_STATESHORT)
 *   - Gender may only be set together with a name                  (CLOUD_ADDRESS_GENDER)
 *   - Predict products need an e-mail address or a phone number    (CLOUD_ADDRESS_NEEDMAILORSMS)
 *   - Classic_Return needs a phone number and cannot be batched    (CLOUD_API_ORDER_CLASSICRETURN_NOBULKPRINT)
 *   - Shop_Delivery needs a ParcelShopID                           (CLOUD_API_ORDER_PARCELSHOP)
 *   - Express 8:30-18:00 is Germany-domestic only                (CLOUD_API_ORDER_EXPRESS_DEU_COUNTRY)
 *   - COD amount 1.00-5000.00 EUR; cash up to 2500, cheque up to 5000
 *                                                    (CLOUD_API_ORDER_CODAMOUNT / _COD_PAYMENT)
 *   - COD data and *_COD products must be used together
 *
 * This is a best-effort local check that mirrors DPD's own validation; it does not replace
 * calling `setOrder` with `OrderAction::CheckOrderData` for a full server-side dry run, and it
 * cannot cover rules that depend on DPD's own data (address existence, per-country product
 * availability, pickup depot routing).
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class OrderValidator
{
    private const MAX_REFERENCE_FIELD = 35;
    private const MAX_COD_PURPOSE = 14;
    private const MAX_ITEMS_PER_CALL = 30;

    private const COD_MIN_AMOUNT = 1.0;
    private const COD_MAX_AMOUNT = 5000.0;
    private const COD_MAX_CASH = 2500.0;

    /**
     * Country spellings DPD accepts (Alpha-2, Alpha-3, ISO-3166 numeric, country name) for the
     * three countries that carry validation rules. Compared after stripping non-alphanumerics,
     * so "U.S.A." and "United-States" also match.
     *
     * @var array<string, list<string>>
     */
    private const COUNTRY_ALIASES = [
        'DE' => ['DE', 'DEU', '276', 'DEUTSCHLAND', 'GERMANY', 'ALLEMAGNE'],
        'US' => ['US', 'USA', '840', 'UNITEDSTATES', 'UNITEDSTATESOFAMERICA', 'VEREINIGTESTAATEN', 'VEREINIGTESTAATENVONAMERIKA'],
        'CA' => ['CA', 'CAN', '124', 'CANADA', 'KANADA'],
    ];

    /**
     * @param list<OrderItem> $items
     * @return list<string> Validation error messages; empty array = valid.
     */
    public function validate(array $items): array
    {
        $errors = [];

        if ($items === []) {
            $errors[] = 'At least one OrderItem is required.';
            return $errors;
        }

        if (count($items) > self::MAX_ITEMS_PER_CALL) {
            $errors[] = sprintf(
                'DPD Cloud Service accepts at most %d OrderData entries per setOrder call, got %d.',
                self::MAX_ITEMS_PER_CALL,
                count($items),
            );
        }

        // Classic_Return orders can only be started one at a time (CLOUD_API_ORDER_CLASSICRETURN_NOBULKPRINT).
        if (count($items) > 1) {
            foreach ($items as $index => $item) {
                if ($item->parcel->shipService === ShipService::ClassicReturn) {
                    $errors[] = "OrderItem[{$index}]: Classic_Return orders can only be started one at a time "
                        . '(CLOUD_API_ORDER_CLASSICRETURN_NOBULKPRINT); send it in its own setOrder call.';
                }
            }
        }

        foreach ($items as $index => $item) {
            $errors = [...$errors, ...$this->validateItem($item, $index)];
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateItem(OrderItem $item, int $index): array
    {
        $prefix = "OrderItem[{$index}]";

        return [
            ...$this->validateParcel($item, $prefix),
            ...$this->validateAddress($item->shipAddress, $prefix),
            ...$this->validateProductRules($item, $prefix),
            ...$this->validateCod($item, $prefix),
        ];
    }

    /** @return list<string> */
    private function validateParcel(OrderItem $item, string $prefix): array
    {
        $errors = [];
        $parcel = $item->parcel;

        $weight = $parcel->weightKg;
        if ($weight < 0 || $weight > 31.5) {
            $errors[] = "{$prefix}: Weight must be between 0 and 31.5 kg, got {$weight}.";
        }

        // Content / YourInternalID / Reference1 are minOccurs="0" in the WSDL and described as
        // "individuelle Angabe" in the documentation, but DPD rejects orders that omit any of
        // them (2125 / 2122 / 2123). Only Reference2 is genuinely optional.
        $errors = [...$errors, ...$this->validateRequiredField($parcel->content, "{$prefix}: Content", 'CLOUD_API_ORDER_CONTENT')];
        $errors = [...$errors, ...$this->validateRequiredField($parcel->yourInternalId, "{$prefix}: YourInternalID", 'CLOUD_API_ORDER_INTERNALID')];
        $errors = [...$errors, ...$this->validateRequiredField($parcel->reference1, "{$prefix}: Reference1", 'CLOUD_API_ORDER_REFERENCE1')];
        $errors = [...$errors, ...$this->validateMaxLength($parcel->reference2, self::MAX_REFERENCE_FIELD, "{$prefix}: Reference2")];

        return $errors;
    }

    /** @return list<string> */
    private function validateAddress(Address $addr, string $prefix): array
    {
        $errors = [];

        if ($addr->company !== null) {
            $errors = [...$errors, ...$this->validateLengthRange($addr->company, 2, 50, "{$prefix}: ShipAddress.Company")];
        }

        $fullName = $this->fullName($addr);
        if ($fullName === '' && $addr->company === null) {
            $errors[] = "{$prefix}: ShipAddress must provide either Company or Name/FirstName+LastName.";
        } elseif ($fullName !== '') {
            $errors = [...$errors, ...$this->validateLengthRange($fullName, 2, 50, "{$prefix}: ShipAddress.Name")];
        }

        if ($addr->salutation !== null) {
            $errors = [...$errors, ...$this->validateLengthRange($addr->salutation, 2, 10, "{$prefix}: ShipAddress.Salutation")];
        }

        // CLOUD_ADDRESS_GENDER: gender may only be sent when a name is present.
        if ($addr->gender !== null && trim($addr->gender) !== '' && $fullName === '') {
            $errors[] = "{$prefix}: ShipAddress.Gender may only be set together with a name (CLOUD_ADDRESS_GENDER).";
        }

        if ($addr->street === null || trim($addr->street) === '') {
            $errors[] = "{$prefix}: ShipAddress.Street is required.";
        } else {
            $errors = [...$errors, ...$this->validateLengthRange($addr->street, 1, 50, "{$prefix}: ShipAddress.Street")];
        }

        if ($addr->houseNo === null || trim($addr->houseNo) === '') {
            $errors[] = "{$prefix}: ShipAddress.HouseNo is required.";
        } else {
            $errors = [...$errors, ...$this->validateLengthRange($addr->houseNo, 1, 8, "{$prefix}: ShipAddress.HouseNo")];
        }

        if ($addr->city === null || trim($addr->city) === '') {
            $errors[] = "{$prefix}: ShipAddress.City is required.";
        } else {
            $errors = [...$errors, ...$this->validateLengthRange($addr->city, 1, 50, "{$prefix}: ShipAddress.City")];
        }

        if ($addr->zipCode === null || trim($addr->zipCode) === '') {
            $errors[] = "{$prefix}: ShipAddress.ZipCode is required.";
        }

        if ($addr->country === null || trim($addr->country) === '') {
            $errors[] = "{$prefix}: ShipAddress.Country is required.";
        }

        if ($addr->phone !== null) {
            $errors = [...$errors, ...$this->validateLengthRange($addr->phone, 5, 20, "{$prefix}: ShipAddress.Phone")];

            // Documented character set: digits, spaces, "+", "-", "(" and ")".
            if (preg_match('/^[0-9 +\-()]+$/', $addr->phone) !== 1) {
                $errors[] = "{$prefix}: ShipAddress.Phone may only contain digits, spaces and the characters +, -, ( and ) "
                    . "(CLOUD_ADDRESS_PHONE), got '{$addr->phone}'.";
            }
        }

        if ($addr->mail !== null) {
            $errors = [...$errors, ...$this->validateMaxLength($addr->mail, 50, "{$prefix}: ShipAddress.Mail")];

            if (filter_var($addr->mail, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = "{$prefix}: ShipAddress.Mail is not a valid e-mail address (CLOUD_ADDRESS_MAIL), got '{$addr->mail}'.";
            }
        }

        return [...$errors, ...$this->validateState($addr, $prefix)];
    }

    /**
     * State is mandatory for the USA and Canada and must not be sent for any other country.
     *
     * @return list<string>
     */
    private function validateState(Address $addr, string $prefix): array
    {
        $errors = [];
        $needsState = $this->isCountry($addr->country, 'US') || $this->isCountry($addr->country, 'CA');
        $hasState = $addr->state !== null && trim($addr->state) !== '';

        if ($needsState && !$hasState) {
            $errors[] = "{$prefix}: ShipAddress.State is mandatory for USA and Canada (CLOUD_ADDRESS_STATE).";
        }

        if (!$needsState && $hasState && $addr->country !== null && trim($addr->country) !== '') {
            $errors[] = "{$prefix}: ShipAddress.State must only be set for USA and Canada (CLOUD_ADDRESS_STATE), "
                . "got '{$addr->state}' for country '{$addr->country}'.";
        }

        if ($hasState && mb_strlen((string) $addr->state) !== 2) {
            $errors[] = "{$prefix}: ShipAddress.State must be exactly 2 characters (ISO 3166-2), got '{$addr->state}'.";
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateProductRules(OrderItem $item, string $prefix): array
    {
        $errors = [];
        $service = $item->parcel->shipService;
        $addr = $item->shipAddress;

        if ($service->isPredict() && !$this->hasContactDetails($addr)) {
            $errors[] = "{$prefix}: {$service->value} requires an e-mail address or a phone number on the ship address "
                . '(CLOUD_ADDRESS_NEEDMAILORSMS).';
        }

        if ($service === ShipService::ClassicReturn && ($addr->phone === null || trim($addr->phone) === '')) {
            $errors[] = "{$prefix}: Classic_Return requires a phone number on the ship address.";
        }

        if ($service->requiresParcelShopId() && $item->parcelShopId <= 0) {
            $errors[] = "{$prefix}: {$service->value} requires a ParcelShopID; look one up with findParcelShops() "
                . '(CLOUD_API_ORDER_PARCELSHOP).';
        }

        if ($service->isDomesticExpressOnly() && !$this->isCountry($addr->country, 'DE')) {
            $errors[] = "{$prefix}: {$service->value} is only available within Germany; use Express_International "
                . "for other countries (CLOUD_API_ORDER_EXPRESS_DEU_COUNTRY), got country '{$addr->country}'.";
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateCod(OrderItem $item, string $prefix): array
    {
        $errors = [];
        $service = $item->parcel->shipService;
        $cod = $item->parcel->cod;

        if ($cod === null) {
            if ($service->isCod()) {
                $errors[] = "{$prefix}: {$service->value} requires COD data (amount and payment type).";
            }

            return $errors;
        }

        if (!$service->isCod()) {
            $errors[] = "{$prefix}: COD data was provided but {$service->value} is not a cash-on-delivery product.";
        }

        $errors = [...$errors, ...$this->validateMaxLength($cod->purpose, self::MAX_COD_PURPOSE, "{$prefix}: COD.Purpose")];

        if ($cod->amount < self::COD_MIN_AMOUNT || $cod->amount > self::COD_MAX_AMOUNT) {
            $errors[] = sprintf(
                '%s: COD.Amount must be between %.2f and %.2f EUR (CLOUD_API_ORDER_CODAMOUNT), got %.2f.',
                $prefix,
                self::COD_MIN_AMOUNT,
                self::COD_MAX_AMOUNT,
                $cod->amount,
            );
        } elseif ($cod->payment === PaymentType::Cash && $cod->amount > self::COD_MAX_CASH) {
            $errors[] = sprintf(
                '%s: COD.Amount paid in cash is limited to %.2f EUR (CLOUD_API_ORDER_COD_PAYMENT), got %.2f; use a cheque above that.',
                $prefix,
                self::COD_MAX_CASH,
                $cod->amount,
            );
        }

        return $errors;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function fullName(Address $addr): string
    {
        return trim(($addr->firstName ?? '') . ' ' . ($addr->lastName ?? '')) ?: trim($addr->name ?? '');
    }

    /** Predict needs at least one way to notify the recipient. */
    private function hasContactDetails(Address $addr): bool
    {
        return ($addr->mail !== null && trim($addr->mail) !== '')
            || ($addr->phone !== null && trim($addr->phone) !== '');
    }

    /** Whether the free-form country value denotes the given Alpha-2 country. */
    private function isCountry(?string $country, string $alpha2): bool
    {
        if ($country === null) {
            return false;
        }

        $normalised = strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $country));

        return $normalised !== '' && in_array($normalised, self::COUNTRY_ALIASES[$alpha2], true);
    }

    /**
     * A field DPD treats as mandatory even though the WSDL marks it optional.
     *
     * @return list<string>
     */
    private function validateRequiredField(?string $value, string $label, string $dpdErrorCode): array
    {
        if ($value === null || trim($value) === '') {
            return [sprintf('%s is required by DPD (1-%d characters, %s).', $label, self::MAX_REFERENCE_FIELD, $dpdErrorCode)];
        }

        return $this->validateMaxLength($value, self::MAX_REFERENCE_FIELD, $label);
    }

    /** @return list<string> */
    private function validateMaxLength(?string $value, int $max, string $label): array
    {
        if ($value !== null && mb_strlen($value) > $max) {
            return ["{$label}: max {$max} characters, got " . mb_strlen($value) . '.'];
        }
        return [];
    }

    /** @return list<string> */
    private function validateLengthRange(string $value, int $min, int $max, string $label): array
    {
        // DPD counts characters, not bytes; "Musterstraße" is 12 characters but 13 bytes.
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            return ["{$label}: must be between {$min} and {$max} characters, got {$len}."];
        }
        return [];
    }
}
