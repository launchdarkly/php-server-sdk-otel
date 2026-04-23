<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry;

/**
 * Package-level metadata for the LaunchDarkly OpenTelemetry integration.
 *
 * @internal
 *
 * @psalm-api
 */
final class Package
{
    /**
     * The current version of the launchdarkly/server-sdk-otel package.
     *
     * @var string
     */
    public const VERSION = '0.1.0'; // x-release-please-version
}
