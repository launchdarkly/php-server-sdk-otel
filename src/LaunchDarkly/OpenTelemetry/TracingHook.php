<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry;

use LaunchDarkly\EvaluationDetail;
use LaunchDarkly\Hooks\EvaluationSeriesContext;
use LaunchDarkly\Hooks\Hook;
use LaunchDarkly\Hooks\Metadata;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;

/**
 * LaunchDarkly feature flag evaluation hook that enriches OpenTelemetry spans
 * with `feature_flag` events whenever a flag is evaluated.
 *
 * When a LaunchDarkly SDK variation call runs inside an active OpenTelemetry
 * span, this hook attaches a `feature_flag` span event carrying the required
 * semantic-convention attributes (`feature_flag.key`,
 * `feature_flag.provider.name`, `feature_flag.context.id`) as well as any
 * enabled optional attributes:
 *
 *   - `feature_flag.result.value` when
 *     {@see TracingHookOptions::$includeValue} is true. The value is
 *     serialized per {@see self::serializeValue()}.
 *   - `feature_flag.result.variationIndex` when the evaluation produced a
 *     variation index (emitted as an integer primitive).
 *   - `feature_flag.result.reason.inExperiment` when the evaluation reason
 *     is part of an experiment. Omitted when false, per spec §1.2.2.10.1.
 *   - `feature_flag.set.id` when {@see TracingHookOptions::$environmentId}
 *     is configured. Only the options-sourced path (spec §1.2.2.9.1) is
 *     supported; the per-evaluation path (§1.2.2.9.2) is not, because the
 *     PHP Server-Side SDK does not currently expose an environment ID on
 *     `EvaluationSeriesContext`.
 *
 * When no span is active, the hook is a no-op.
 *
 * Experimental: when {@see TracingHookOptions::$addSpans} is enabled,
 * `beforeEvaluation` also creates a dedicated OpenTelemetry span named
 * `LDClient.<method>` (where `<method>` is the raw variation method name,
 * e.g. `variation`, `variationDetail`, `migrationVariation`) that wraps the
 * variation call. The wrapper span carries only the `feature_flag.key` and
 * `feature_flag.context.id` attributes; all other attributes remain on the
 * `feature_flag` span event. `afterEvaluation` detaches the wrapper span's
 * context and ends it BEFORE attaching the `feature_flag` event, so the
 * event lands on the caller's surrounding span rather than on the wrapper.
 *
 * The wrapper span and the scope used to restore the caller's context are
 * threaded between `beforeEvaluation` and `afterEvaluation` through the
 * hook `$data` array under the reserved keys identified by the
 * {@see self::DATA_KEY_SPAN} and {@see self::DATA_KEY_SCOPE} class
 * constants. Other hooks must not write to those keys. On exit from
 * `afterEvaluation` the keys are removed from `$data` so downstream stages
 * are not surprised by OpenTelemetry objects in their inputs.
 *
 * The hook is registered on an `LDClient` via the SDK's hooks configuration
 * and is safe to share across threads/requests.
 *
 * @psalm-api
 */
final class TracingHook extends Hook
{
    private const HOOK_NAME   = 'LaunchDarkly Tracing Hook';
    private const TRACER_NAME = 'launchdarkly';
    private const EVENT_NAME  = 'feature_flag';

    /**
     * Reserved `$data` key under which the experimental `addSpans` path
     * stashes the wrapper `SpanInterface` for later teardown. Exposed as a
     * class constant rather than a literal string so that collisions with
     * other hooks are easy to audit.
     */
    private const DATA_KEY_SPAN  = '__ld_otel_span';

    /**
     * Reserved `$data` key under which the experimental `addSpans` path
     * stashes the `ScopeInterface` returned by
     * `Context::storage()->attach(...)`. Detaching this scope restores the
     * caller's context so the `feature_flag` event can attach to the
     * caller's span instead of the wrapper span.
     */
    private const DATA_KEY_SCOPE = '__ld_otel_scope';

    /**
     * Tracer used by the experimental `addSpans` path to create wrapper
     * spans around each variation call. Unused when `addSpans` is disabled.
     */
    private readonly TracerInterface $tracer;

    /**
     * Configuration object controlling the optional attributes emitted on the
     * `feature_flag` span event. Consulted on every call to
     * {@see self::afterEvaluation()}.
     */
    private readonly TracingHookOptions $options;

    /**
     * @param TracingHookOptions   $options Configuration object controlling optional attributes and features.
     * @param TracerInterface|null $tracer  Tracer used by the experimental `addSpans` path to create wrapper
     *                                      spans for every variation call. When `null`, a tracer named
     *                                      `launchdarkly` is acquired from the global OpenTelemetry tracer
     *                                      provider. Primarily an injection point for tests.
     */
    public function __construct(
        TracingHookOptions $options = new TracingHookOptions(),
        ?TracerInterface $tracer = null,
    ) {
        $this->options = $options;
        $this->tracer  = $tracer ?? Globals::tracerProvider()->getTracer(self::TRACER_NAME);
    }

    #[\Override]
    public function getMetadata(): Metadata
    {
        return new Metadata(self::HOOK_NAME);
    }

