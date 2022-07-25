<?php

namespace Makaronnik\RabbitManager\Test\Unit;

use Makaronnik\RabbitManager\Exceptions\FailedConnectionException;
use Makaronnik\RabbitManager\Helpers\LogHelper;
use PHPUnit\Framework\TestCase;

class LogHelperTest extends TestCase
{
    /**
     * @return void
     */
    public function testPrepareLogContextWithException(): void
    {
        $exceptionMessage = 'Connection timeout';
        $exceptionCode = 555;
        $currentLine = __FILE__ . ':' . __LINE__;
        $exceptionLine = __FILE__ . ':' . (__LINE__ + 1);
        $exception = new FailedConnectionException($exceptionMessage, $exceptionCode);

        $expectedResult = [
            'currentLine' => $currentLine,
            'sourceOfException' => $exceptionLine,
            'exceptionCode' => $exceptionCode
        ];

        $result = LogHelper::prepareLogContext($currentLine, $exception);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * @return void
     */
    public function testPrepareLogContextWithoutException(): void
    {
        $currentLine = __FILE__ . ':' . __LINE__;

        $expectedResult = [
            'currentLine' => $currentLine
        ];

        $result = LogHelper::prepareLogContext($currentLine);

        $this->assertSame($expectedResult, $result);
    }
}
