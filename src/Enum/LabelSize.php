<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/** `LabelSizeType`: physical label size/format requested for setOrder. */
enum LabelSize: string
{
    case PdfA4 = 'PDF_A4';
    case PdfA6 = 'PDF_A6';

    /**
     * @deprecated Listed in the WSDL but marked "wird aktuell nicht unterstützt" in the docs.
     *             DPD accepts the value without an error and answers with a PDF payload anyway,
     *             so requesting it silently gives you a PDF, not ZPL.
     */
    case ZplA6 = 'ZPL_A6';
}
