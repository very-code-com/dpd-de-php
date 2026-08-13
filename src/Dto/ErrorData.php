<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Dto;

/** `ErrorDataType`: one structured error entry returned by DPD in `ErrorDataList`. */
final class ErrorData
{
    public function __construct(
        public readonly int $errorId,
        public readonly string $errorCode,
        public readonly string $errorMsgShort,
        public readonly string $errorMsgLong,
    ) {
    }

    /** @return array{errorId: int, errorCode: string, messageShort: string, messageLong: string} */
    public function toArray(): array
    {
        return [
            'errorId'     => $this->errorId,
            'errorCode'   => $this->errorCode,
            'messageShort' => $this->errorMsgShort,
            'messageLong'  => $this->errorMsgLong,
        ];
    }
}
