<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `SearchAddressType`: address-based search criteria for getParcelShopFinder. */
final class SearchAddress
{
    public function __construct(
        public readonly ?string $street = null,
        public readonly ?string $houseNo = null,
        public readonly ?string $zipCode = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
    ) {
    }
}
