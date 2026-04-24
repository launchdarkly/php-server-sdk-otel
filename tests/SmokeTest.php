<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry\Tests;

use LaunchDarkly\LDClient;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test that confirms the package installs and its transitive dependencies autoload.
 * Real test coverage arrives alongside the hook implementation in later PRs.
 */
class SmokeTest extends TestCase
{
    public function testLaunchDarklySdkIsAvailable(): void
    {
        $this->assertTrue(class_exists(LDClient::class));
    }
}
