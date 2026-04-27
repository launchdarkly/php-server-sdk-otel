<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry\Tests;

use LaunchDarkly\OpenTelemetry\TracingHookOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Hand-rolled PSR-3 logger that records every call made against it.
 *
 * @psalm-suppress MissingTemplateParam
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $calls = [];

    /**
     * @param mixed             $level
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->calls[] = [
            'level' => (string) $level,
            'message' => (string) $message,
        ];
    }
}

class TracingHookOptionsTest extends TestCase
{
    private const INVALID_ENVIRONMENT_ID_MESSAGE =
        'LaunchDarkly Tracing Hook: Invalid environmentId provided. It must be a non-empty string.';

    public function testDefaultsWhenNoArgs(): void
    {
        $options = new TracingHookOptions();

        $this->assertFalse($options->includeValue);
        $this->assertFalse($options->addSpans);
        $this->assertNull($options->environmentId);
        $this->assertNull($options->logger);
    }

    public function testExplicitValuesAreStored(): void
    {
        $logger = new SpyLogger();

        $options = new TracingHookOptions(
            includeValue: true,
            addSpans: true,
            environmentId: 'env-abc',
            logger: $logger,
        );

        $this->assertTrue($options->includeValue);
        $this->assertTrue($options->addSpans);
        $this->assertSame('env-abc', $options->environmentId);
        $this->assertSame($logger, $options->logger);
    }

    public function testValidEnvironmentIdPassesThrough(): void
    {
        $options = new TracingHookOptions(environmentId: 'env-123');

        $this->assertSame('env-123', $options->environmentId);
    }

    public function testEmptyStringEnvironmentIdIsNulled(): void
    {
        $logger = new SpyLogger();

        $options = new TracingHookOptions(environmentId: '', logger: $logger);

        $this->assertNull($options->environmentId);
    }

    public function testWhitespaceOnlyEnvironmentIdIsNulled(): void
    {
        $logger = new SpyLogger();

        $options = new TracingHookOptions(environmentId: "  \t\n", logger: $logger);

        $this->assertNull($options->environmentId);
    }

    public function testInvalidEnvironmentIdLogsWarningWhenLoggerProvided(): void
    {
        $logger = new SpyLogger();

        new TracingHookOptions(environmentId: '', logger: $logger);

        $this->assertCount(1, $logger->calls);
        $this->assertSame('warning', $logger->calls[0]['level']);
        $this->assertSame(self::INVALID_ENVIRONMENT_ID_MESSAGE, $logger->calls[0]['message']);
    }

    public function testInvalidEnvironmentIdIsSilentWhenNoLoggerProvided(): void
    {
        $options = new TracingHookOptions(environmentId: '');

        $this->assertNull($options->environmentId);
        $this->assertNull($options->logger);
    }

    public function testValidEnvironmentIdDoesNotLogWarning(): void
    {
        $logger = new SpyLogger();

        new TracingHookOptions(environmentId: 'env-xyz', logger: $logger);

        $this->assertSame([], $logger->calls);
    }
}
