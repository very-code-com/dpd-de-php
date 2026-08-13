<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;
use VeryCodeCom\DpdDe\Exception\DpdCloudAuthException;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;
use VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException;
use VeryCodeCom\DpdDe\Exception\DpdCloudTransportException;
use VeryCodeCom\DpdDe\Exception\DpdCloudValidationException;

final class ExceptionTest extends TestCase
{
    // -- DpdCloudException base --------------------------------------------------

    public function testGetRawResponseDefaultsToNull(): void
    {
        $e = new DpdCloudException('boom');
        self::assertNull($e->getRawResponse());
    }

    public function testWithRawResponseIsFluentAndStores(): void
    {
        $e = new DpdCloudException('boom');
        $result = $e->withRawResponse('<xml/>');

        self::assertSame($e, $result);
        self::assertSame('<xml/>', $e->getRawResponse());
    }

    public function testGetDebugReportContainsClassAndMessage(): void
    {
        $e = new DpdCloudException('something failed');
        $report = $e->getDebugReport();

        self::assertStringContainsString(DpdCloudException::class, $report);
        self::assertStringContainsString('something failed', $report);
        self::assertStringContainsString('Stack trace', $report);
    }

    public function testGetDebugReportIncludesRawResponseWhenSet(): void
    {
        $e = (new DpdCloudException('failed'))->withRawResponse('<raw>data</raw>');
        $report = $e->getDebugReport();

        self::assertStringContainsString('Raw DPD response', $report);
        self::assertStringContainsString('<raw>data</raw>', $report);
    }

    public function testGetDebugReportOmitsRawResponseSectionWhenNotSet(): void
    {
        $e = new DpdCloudException('failed');
        self::assertStringNotContainsString('Raw DPD response', $e->getDebugReport());
    }

    public function testGetDebugReportIncludesPreviousException(): void
    {
        $previous = new \RuntimeException('root cause');
        $e = new DpdCloudException('failed', 0, $previous);
        $report = $e->getDebugReport();

        self::assertStringContainsString('Caused by', $report);
        self::assertStringContainsString('root cause', $report);
    }

    // -- DpdCloudApiException --------------------------------------------------

    public function testApiExceptionStoresErrors(): void
    {
        $errors = [
            ['errorId' => 2121, 'errorCode' => 'CLOUD_API_ORDER_WEIGHT', 'messageShort' => 'Invalid weight', 'messageLong' => 'Gewicht: 0 bis 31,5 Kg.'],
        ];
        $e = new DpdCloudApiException('setOrder failed', $errors);

        self::assertSame($errors, $e->errors);
    }

    public function testApiExceptionHasCodeTrue(): void
    {
        $errors = [['errorId' => 1, 'errorCode' => 'CLOUD_API_ORDER_WEIGHT', 'messageShort' => 's', 'messageLong' => 'l']];
        $e = new DpdCloudApiException('failed', $errors);

        self::assertTrue($e->hasCode('CLOUD_API_ORDER_WEIGHT'));
        self::assertFalse($e->hasCode('CLOUD_API_OTHER'));
    }

    public function testApiExceptionGetFormattedErrorsPrefersLongMessage(): void
    {
        $errors = [['errorId' => 1, 'errorCode' => 'CODE_A', 'messageShort' => 'short', 'messageLong' => 'long message']];
        $e = new DpdCloudApiException('failed', $errors);

        self::assertSame(['CODE_A: long message'], $e->getFormattedErrors());
    }

    public function testApiExceptionGetFormattedErrorsFallsBackToShortMessage(): void
    {
        $errors = [['errorId' => 1, 'errorCode' => 'CODE_A', 'messageShort' => 'short', 'messageLong' => '']];
        $e = new DpdCloudApiException('failed', $errors);

        self::assertSame(['CODE_A: short'], $e->getFormattedErrors());
    }

    public function testApiExceptionDefaultsToEmptyErrors(): void
    {
        $e = new DpdCloudApiException('failed');
        self::assertSame([], $e->errors);
        self::assertFalse($e->hasCode('ANYTHING'));
    }

    // -- DpdCloudAuthException --------------------------------------------------

    public function testAuthExceptionStoresErrorCode(): void
    {
        $e = new DpdCloudAuthException('Partner Credentials invalid.', 'CLOUD_API_PARTNERCREDENTIALS');
        self::assertSame('CLOUD_API_PARTNERCREDENTIALS', $e->errorCode);
        self::assertSame('Partner Credentials invalid.', $e->getMessage());
    }

    public function testAuthExceptionDefaultsErrorCodeToEmptyString(): void
    {
        $e = new DpdCloudAuthException('failed');
        self::assertSame('', $e->errorCode);
    }

    // -- DpdCloudValidationException --------------------------------------------------

    public function testValidationExceptionStoresErrorsAndBuildsMessage(): void
    {
        $e = new DpdCloudValidationException(['Weight must be between 0 and 31.5 kg.', 'Street is required.']);

        self::assertSame(['Weight must be between 0 and 31.5 kg.', 'Street is required.'], $e->errors);
        self::assertStringContainsString('Weight must be between 0 and 31.5 kg.', $e->getMessage());
        self::assertStringContainsString('Street is required.', $e->getMessage());
    }

    // -- type hierarchy --------------------------------------------------

    public function testAllExceptionsExtendBaseException(): void
    {
        self::assertInstanceOf(DpdCloudException::class, new DpdCloudApiException('x'));
        self::assertInstanceOf(DpdCloudException::class, new DpdCloudAuthException('x'));
        self::assertInstanceOf(DpdCloudException::class, new DpdCloudValidationException(['x']));
        self::assertInstanceOf(DpdCloudException::class, new DpdCloudTransportException('x'));
        self::assertInstanceOf(DpdCloudException::class, new DpdCloudResponseParseException('x'));
    }

    public function testAllExceptionsExtendRuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new DpdCloudException('x'));
    }
}
