<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry;

/**
 * String constants for every OpenTelemetry attribute key and fixed
 * attribute value emitted by the LaunchDarkly tracing hook.
 *
 * These names align with the OpenTelemetry semantic conventions for
 * feature flags and the LaunchDarkly OTEL integration specification. They
 * are kept in one place so that the hook implementation, tests, and
 * consumers reference identical strings.
 *
 * @see TracingHook
 * @see https://github.com/launchdarkly/sdk-meta/blob/main/api/otel-integration.md
 *
 * @psalm-api
 */
final class Attributes
{
    /**
     * Key of the flag being evaluated (spec §1.2.2.3).
     */
    public const FEATURE_FLAG_KEY = 'feature_flag.key';

    /**
     * Name of the feature flag provider; always {@see self::PROVIDER_NAME}
     * (spec §1.2.2.4).
     */
    public const FEATURE_FLAG_PROVIDER_NAME = 'feature_flag.provider.name';

    /**
     * Canonical key of the `LDContext` the flag is being evaluated for
     * (spec §1.2.2.5).
     */
    public const FEATURE_FLAG_CONTEXT_ID = 'feature_flag.context.id';

    /**
     * Serialized evaluated flag value (spec §1.2.2.6–§1.2.2.8). Optional;
     * emitted only when {@see TracingHookOptions::$includeValue} is
     * `true`.
     */
    public const FEATURE_FLAG_RESULT_VALUE = 'feature_flag.result.value';

    /**
     * Variation index of the evaluated flag as an integer (spec
     * §1.2.2.10–§1.2.2.10.1). Emitted whenever the evaluation produced a
     * non-null variation index, including the value `0`.
     */
    public const FEATURE_FLAG_RESULT_VARIATION_INDEX = 'feature_flag.result.variationIndex';

    /**
     * `true` when the evaluation was part of an experiment (spec
     * §1.2.2.11). Omitted entirely when the evaluation reason is not an
     * experiment — never emitted as `false` (spec §1.2.2.11.1).
     */
    public const FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT = 'feature_flag.result.reason.inExperiment';

    /**
     * Environment identifier for the LaunchDarkly project/environment
     * (spec §1.2.2.9). Emitted only when
     * {@see TracingHookOptions::$environmentId} is configured with a
     * non-empty string (spec §1.2.2.9.1).
     */
    public const FEATURE_FLAG_SET_ID = 'feature_flag.set.id';

    /**
     * Fixed value emitted for the `feature_flag.provider.name` attribute
     * (spec §1.2.2.4).
     */
    public const PROVIDER_NAME = 'LaunchDarkly';

    /**
     * This class is not instantiable; it exists only as a namespace for
     * string constants. The private constructor exists solely to prevent
     * instantiation — the `@psalm-api` tag silences the `UnusedConstructor`
     * check at psalm errorLevel 1, which would otherwise flag it.
     *
     * @psalm-api
     */
    private function __construct()
    {
    }
}
