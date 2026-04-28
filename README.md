# LaunchDarkly Server-Side SDK for PHP — OpenTelemetry integration

[![Run CI](https://github.com/launchdarkly/php-server-sdk-otel/actions/workflows/ci.yml/badge.svg)](https://github.com/launchdarkly/php-server-sdk-otel/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/launchdarkly/server-sdk-otel.svg?style=flat-square)](https://packagist.org/packages/launchdarkly/server-sdk-otel)
[![Documentation](https://img.shields.io/static/v1?label=GitHub+Pages&message=API+reference&color=00add8)](https://launchdarkly.github.io/php-server-sdk-otel)

## LaunchDarkly overview

[LaunchDarkly](https://www.launchdarkly.com) is a feature management platform that serves trillions of feature flags daily to help teams build better software, faster. [Get started](https://docs.launchdarkly.com/home/getting-started) using LaunchDarkly today!

## Overview

This package provides an [OpenTelemetry](https://opentelemetry.io/) integration for the [LaunchDarkly PHP Server-Side SDK](https://github.com/launchdarkly/php-server-sdk). It exposes a `TracingHook` that, when registered with an `LDClient`, enriches the active OpenTelemetry span with a `feature_flag` span event for every flag evaluation. The event carries the semantic-convention attributes defined by the OpenTelemetry feature-flag specification and the LaunchDarkly OTEL integration specification (see [Attributes emitted](#attributes-emitted)).

Reach for this package when you already have OpenTelemetry instrumentation in place and want flag evaluations to show up on the traces that cover them. The hook is a pure consumer of the SDK's hooks API and adds no network or storage dependencies of its own.

## Supported PHP versions

This package supports PHP 8.1, 8.2, 8.3, and 8.4. It is safe to use under any PHP SAPI (CLI, PHP-FPM, long-running worker pools); see [PHP-FPM and short-lived request lifecycles](#php-fpm-and-short-lived-request-lifecycles) for guidance on flushing spans in request-response environments.

| PHP SAPI | Supported | Notes |
| --- | --- | --- |
| CLI (scripts, workers) | yes | Nothing to configure. |
| PHP-FPM / mod_php (web requests) | yes | See FPM caveats below — span processors must flush before the request ends. |
| Long-running worker pools (RoadRunner, Swoole, etc.) | yes | Same rules as CLI; follow your pool's shutdown hooks to flush the tracer provider. |

## Dependencies

| Dependency | Version |
| --- | --- |
| `launchdarkly/server-sdk` | `>=6.8` (first release exposing the evaluation hooks API) |
| `open-telemetry/api` | `^1.0` |
| `psr/log` | `^1.0`, `^2.0`, or `^3.0` |

`open-telemetry/api` is interface-only. A runtime OpenTelemetry SDK implementation is also required — typically `open-telemetry/sdk` plus at least one exporter (for example `open-telemetry/exporter-otlp` for OTLP/HTTP). This package does not pull the SDK in for you, since most applications already configure one.

If you are installing this package from source before `launchdarkly/server-sdk` has tagged its first hooks-ready release, you may need to track a compatible branch of the parent SDK until that tag lands.

## Installation

```shell
composer require launchdarkly/server-sdk-otel
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use LaunchDarkly\LDClient;
use LaunchDarkly\LDContext;
use LaunchDarkly\OpenTelemetry\TracingHook;
use OpenTelemetry\API\Globals;

// Assumes your application has already configured an OpenTelemetry
// TracerProvider and registered it with OpenTelemetry\API\Globals.
$tracer = Globals::tracerProvider()->getTracer('my-app');

$client = new LDClient('sdk-key', [
    'hooks' => [new TracingHook()],
]);

$span = $tracer->spanBuilder('handle-request')->startSpan();
$scope = $span->activate();
try {
    $enabled = $client->variation(
        'my-flag-key',
        LDContext::create('user-123'),
        false,
    );
    // ...
} finally {
    $scope->detach();
    $span->end();
}
```

When `$client->variation(...)` runs inside the active span, the hook attaches a `feature_flag` event to that span. When no span is active, the hook is a no-op.

## Configuring `TracingHookOptions`

`TracingHookOptions` is an immutable configuration object passed to the `TracingHook` constructor. All arguments are optional.

| Option | Type | Default | Purpose |
| --- | --- | --- | --- |
| `includeValue` | `bool` | `false` | When `true`, attaches the serialized evaluated flag value as `feature_flag.result.value`. Be mindful of cardinality and sensitivity before enabling this in production. |
| `addSpans` | `bool` | `false` | **Experimental.** When `true`, wraps every variation call in an `LDClient.<method>` child span in addition to the event. The exact span structure and naming may change in future releases. |
| `environmentId` | `string\|null` | `null` | Emitted as `feature_flag.set.id`. Takes precedence over the environment ID the LaunchDarkly SDK supplies via `EvaluationSeriesContext`; leave `null` to use the SDK-supplied value when one is available. Empty or whitespace-only input is rejected and stored as `null` (a warning is logged if a `logger` is supplied). |
| `logger` | `Psr\Log\LoggerInterface\|null` | `null` | Receives construction-time warnings from the options object (for example, an invalid `environmentId`). When `null`, no diagnostic output is produced. |

Example:

```php
use LaunchDarkly\OpenTelemetry\TracingHook;
use LaunchDarkly\OpenTelemetry\TracingHookOptions;

$hook = new TracingHook(new TracingHookOptions(
    includeValue: true,
    addSpans: false,
    environmentId: 'your-environment-id',
));
```

## Attributes emitted

The hook emits the following attributes on the `feature_flag` span event. Attribute names and semantics follow the [LaunchDarkly OTEL integration specification][spec].

Required attributes (always emitted when the hook emits an event):

| Attribute | Value |
| --- | --- |
| `feature_flag.key` | The flag key being evaluated. |
| `feature_flag.provider.name` | The fixed string `LaunchDarkly`. |
| `feature_flag.context.id` | The canonical key of the `LDContext` the flag is being evaluated for. |

Optional attributes (emitted only when the corresponding condition is met):

| Attribute | Emitted when |
| --- | --- |
| `feature_flag.result.value` | `TracingHookOptions::$includeValue` is `true`. Always serialized to a string; `bool` becomes `"true"`/`"false"`, `null` becomes `"null"`, and arrays/objects are JSON-encoded. |
| `feature_flag.result.variationIndex` | The evaluation produced a non-null variation index. Emitted as an integer, including the value `0`. |
| `feature_flag.result.reason.inExperiment` | The evaluation reason has `inExperiment=true`. When `false`, the attribute is omitted entirely rather than emitted as `false`. |
| `feature_flag.set.id` | `TracingHookOptions::$environmentId` is configured with a non-empty string, or the LaunchDarkly SDK supplies an environment ID on `EvaluationSeriesContext`. The configured value takes precedence. |

When `addSpans=true`, the wrapper `LDClient.<method>` span carries only `feature_flag.key` and `feature_flag.context.id`; all other attributes remain on the `feature_flag` event on the caller's surrounding span.

When no span is active on the current OpenTelemetry context, or the active span has an invalid context, the hook emits nothing.

## PHP-FPM and short-lived request lifecycles

Under PHP-FPM and similar request-response SAPIs, the PHP process hands control back to the web server as soon as a request completes, which can cut off span exports that a background processor has not yet flushed.

If you use `BatchSpanProcessor`, its in-memory queue is not guaranteed to flush before the process returns to the pool, and spans emitted late in the request may be lost. You have two straightforward options:

1. Use `SimpleSpanProcessor`, which exports each span synchronously on end. This is the simplest correct default for FPM deployments.
2. Continue using `BatchSpanProcessor`, but register a shutdown hook that flushes the tracer provider before the process exits:

```php
register_shutdown_function(static function () use ($tracerProvider): void {
    $tracerProvider->shutdown();
});
```

Worker pools (RoadRunner, Swoole, and similar) keep the PHP process alive across requests, so `BatchSpanProcessor` works as intended there. Follow your pool's shutdown hooks to call `$tracerProvider->shutdown()` at process exit.

## Known limitations

When `addSpans=true`, the wrapper span name is built from the PHP SDK method string (`variation`, `variationDetail`, `migrationVariation`), producing `LDClient.variation`, `LDClient.variationDetail`, and `LDClient.migrationVariation`. Aligning the casing with other LaunchDarkly SDKs is a concern for the core PHP Server-Side SDK and is tracked separately.

[spec]: https://github.com/launchdarkly/sdk-meta/blob/main/api/otel-integration.md

## Contributing

We encourage pull requests and other contributions from the community. Check out our [contributing guidelines](CONTRIBUTING.md) for instructions on how to contribute to this SDK.

## License

Apache 2.0. See [LICENSE.txt](LICENSE.txt) for the full text.

## About LaunchDarkly

* LaunchDarkly is a continuous delivery platform that provides feature flags as a service and allows developers to iterate quickly and safely. We allow you to easily flag your features and manage them from the LaunchDarkly dashboard.  With LaunchDarkly, you can:
    * Roll out a new feature to a subset of your users (like a group of users who opt-in to a beta tester group), gathering feedback and bug reports from real-world use cases.
    * Gradually roll out a feature to an increasing percentage of users, and track the effect that the feature has on key metrics (for instance, how likely is a user to complete a purchase if they have feature A versus feature B?).
    * Turn off a feature that you realize is causing performance problems in production, without needing to re-deploy, or even restart the application with a changed configuration file.
    * Grant access to certain features based on user attributes, like payment plan (eg: users on the 'gold' plan get access to more features than users in the 'silver' plan). Disable parts of your application to facilitate maintenance, without taking everything offline.
* LaunchDarkly provides feature flag SDKs for a wide variety of languages and technologies. Read [our documentation](https://docs.launchdarkly.com/sdk) for a complete list.
* Explore LaunchDarkly
    * [launchdarkly.com](https://www.launchdarkly.com/ "LaunchDarkly Main Website") for more information
    * [docs.launchdarkly.com](https://docs.launchdarkly.com/  "LaunchDarkly Documentation") for our documentation and SDK reference guides
    * [apidocs.launchdarkly.com](https://apidocs.launchdarkly.com/  "LaunchDarkly API Documentation") for our API documentation
    * [blog.launchdarkly.com](https://blog.launchdarkly.com/  "LaunchDarkly Blog Documentation") for the latest product updates
