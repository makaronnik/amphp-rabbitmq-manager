<?php

namespace Makaronnik\RabbitManager\Helpers;

use Throwable;

final class LogHelper
{
    /**
     * @param string $currentLine
     * @param Throwable|null $exception
     * @return array{currentLine: string, exceptionCode?: int, sourceOfException?: non-empty-string}
     */
    public static function prepareLogContext(
        string $currentLine,
        ?Throwable $exception = null
    ): array {
        $result = ['currentLine' => $currentLine];

        if ($exception instanceof Throwable) {
            $sourceOfException = $exception->getFile() . ':' . $exception->getLine();
            $exceptionCode = (int) $exception->getCode();

            $result = array_merge($result, [
                'sourceOfException' => $sourceOfException,
                'exceptionCode' => $exceptionCode
            ]);
        }

        return $result;
    }
}
