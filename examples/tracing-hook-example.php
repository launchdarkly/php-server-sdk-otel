<?php

/**
 * End-to-end runnable example for the LaunchDarkly OpenTelemetry
 * tracing hook.
 *
 * This script wires up an OpenTelemetry TracerProvider that exports spans
 * to stdout via the ConsoleSpanExporter that ships with
 * `open-telemetry/sdk`, registers a `TracingHook` on an `LDClient`, and
 * evaluates a flag inside a parent span. The client is configured to
 * evaluate against an in-process `TestData` feature requester with events
 * disabled, so it runs to completion with no network or real SDK key.
 *
 * How to run (from the repository root):
 *
 *     composer install
 *     php examples/tracing-hook-example.php
 *
 * Expected output on stdout: one pretty-printed JSON blob per span. You
 * should see an `LDClient.variation` wrapper span (because `addSpans` is
 * enabled) and an `example-parent` span that carries the `feature_flag`
 * event with the evaluated attributes. The evaluated boolean value is
 * printed to stderr so the stdout trace output stays clean.
 *
 * The final `$tracerProvider->shutdown()` call doubles as the reference
 * pattern described in the README's "PHP-FPM and short-lived request
 * lifecycles" section — under FPM you typically wire this into
 * `register_shutdown_function` so buffered spans flush before the
 * process returns to the pool.
 */

declare(strict_types=1);

use LaunchDarkly\Integrations\TestData;
use LaunchDarkly\LDClient;
use LaunchDarkly\LDContext;
use LaunchDarkly\OpenTelemetry\TracingHook;
use LaunchDarkly\OpenTelemetry\TracingHookOptions;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

require __DIR__ . '/../vendor/autoload.php';

// ----- 1. Build a minimal OpenTelemetry tracer provider that writes to
// stdout, and register it as the global provider so the TracingHook picks
// it up via OpenTelemetry\API\Globals.
$exporter       = (new ConsoleSpanExporterFactory())->create();
$tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->buildAndRegisterGlobal();
$tracer = $tracerProvider->getTracer('example-app');

// ----- 2. Seed an in-process flag so the variation call resolves against
// real flag data rather than falling through to the default value.
$td   = new TestData();
$flag = $td->flag('example-flag')
    ->booleanFlag()
    ->valueForAll(true);
$td->update($flag);

// ----- 3. Build an LDClient with the tracing hook attached. `send_events`
// is disabled so the client does not try to contact LaunchDarkly to
// deliver analytics events.
$client = new LDClient('fake-sdk-key', [
    'feature_requester' => $td,
    'send_events'       => false,
    'hooks'             => [
        new TracingHook(new TracingHookOptions(
            includeValue: true,
            addSpans: true,
            environmentId: 'env-example',
        )),
    ],
]);

// ----- 4. Evaluate the flag inside an active parent span.
$parent = $tracer->spanBuilder('example-parent')->startSpan();
$scope  = $parent->activate();
try {
    $value = $client->variation(
        'example-flag',
        LDContext::create('user-123'),
        false,
    );
    fwrite(STDERR, 'example-flag evaluated to: ' . var_export($value, true) . PHP_EOL);
} finally {
    $scope->detach();
    $parent->end();
}

// ----- 5. Flush. Under PHP-FPM this is what `register_shutdown_function`
// would typically invoke; see the README for details.
$tracerProvider->shutdown();
