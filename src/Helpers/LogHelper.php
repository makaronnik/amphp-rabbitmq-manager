<?php

namespace Makaronnik\RabbitManager\Helpers;

use Throwable;

final class LogHelper
{
    /**
     * @param string $currentLine
     * @param Throwable|null $exception
     * @return array{currentLine: string, sourceOfException: string, exceptionCode: int}
     */
    public static function prepareLogContext(
        string $currentLine,
        ?Throwable $exception = null
    ): array {
        if ($exception instanceof Throwable) {
            $sourceOfException = $exception->getFile() . ':' . $exception->getLine();
            $exceptionCode = (int) $exception->getCode();
        } else {
            $sourceOfException = '';
            $exceptionCode = 0;
        }

        return [
            'currentLine' => $currentLine,
            'sourceOfException' => $sourceOfException,
            'exceptionCode' => $exceptionCode
        ];
    }
}
