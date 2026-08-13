<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

use VeryCodeCom\DpdDe\Enum\PaymentType;

/**
 * `CODType`: cash-on-delivery ("Nachnahme") data attached to a parcel.
 *
 * @deprecated DPD fully discontinued the Nachnahme service on 2020-05-11. The type is kept
 *             1:1 with the WSDL (which still declares it) for completeness, but new
 *             integrations should not rely on it. DPD will reject `Classic_COD`-family
 *             ShipServices with a business error on modern accounts.
 */
final class Cod
{
    public function __construct(
        public readonly float $amount,
        public readonly PaymentType $payment,
        public readonly ?string $purpose = null,
    ) {
    }
}
