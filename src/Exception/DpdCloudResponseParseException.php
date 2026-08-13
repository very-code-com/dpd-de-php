<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Exception;

/** Thrown when a DPD response cannot be parsed (malformed XML/JSON, or an unexpected shape). */
class DpdCloudResponseParseException extends DpdCloudException
{
}
