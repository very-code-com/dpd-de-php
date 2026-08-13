<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/**
 * `AddressType` from the WSDL, used for `ShipAddress` (setOrder / getOrderStatus),
 * `ShopAddress` (getParcelShopFinder response) and `SearchAddress` full addresses.
 *
 * All fields are optional per the WSDL, but DPD's business rules effectively require
 * Name (or Company), Street, HouseNo, ZipCode, City and Country for a valid shipment -
 * this is enforced by {@see \VeryCodeCom\DpdDe\Internal\Validator\OrderValidator}, not here,
 * so the DTO stays a faithful 1:1 mirror of the WSDL type.
 */
final class Address
{
    public function __construct(
        public readonly ?string $company = null,
        public readonly ?string $salutation = null,
        public readonly ?string $name = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $street = null,
        public readonly ?string $houseNo = null,
        /** ISO 3166-1 alpha-2 country code recommended, e.g. 'DE', 'AT', 'CH' (Alpha3/numeric/name also accepted by DPD). */
        public readonly ?string $country = null,
        public readonly ?string $zipCode = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $phone = null,
        public readonly ?string $mail = null,
        public readonly ?string $gender = null,
    ) {
    }

    /** Uppercased country code, or null when not set. */
    public function getCountryCode(): ?string
    {
        return $this->country !== null ? strtoupper($this->country) : null;
    }
}
