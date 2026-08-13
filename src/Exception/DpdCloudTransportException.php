<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Exception;

/** Thrown on network / HTTP-level failures (cURL errors, non-2xx responses without a parseable body). */
class DpdCloudTransportException extends DpdCloudException
{
}
