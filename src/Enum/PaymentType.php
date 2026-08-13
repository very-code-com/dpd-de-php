<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/**
 * `PaymentType`: cash-on-delivery payment method.
 *
 * @deprecated DPD fully discontinued the "Nachnahme" (cash on delivery) service on 2020-05-11
 *             (see the DPD Cloud Service changelog). The type is kept 1:1 with the WSDL for
 *             completeness / older accounts that may still have it enabled, but new integrations
 *             should not rely on it.
 */
enum PaymentType: string
{
    case Cash   = 'Cash';
    case Cheque = 'Cheque';
}
