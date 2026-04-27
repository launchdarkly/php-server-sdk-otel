<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry;

/**
 * String constants for every OpenTelemetry attribute key and fixed attribute
 * value emitted by the LaunchDarkly tracing hook.
 *
 * These names align with the OpenTelemetry semantic conventions for feature
 * flags and the LaunchDarkly OTEL integration spec. They are kept in one
 * place so that the hook implementation, tests, and consumers reference
 * identical strings.
 *
 * @psalm-api
 */
final class Attributes
{
    /**
     * Key of the flag being evaluated.
     */
    public const FEATURE_FLAG_KEY = 'feature_flag.key';

    /**
     * Name of the feature flag provider; always {@see self::PROVIDER_NAME}.
     */
    public const FEATURE_FLAG_PROVIDER_NAME = 'feature_flag.provider.name';

    /**
     * Canonical key of the context the flag is being evaluated for.
     */
    public const FEATURE_FLAG_CONTEXT_ID = 'feature_flag.context.id';

    /**
     * Serialized evaluated flag value (optional; opt-in via
     * {@see TracingHookOptions::$includeValue}).
     */
    public const FEATURE_FLAG_RESULT_VALUE = 'feature_flag.result.value';

    /**
     * Variation index of the evaluated flag, when available.
     */
    public const FEATURE_FLAG_RESULT_VARIATION_INDEX = 'feature_flag.result.variationIndex';

    /**
     * True when the evaluation was part of an experiment; omitted otherwise.
     */
    public const FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT = 'feature_flag.result.reason.inExperiment';

    /**
     * Environment identifier for the LaunchDarkly project/environment.
     */
    public const FEATURE_FLAG_SET_ID = 'feature_flag.set.id';

    /**
     * Fixed value used for the `feature_flag.provider.name` attribute.
     */
    public const PROVIDER_NAME = 'LaunchDarkly';

    /**
     * This class is not instantiable; it exists only as a namespace for
     * string constants.
     *
     * @psalm-api
     */
    private function __construct()
    {
    }
}
