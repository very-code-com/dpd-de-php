<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/**
 * Which wire protocol {@see \VeryCodeCom\DpdDe\DpdCloudClient} should use to talk to DPD Cloud Service.
 *
 * `Soap` (default) follows the live WSDL and is the recommended choice. `Rest` is a
 * lighter-weight alternative covering the same operations except `getParcelLifeCycle`, which
 * always falls back to SOAP. See docs/DPD-NOTES.md for the REST contract.
 */
enum TransportMode
{
    case Soap;
    case Rest;
}
