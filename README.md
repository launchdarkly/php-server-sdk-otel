# LaunchDarkly Server-Side SDK for PHP — OpenTelemetry integration

This package provides an [OpenTelemetry](https://opentelemetry.io/) integration for the [LaunchDarkly PHP Server-Side SDK](https://github.com/launchdarkly/php-server-sdk). It exposes a `TracingHook` that adds `feature_flag` span events during flag evaluation, matching the LaunchDarkly OpenTelemetry integration spec and the sibling Ruby, Node, Python, Java, .NET, and Go packages. Full usage documentation will land with the first release.