    /**
     * Experimental. When {@see TracingHookOptions::$addSpans} is enabled,
     * start a wrapper span named `LDClient.<method>` around the upcoming
     * variation call and attach its context so subsequent work is nested
     * beneath it. The wrapper span carries only the two required attributes
     * from spec §1.2.3.4–5 (`feature_flag.key` and `feature_flag.context.id`).
     *
     * The span and the scope used to detach its context are stashed under
     * {@see self::DATA_KEY_SPAN} and {@see self::DATA_KEY_SCOPE} in the
     * returned `$data` so `afterEvaluation` can tear them down in the
     * correct order.
     *
     * When `addSpans` is disabled, `$data` is returned unchanged.
     *
     * The span name is the raw PHP SDK method string (`variation`,
     * `variationDetail`, `migrationVariation`), which yields
     * `LDClient.variation` and friends. This is a PHP-specific divergence
     * from spec §1.2.3.6's PascalCase examples; aligning the method-name
     * casing is a concern for the core PHP Server-Side SDK and is tracked
     * separately.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    #[\Override]
    public function beforeEvaluation(
        EvaluationSeriesContext $seriesContext,
        array $data,
    ): array {
        if (!$this->options->addSpans) {
            return $data;
        }

        $span = $this->tracer
            ->spanBuilder('LDClient.' . $seriesContext->method)
            ->setAttributes([
                Attributes::FEATURE_FLAG_KEY        => $seriesContext->flagKey,
                Attributes::FEATURE_FLAG_CONTEXT_ID => $seriesContext->context->getFullyQualifiedKey(),
            ])
            ->startSpan();

        $scope = Context::storage()->attach($span->storeInContext(Context::getCurrent()));

        $data[self::DATA_KEY_SPAN]  = $span;
        $data[self::DATA_KEY_SCOPE] = $scope;
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    #[\Override]
    public function afterEvaluation(
        EvaluationSeriesContext $seriesContext,
        array $data,
        EvaluationDetail $detail,
    ): array {
        // Spec §1.2.3.2: if the experimental `addSpans` path started a
        // wrapper span in `beforeEvaluation`, tear it down BEFORE looking up
        // the current span. Detaching the scope pops the wrapper off the
        // active context so the `feature_flag` event below attaches to the
        // caller's surrounding span instead of the wrapper. The order
        // (detach → end → read current span) matters — ending before
        // detaching would leave a closed span on top of the active context,
        // and reading the current span before detaching would return the
        // wrapper itself.
        if (isset($data[self::DATA_KEY_SPAN], $data[self::DATA_KEY_SCOPE])
            && $data[self::DATA_KEY_SPAN] instanceof SpanInterface
            && $data[self::DATA_KEY_SCOPE] instanceof ScopeInterface
        ) {
            $data[self::DATA_KEY_SCOPE]->detach();
            $data[self::DATA_KEY_SPAN]->end();
            unset($data[self::DATA_KEY_SPAN], $data[self::DATA_KEY_SCOPE]);
        }

        $span = Span::fromContext(Context::getCurrent());

        // Spec §1.2.2.1.1: if the surrounding span context is not valid (no
        // active span, or the active span has an invalid context), the hook
        // must not emit an event.
        if (!$span->getContext()->isValid()) {
            return $data;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = [
            Attributes::FEATURE_FLAG_KEY           => $seriesContext->flagKey,
            Attributes::FEATURE_FLAG_PROVIDER_NAME => Attributes::PROVIDER_NAME,
            Attributes::FEATURE_FLAG_CONTEXT_ID    => $seriesContext->context->getFullyQualifiedKey(),
        ];

        // Spec §1.2.2.6 / §1.2.2.7 / §1.2.2.8: optional serialized flag value,
        // gated on the `includeValue` option.
        if ($this->options->includeValue) {
            $attributes[Attributes::FEATURE_FLAG_RESULT_VALUE] = self::serializeValue($detail->getValue());
        }

        // Spec §1.2.2.10 / §1.2.2.10.1: emit the variation index as an integer
        // whenever present (including 0). Null means "default value was
        // returned" and the attribute must be omitted.
        $variationIndex = $detail->getVariationIndex();
        if ($variationIndex !== null) {
            $attributes[Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX] = $variationIndex;
        }

        // Spec §1.2.2.11 / §1.2.2.11.1: emit only when `inExperiment` is
        // true. When false, the attribute must be omitted entirely (do not
        // emit `false`).
        if ($detail->getReason()->isInExperiment()) {
            $attributes[Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT] = true;
        }

        // Spec §1.2.2.9 / §1.2.2.9.1: emit the configured environment ID as
        // `feature_flag.set.id`. The options constructor has already trimmed
        // and rejected empty or whitespace-only inputs, so a non-null value
        // here is guaranteed to be a usable non-empty string.
        if ($this->options->environmentId !== null) {
            $attributes[Attributes::FEATURE_FLAG_SET_ID] = $this->options->environmentId;
        }

        $span->addEvent(self::EVENT_NAME, $attributes);

        return $data;
    }

    /**
     * Serialize an evaluated flag value into a string suitable for the
     * `feature_flag.result.value` span-event attribute.
     *
     * The rules are (item B of the epic plan):
     *
     *   - `bool` → the literal strings `"true"` / `"false"`
     *   - `int` or `float` → `(string) $value`
     *   - `string` → returned as-is (including the empty string)
     *   - `null` → the literal 4-character string `"null"`
     *   - `array` or `object` → `json_encode($value, JSON_THROW_ON_ERROR)`,
     *     falling back to `"null"` on encode failure
     *   - anything else (resource, closure, etc.) → `"null"`
     */
    private static function serializeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value) || is_object($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return 'null';
            }
        }

        return 'null';
    }
}
