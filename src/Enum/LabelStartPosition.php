<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Enum;

/** `LabelStartPositionType`: where on the page/roll the label print starts. */
enum LabelStartPosition: string
{
    case UpperLeft  = 'UpperLeft';
    case UpperRight = 'UpperRight';
    case LowerLeft  = 'LowerLeft';
    case LowerRight = 'LowerRight';
}
