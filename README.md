# LaunchDarkly Server-Side SDK for PHP — OpenTelemetry integration

This package provides an [OpenTelemetry](https://opentelemetry.io/) integration for the [LaunchDarkly PHP Server-Side SDK](https://github.com/launchdarkly/php-server-sdk). It exposes a `TracingHook` that adds `feature_flag` span events during flag evaluation, matching the LaunchDarkly OpenTelemetry integration spec and the sibling Ruby, Node, Python, Java, .NET, and Go packages. Full usage documentation will land with the first release.

## Known limitations

`feature_flag.set.id` is emitted only when the environment ID is supplied via `TracingHookOptions` (spec [§1.2.2.9.1][spec]). The per-evaluation path described in [§1.2.2.9.2][spec] — where the SDK would pass the environment ID through `EvaluationSeriesContext` — is not yet supported, because the LaunchDarkly PHP Server-Side SDK does not currently expose an environment ID on `EvaluationSeriesContext`. Configuring `environmentId` on the options is the supported way to emit `feature_flag.set.id` today.

[spec]: https://github.com/launchdarkly/sdk-meta/blob/main/api/otel-integration.md
