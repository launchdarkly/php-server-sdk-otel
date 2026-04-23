<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry;

/**
 * Package-level metadata for the LaunchDarkly OpenTelemetry integration.
 *
 * This class is an implementation detail; consumer code must not depend on
 * it. The constant below is managed by release-please via the
 * `x-release-please-version` inline marker and the `extra-files` entry in
 * `release-please-config.json`, which rewrites it on every release.
 *
 * @internal
 *
 * @psalm-api
 */
final class Package
{
    /**
     * Current version of the launchdarkly/server-sdk-otel package. Kept in
     * sync with the Packagist release tag by release-please.
     */
    public const VERSION = '0.1.0'; // x-release-please-version
}
