<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry\Tests;

use ArrayObject;
use LaunchDarkly\EvaluationDetail;
use LaunchDarkly\EvaluationReason;
use LaunchDarkly\Hooks\EvaluationSeriesContext;
use LaunchDarkly\Hooks\Metadata;
use LaunchDarkly\LDContext;
use LaunchDarkly\OpenTelemetry\Attributes;
use LaunchDarkly\OpenTelemetry\TracingHook;
use LaunchDarkly\OpenTelemetry\TracingHookOptions;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see \LaunchDarkly\OpenTelemetry\TracingHook}.
 *
 * Each test brings up a fresh OpenTelemetry `TracerProvider` wired to an
 * `InMemoryExporter` via `SimpleSpanProcessor`, runs the hook, then asserts
 * against the captured spans/events.
 *
 * Spec coverage map (LaunchDarkly OTEL integration spec):
 *   §1.2.1     -- Hook class is exported.                         (file existence / class usage)
 *   §1.2.2.1   -- `afterEvaluation` attaches an event to span.    (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.1.1 -- no active / invalid span → no-op.               (testNoActiveSpan..., testInvalidSpanContext...)
 *   §1.2.2.2   -- event name is `feature_flag`.                   (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.3   -- `feature_flag.key` present.                     (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.4   -- `feature_flag.provider.name` = `LaunchDarkly`.  (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.5   -- `feature_flag.context.id` = canonical key.      (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.6   -- `feature_flag.result.value` gated by option.    (testValueAttribute..., testSerialize*)
 *   §1.2.2.7   -- `value` attribute is a string.                  (testSerialize* cases)
 *   §1.2.2.8   -- value encodes the evaluated flag value.         (testSerialize* cases)
 *   §1.2.2.9   -- `feature_flag.set.id` optional attribute.       (testEnvironmentId*)
 *   §1.2.2.9.1 -- emitted from TracingHookOptions when set.       (testEnvironmentIdEmittedWhenConfigured)
 *   §1.2.2.9.1.1 -- attribute value matches configured input.     (testEnvironmentIdEmittedWhenConfigured)
 *   §1.2.2.10  -- `variationIndex` present when non-null.         (testVariationIndex*)
 *   §1.2.2.10.1 -- `variationIndex` type is int.                  (testVariationIndexIsInteger)
 *   §1.2.2.11  -- `reason.inExperiment` present when true.        (testInExperimentEmittedWhenTrue)
 *   §1.2.2.11.1 -- omitted when false (not emitted as `false`).   (testInExperimentOmittedWhenFalse)
 *   §1.2.3.1   -- `addSpans=false` is a no-op in beforeEvaluation.(testBeforeEvaluationIsNoOpWhenAddSpansDisabled)
 *   §1.2.3.2   -- feature_flag event attaches to caller's span.   (testFeatureFlagEventAttachesToParentSpanNotWrapper)
 *   §1.2.3.3   -- wrapper span parents correctly or roots alone.  (testAddSpansCreatesChildSpanParentedToActiveSpan,
 *                                                                  testAddSpansCreatesRootSpanWhenNoActiveSpan)
 *   §1.2.3.4   -- feature_flag.key on wrapper span.               (testAddSpansCreatesChildSpanParentedToActiveSpan)
 *   §1.2.3.5   -- feature_flag.context.id on wrapper span.        (testAddSpansCreatesChildSpanParentedToActiveSpan)
 *   §1.2.3.6   -- span name is `LDClient.<method>`.               (testAddSpansSpanNameMatchesMethod*)
 *   §1.2.3.7   -- wrapper span is ended in afterEvaluation.       (testWrapperSpanIsEndedBeforeEventWrite)
 *
 * Known gap: §1.2.2.9.2 (per-evaluation environment ID supplied via
 * `EvaluationSeriesContext`) is not implemented because the PHP SDK's
 * `EvaluationSeriesContext` does not yet carry an environment ID. The
 * absence of an emission on that path is covered indirectly by the
 * options-driven tests below, which are the only supported source today.
 */
class TracingHookTest extends TestCase
{
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;
    private TracerProvider $tracerProvider;
    private TracerInterface $tracer;

    protected function setUp(): void
    {
        /** @var ArrayObject<int, ImmutableSpan> $storage */
        $storage              = new ArrayObject();
        $this->storage        = $storage;
        $exporter             = new InMemoryExporter($this->storage);
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($exporter));
        $this->tracer         = $this->tracerProvider->getTracer('test');
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
    }

    private function seriesContext(string $flagKey = 'my-flag', ?LDContext $ctx = null): EvaluationSeriesContext
    {
        return new EvaluationSeriesContext(
            flagKey: $flagKey,
            context: $ctx ?? LDContext::create('user-abc'),
            defaultValue: false,
            method: 'variation',
        );
    }

    private function detail(mixed $value = true, ?int $idx = 0, ?EvaluationReason $reason = null): EvaluationDetail
    {
        return new EvaluationDetail($value, $idx, $reason ?? EvaluationReason::off());
    }

    /**
     * Runs `afterEvaluation` inside a parent span and returns the captured
     * event's attributes as an associative array.
     *
     * @return array<string, mixed>
     */
    private function captureEventAttributes(
        TracingHookOptions $options,
        EvaluationDetail $detail,
        ?LDContext $ctx = null,
        string $flagKey = 'my-flag',
    ): array {
        $hook  = new TracingHook($options, $this->tracer);
        $span  = $this->tracer->spanBuilder('parent')->startSpan();
        $scope = $span->activate();
        try {
            $hook->afterEvaluation($this->seriesContext($flagKey, $ctx), [], $detail);
        } finally {
            $scope->detach();
            $span->end();
        }

        $spans = $this->storage->getArrayCopy();
        $this->assertCount(1, $spans);
        $parent = $spans[0];
        $this->assertInstanceOf(ImmutableSpan::class, $parent);

        $events = $parent->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('feature_flag', $events[0]->getName());

        return $events[0]->getAttributes()->toArray();
    }

    // -----------------------------------------------------------------
    // Required attributes, no-op paths, metadata.
    // -----------------------------------------------------------------

    /**
     * Spec §1.2.1, §1.2.2.1, §1.2.2.2, §1.2.2.3, §1.2.2.4, §1.2.2.5.
     *
     * With a valid active span, `afterEvaluation` attaches a single
     * `feature_flag` event carrying exactly the three required attributes.
     */
    public function testActiveSpanReceivesFeatureFlagEventWithRequiredAttributes(): void
    {
        $ctx  = LDContext::create('user-abc');
        $hook = new TracingHook(new TracingHookOptions(), $this->tracer);

        $span  = $this->tracer->spanBuilder('parent')->startSpan();
        $scope = $span->activate();
        try {
            // Use null variationIndex so none of the optional attributes fire.
            $detail = $this->detail(true, null);
            $hook->afterEvaluation($this->seriesContext('my-flag', $ctx), [], $detail);
        } finally {
            $scope->detach();
            $span->end();
        }

        $spans = $this->storage->getArrayCopy();
        $this->assertCount(1, $spans);
        $parent = $spans[0];
        $this->assertInstanceOf(ImmutableSpan::class, $parent);

        $events = $parent->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('feature_flag', $events[0]->getName());

        $attrs = $events[0]->getAttributes()->toArray();
        $this->assertSame([
            Attributes::FEATURE_FLAG_KEY           => 'my-flag',
            Attributes::FEATURE_FLAG_PROVIDER_NAME => 'LaunchDarkly',
            Attributes::FEATURE_FLAG_CONTEXT_ID    => $ctx->getFullyQualifiedKey(),
        ], $attrs);
    }

    /**
     * Spec §1.2.2.1.1.
     *
     * With no active span, the hook is a no-op: no exception, no span or
     * event is ever exported.
     */
    public function testNoActiveSpanDoesNothing(): void
    {
        $hook = new TracingHook(new TracingHookOptions(), $this->tracer);

        $hook->afterEvaluation($this->seriesContext(), [], $this->detail());

        $this->assertSame([], $this->storage->getArrayCopy());
    }

    /**
     * Spec §1.2.2.1.1.
     *
     * When the "active" span is the OpenTelemetry invalid span (a
     * NonRecordingSpan with an invalid SpanContext), the hook must not emit
     * an event. This is the no-op contract end-to-end.
     */
    public function testInvalidSpanContextDoesNothing(): void
    {
        $hook = new TracingHook(new TracingHookOptions(), $this->tracer);

        $invalidSpan = Span::getInvalid();
        $scope       = $invalidSpan->activate();
        try {
            $hook->afterEvaluation($this->seriesContext(), [], $this->detail());
        } finally {
            $scope->detach();
        }

        $this->assertSame([], $this->storage->getArrayCopy());
    }

    /**
     * `getMetadata()` returns the documented hook name used by the SDK's
     * error-isolation logging.
     */
    public function testGetMetadataReturnsLaunchDarklyTracingHook(): void
    {
        $hook = new TracingHook(new TracingHookOptions(), $this->tracer);

        $meta = $hook->getMetadata();

        $this->assertInstanceOf(Metadata::class, $meta);
        $this->assertSame('LaunchDarkly Tracing Hook', $meta->name);
    }

    /**
     * The constructor is usable with zero arguments — default options and a
     * tracer acquired from the global OTEL provider. We assert only that this
     * does not throw; behaviour under the global no-op tracer is not our
     * contract to test.
     */
    public function testConstructorDefaultsAreUsableWithoutArguments(): void
    {
        $hook = new TracingHook();

        $this->assertInstanceOf(TracingHook::class, $hook);
    }

    // -----------------------------------------------------------------
    // Optional-attribute on/off matrix.
    // -----------------------------------------------------------------

    /**
     * Spec §1.2.2.6, §1.2.2.10, §1.2.2.11.
     *
     * With the default options, no variation index, and an `off` reason
     * (inExperiment=false), none of the optional attributes are emitted.
     */
    public function testNoOptionalAttributesEmittedByDefault(): void
    {
        $ctx    = LDContext::create('user-abc');
        $attrs  = $this->captureEventAttributes(
            new TracingHookOptions(),
            $this->detail(value: true, idx: null, reason: EvaluationReason::off()),
            $ctx,
        );

        $this->assertSame([
            Attributes::FEATURE_FLAG_KEY           => 'my-flag',
            Attributes::FEATURE_FLAG_PROVIDER_NAME => 'LaunchDarkly',
            Attributes::FEATURE_FLAG_CONTEXT_ID    => $ctx->getFullyQualifiedKey(),
        ], $attrs);
    }

    /**
     * Spec §1.2.2.6.
     *
     * When `includeValue=true` and all other optional attributes are absent,
     * the event carries the three required attributes plus `result.value`.
     */
    public function testValueAttributeEmittedWhenIncludeValueTrue(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(includeValue: true),
            $this->detail(value: true, idx: null, reason: EvaluationReason::off()),
        );

        $this->assertArrayHasKey(Attributes::FEATURE_FLAG_RESULT_VALUE, $attrs);
        $this->assertSame('true', $attrs[Attributes::FEATURE_FLAG_RESULT_VALUE]);
        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX, $attrs);
        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT, $attrs);
    }

    /**
     * Spec §1.2.2.10.
     *
     * Non-null `variationIndex` is emitted even when `includeValue=false` and
     * the reason is not an experiment. No value or inExperiment attributes.
     */
    public function testVariationIndexEmittedWhenNotNull(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(),
            $this->detail(value: true, idx: 5, reason: EvaluationReason::off()),
        );

        $this->assertArrayHasKey(Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX, $attrs);
        $this->assertSame(5, $attrs[Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX]);
        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_RESULT_VALUE, $attrs);
        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT, $attrs);
    }

    /**
     * Spec §1.2.2.10.
     *
     * Regression guard: `variationIndex=0` is the first variation, not the
     * default. It MUST be emitted. (Ruby has a truthy-check bug here; we
     * don't.)
     */
    public function testVariationIndexZeroIsEmitted(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(),
            $this->detail(value: true, idx: 0, reason: EvaluationReason::off()),
        );

        $this->assertArrayHasKey(Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX, $attrs);
        $this->assertSame(0, $attrs[Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX]);
    }

    /**
     * Spec §1.2.2.10.1.
     *
     * `variationIndex` is emitted as an int primitive, not a stringified form.
     */
    public function testVariationIndexIsInteger(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(),
            $this->detail(value: true, idx: 42, reason: EvaluationReason::off()),
        );

        $this->assertSame(42, $attrs[Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX]);
        // Deliberately not `assertIsInt` so the fail message shows the actual
        // value if a future cast is introduced.
        $this->assertNotSame('42', $attrs[Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX]);
    }

    /**
     * Spec §1.2.2.11.
     *
     * A fallthrough reason with `inExperiment=true` causes the optional
     * attribute to be emitted with the literal boolean `true`.
     */
    public function testInExperimentEmittedWhenTrue(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(),
            $this->detail(value: true, idx: null, reason: EvaluationReason::fallthrough(true)),
        );

        $this->assertArrayHasKey(Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT, $attrs);
        $this->assertSame(true, $attrs[Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT]);
        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_RESULT_VALUE, $attrs);
        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX, $attrs);
    }

    /**
     * Spec §1.2.2.11.1.
     *
     * When `inExperiment=false`, the attribute is omitted entirely — the hook
     * MUST NOT emit `inExperiment=false`. Asserted via `assertArrayNotHasKey`.
     */
    public function testInExperimentOmittedWhenFalse(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(),
            $this->detail(value: true, idx: null, reason: EvaluationReason::fallthrough(false)),
        );

        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT, $attrs);
    }

    /**
     * Spec §1.2.2.6, §1.2.2.10, §1.2.2.11.
     *
     * All three optional attributes fire simultaneously when their respective
     * gates are true. Event should have all six attributes.
     */
    public function testAllOptionalAttributesEmittedTogether(): void
    {
        $ctx   = LDContext::create('user-abc');
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(includeValue: true),
            $this->detail(
                value: 'blue',
                idx: 2,
                reason: EvaluationReason::ruleMatch(1, 'rule-id', true),
            ),
            $ctx,
        );

        $this->assertSame([
            Attributes::FEATURE_FLAG_KEY                     => 'my-flag',
            Attributes::FEATURE_FLAG_PROVIDER_NAME           => 'LaunchDarkly',
            Attributes::FEATURE_FLAG_CONTEXT_ID              => $ctx->getFullyQualifiedKey(),
            Attributes::FEATURE_FLAG_RESULT_VALUE            => 'blue',
            Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX  => 2,
            Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT => true,
        ], $attrs);
    }

    // -----------------------------------------------------------------
    // feature_flag.set.id (spec §1.2.2.9 / §1.2.2.9.1 / §1.2.2.9.1.1).
    //
    // Only the options-sourced path is supported. §1.2.2.9.2 (per-evaluation
    // environment ID via EvaluationSeriesContext) is a known gap documented
    // at the top of this file; we verify it by exercising the supported
    // path and asserting the absence of the attribute when no environmentId
    // is configured.
    // -----------------------------------------------------------------

    /**
     * Spec §1.2.2.9, §1.2.2.9.1, §1.2.2.9.1.1.
     *
     * With `environmentId` configured and all other optional attributes
     * disabled, the event carries exactly the three required attributes plus
     * `feature_flag.set.id` with the configured value.
     */
    public function testEnvironmentIdEmittedWhenConfigured(): void
    {
        $ctx   = LDContext::create('user-abc');
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(environmentId: 'env-abc123'),
            $this->detail(value: true, idx: null, reason: EvaluationReason::off()),
            $ctx,
        );

        $this->assertSame([
            Attributes::FEATURE_FLAG_KEY           => 'my-flag',
            Attributes::FEATURE_FLAG_PROVIDER_NAME => 'LaunchDarkly',
            Attributes::FEATURE_FLAG_CONTEXT_ID    => $ctx->getFullyQualifiedKey(),
            Attributes::FEATURE_FLAG_SET_ID        => 'env-abc123',
        ], $attrs);
    }

    /**
     * Spec §1.2.2.9, §1.2.2.9.1.
     *
     * Default options do not configure an environment ID, so the
     * `feature_flag.set.id` attribute must be omitted entirely.
     */
    public function testEnvironmentIdOmittedByDefault(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(),
            $this->detail(value: true, idx: null, reason: EvaluationReason::off()),
        );

        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_SET_ID, $attrs);
    }

    /**
     * Spec §1.2.2.9.1 (cross-module contract with options validation).
     *
     * An empty-string environmentId is discarded by the TracingHookOptions
     * constructor and stored as null. The hook must therefore emit no
     * `feature_flag.set.id` attribute — anchoring the integration between
     * options-validation and hook-emission.
     */
    public function testEnvironmentIdOmittedWhenEmptyStringDiscardedByOptions(): void
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(environmentId: ''),
            $this->detail(value: true, idx: null, reason: EvaluationReason::off()),
        );

        $this->assertArrayNotHasKey(Attributes::FEATURE_FLAG_SET_ID, $attrs);
    }

    /**
     * Spec §1.2.2.9.1, §1.2.2.6, §1.2.2.10, §1.2.2.11.
     *
     * Full combo: environmentId configured, includeValue on, a non-null
     * variation index, and an experiment-reason. The event carries all seven
     * attributes simultaneously.
     */
    public function testAllAttributesIncludingSetIdEmittedTogether(): void
    {
        $ctx   = LDContext::create('user-abc');
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(includeValue: true, environmentId: 'env-xyz'),
            $this->detail(
                value: 'blue',
                idx: 2,
                reason: EvaluationReason::ruleMatch(1, 'rule-id', true),
            ),
            $ctx,
        );

        $this->assertSame([
            Attributes::FEATURE_FLAG_KEY                         => 'my-flag',
            Attributes::FEATURE_FLAG_PROVIDER_NAME               => 'LaunchDarkly',
            Attributes::FEATURE_FLAG_CONTEXT_ID                  => $ctx->getFullyQualifiedKey(),
            Attributes::FEATURE_FLAG_RESULT_VALUE                => 'blue',
            Attributes::FEATURE_FLAG_RESULT_VARIATION_INDEX      => 2,
            Attributes::FEATURE_FLAG_RESULT_REASON_IN_EXPERIMENT => true,
            Attributes::FEATURE_FLAG_SET_ID                      => 'env-xyz',
        ], $attrs);
    }

    /**
     * Spec §1.2.2.1.1 precedence over §1.2.2.9.1.
     *
     * The no-active-span rule wins over every attribute-emission gate: even
     * with `environmentId` configured, a call without an active span must
     * produce no span and no event at all.
     */
    public function testNoActiveSpanWithEnvironmentIdStillDoesNothing(): void
    {
        $hook = new TracingHook(
            new TracingHookOptions(environmentId: 'env-abc'),
            $this->tracer,
        );

        $hook->afterEvaluation($this->seriesContext(), [], $this->detail());

        $this->assertSame([], $this->storage->getArrayCopy());
    }

    // -----------------------------------------------------------------
    // Per-PHP-type serialization of feature_flag.result.value, end-to-end
    // via afterEvaluation.
    //
    // Spec §1.2.2.6, §1.2.2.7, §1.2.2.8. Each test goes through the live
    // hook so the assertion is against the captured span event, not a
    // direct helper invocation.
    // -----------------------------------------------------------------

    /**
     * @return mixed
     */
    private function valueAttrFor(mixed $value): mixed
    {
        $attrs = $this->captureEventAttributes(
            new TracingHookOptions(includeValue: true),
            $this->detail(value: $value, idx: null, reason: EvaluationReason::off()),
        );
        $this->assertArrayHasKey(Attributes::FEATURE_FLAG_RESULT_VALUE, $attrs);
        return $attrs[Attributes::FEATURE_FLAG_RESULT_VALUE];
    }

    public function testSerializeBoolTrue(): void
    {
        $this->assertSame('true', $this->valueAttrFor(true));
    }

    public function testSerializeBoolFalse(): void
    {
        $this->assertSame('false', $this->valueAttrFor(false));
    }

    public function testSerializeIntPositive(): void
    {
        $this->assertSame('42', $this->valueAttrFor(42));
    }

    public function testSerializeIntZero(): void
    {
        $this->assertSame('0', $this->valueAttrFor(0));
    }

    public function testSerializeIntNegative(): void
    {
        $this->assertSame('-5', $this->valueAttrFor(-5));
    }

    public function testSerializeFloat(): void
    {
        $this->assertSame('3.14', $this->valueAttrFor(3.14));
    }

    public function testSerializeStringNonEmpty(): void
    {
        $this->assertSame('hello', $this->valueAttrFor('hello'));
    }

    public function testSerializeStringEmpty(): void
    {
        // Empty string is still a string — passes through as-is.
        $this->assertSame('', $this->valueAttrFor(''));
    }

    public function testSerializeNull(): void
    {
        // Literal 4-character string "null".
        $this->assertSame('null', $this->valueAttrFor(null));
    }

    public function testSerializeIndexedArray(): void
    {
        $this->assertSame('[1,2,3]', $this->valueAttrFor([1, 2, 3]));
    }

    public function testSerializeAssocArray(): void
    {
        $this->assertSame('{"a":1}', $this->valueAttrFor(['a' => 1]));
    }

    public function testSerializeObject(): void
    {
        $obj    = new \stdClass();
        $obj->a = 1;
        $this->assertSame('{"a":1}', $this->valueAttrFor($obj));
    }

    // -----------------------------------------------------------------
    // Encode-failure fallbacks for feature_flag.result.value.
    //
    // These exercise legs of `serializeValue` that can't be reached with a
    // `EvaluationDetail::getValue()` returning a "normal" PHP value, so
    // the helper is invoked directly via reflection.
    // -----------------------------------------------------------------

    /**
     * Spec §1.2.2.6 (robustness). A resource inside an array trips
     * `json_encode`; the serializer must swallow the exception and fall back
     * to the literal string `"null"`.
     */
    public function testSerializeEncodeFailureReturnsNull(): void
    {
        $handle = fopen('php://memory', 'r');
        $this->assertNotFalse($handle);
        try {
            $result = $this->invokeSerializeValue([$handle]);
            $this->assertSame('null', $result);
        } finally {
            fclose($handle);
        }
    }

    /**
     * A raw resource (not wrapped in an array) falls through every typed
     * branch and lands on the terminal `"null"` fallback.
     */
    public function testSerializeResourceScalarReturnsNull(): void
    {
        $handle = fopen('php://memory', 'r');
        $this->assertNotFalse($handle);
        try {
            $result = $this->invokeSerializeValue($handle);
            $this->assertSame('null', $result);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Invoke the private static `TracingHook::serializeValue` via reflection.
     * Used for the encode-failure paths, which can't cleanly be set up via a
     * live span because `EvaluationDetail` doesn't accept resources in a way
     * that survives the serializer without reflection anyway.
     */
    private function invokeSerializeValue(mixed $value): string
    {
        $method = new \ReflectionMethod(TracingHook::class, 'serializeValue');
        /** @var string $result */
        $result = $method->invoke(null, $value);
        return $result;
    }

    // -----------------------------------------------------------------
    // Experimental `addSpans` feature (spec §1.2.3.*).
    //
    // When `addSpans=true`, `beforeEvaluation` wraps each variation call in
    // its own `LDClient.<method>` span. `afterEvaluation` must detach that
    // span's context BEFORE attaching the `feature_flag` event so the event
    // lands on the caller's surrounding span, not on the wrapper.
    // -----------------------------------------------------------------

    /**
     * Runs a full before→after lifecycle inside a caller-supplied parent
     * span so callers can verify the wrapper span's parenting and the
     * `feature_flag` event's placement.
     *
     * @param  string $method Method name written into the EvaluationSeriesContext.
     * @return array{parent: ImmutableSpan, wrapper: ImmutableSpan, afterData: array<string, mixed>}
     */
    private function runLifecycleWithParent(
        TracingHookOptions $options,
        string $method = 'variation',
    ): array {
        $hook = new TracingHook($options, $this->tracer);
        $ctx  = LDContext::create('user-abc');
        $sc   = new EvaluationSeriesContext(
            flagKey: 'my-flag',
            context: $ctx,
            defaultValue: false,
            method: $method,
        );

        $parent      = $this->tracer->spanBuilder('parent')->startSpan();
        $parentScope = $parent->activate();
        try {
            $data      = $hook->beforeEvaluation($sc, []);
            $afterData = $hook->afterEvaluation($sc, $data, $this->detail(true, null));
        } finally {
            $parentScope->detach();
            $parent->end();
        }

        $spans = $this->storage->getArrayCopy();
        // Exporter records spans in the order they end — wrapper first, parent second.
        $this->assertCount(2, $spans);
        $this->assertInstanceOf(ImmutableSpan::class, $spans[0]);
        $this->assertInstanceOf(ImmutableSpan::class, $spans[1]);

        $wrapper    = $spans[0];
        $parentSpan = $spans[1];
        $this->assertSame('parent', $parentSpan->getName());

        return [
            'parent'    => $parentSpan,
            'wrapper'   => $wrapper,
            'afterData' => $afterData,
        ];
    }

    /**
     * Runs a full before→after lifecycle with no caller-supplied parent
     * span. The wrapper span should be a root span.
     *
     * @return array{wrapper: ImmutableSpan, afterData: array<string, mixed>}
     */
    private function runLifecycleNoParent(
        TracingHookOptions $options,
        string $method = 'variation',
    ): array {
        $hook = new TracingHook($options, $this->tracer);
        $ctx  = LDContext::create('user-abc');
        $sc   = new EvaluationSeriesContext(
            flagKey: 'my-flag',
            context: $ctx,
            defaultValue: false,
            method: $method,
        );

        $data      = $hook->beforeEvaluation($sc, []);
        $afterData = $hook->afterEvaluation($sc, $data, $this->detail(true, null));

        $spans = $this->storage->getArrayCopy();
        $this->assertCount(1, $spans);
        $this->assertInstanceOf(ImmutableSpan::class, $spans[0]);

        return [
            'wrapper'   => $spans[0],
            'afterData' => $afterData,
        ];
    }

    /**
     * Spec §1.2.3.1.
     *
     * With `addSpans=false` (the default), `beforeEvaluation` is a pass
     * through: returned `$data` is unchanged, no stash keys are added, and
     * no wrapper span is created. The hook must not emit a span from
     * `beforeEvaluation` alone.
     */
    public function testBeforeEvaluationIsNoOpWhenAddSpansDisabled(): void
    {
        $hook = new TracingHook(new TracingHookOptions(), $this->tracer);
        $sc   = $this->seriesContext();

        $parent      = $this->tracer->spanBuilder('parent')->startSpan();
        $parentScope = $parent->activate();

        $inputData = ['existing' => 'value'];
        try {
            $returned = $hook->beforeEvaluation($sc, $inputData);
        } finally {
            $parentScope->detach();
            $parent->end();
        }

        // Returned data matches input; no stash keys added.
        $this->assertSame($inputData, $returned);
        $this->assertArrayNotHasKey('__ld_otel_span', $returned);
        $this->assertArrayNotHasKey('__ld_otel_scope', $returned);

        // Only the caller's parent span was exported.
        $spans = $this->storage->getArrayCopy();
        $this->assertCount(1, $spans);
        $this->assertInstanceOf(ImmutableSpan::class, $spans[0]);
        $this->assertSame('parent', $spans[0]->getName());
    }

    /**
     * Spec §1.2.3.3, §1.2.3.4, §1.2.3.5.
     *
     * With `addSpans=true` inside an active parent span, `beforeEvaluation`
     * creates a wrapper span parented to the caller's span. The wrapper
     * carries exactly two attributes (`feature_flag.key`,
     * `feature_flag.context.id`) — NOT the provider name, result.*, or
     * set.id, all of which belong on the `feature_flag` event.
     */
    public function testAddSpansCreatesChildSpanParentedToActiveSpan(): void
    {
        $result  = $this->runLifecycleWithParent(new TracingHookOptions(addSpans: true));
        $parent  = $result['parent'];
        $wrapper = $result['wrapper'];

        // Wrapper is parented to the caller's span.
        $this->assertSame($parent->getSpanId(), $wrapper->getParentSpanId());
        $this->assertSame($parent->getTraceId(), $wrapper->getTraceId());

        // Wrapper name is the documented method-prefixed form.
        $this->assertSame('LDClient.variation', $wrapper->getName());

        // Wrapper attributes are exactly the two required by spec §1.2.3.4-5.
        $this->assertSame(
            [
                Attributes::FEATURE_FLAG_KEY        => 'my-flag',
                Attributes::FEATURE_FLAG_CONTEXT_ID => LDContext::create('user-abc')->getFullyQualifiedKey(),
            ],
            $wrapper->getAttributes()->toArray(),
        );
    }

    /**
     * Spec §1.2.3.3.
     *
     * With `addSpans=true` and no active parent span, `beforeEvaluation`
     * still creates a wrapper span; it becomes a root span. The wrapper's
     * attributes are still exactly the two required ones.
     */
    public function testAddSpansCreatesRootSpanWhenNoActiveSpan(): void
    {
        $result  = $this->runLifecycleNoParent(new TracingHookOptions(addSpans: true));
        $wrapper = $result['wrapper'];

        // Root span: the parent span context is invalid.
        $this->assertFalse($wrapper->getParentContext()->isValid());

        // Name and attributes are the same as the parented case.
        $this->assertSame('LDClient.variation', $wrapper->getName());
        $this->assertSame(
            [
                Attributes::FEATURE_FLAG_KEY        => 'my-flag',
                Attributes::FEATURE_FLAG_CONTEXT_ID => LDContext::create('user-abc')->getFullyQualifiedKey(),
            ],
            $wrapper->getAttributes()->toArray(),
        );
    }

    /**
     * Spec §1.2.3.6.
     *
     * Span name is `LDClient.<method>` with the raw method string taken
     * from the EvaluationSeriesContext. The PHP SDK supplies lowerCamel
     * method names, yielding `LDClient.variation`, `LDClient.variationDetail`,
     * and `LDClient.migrationVariation`. This is a documented PHP-specific
     * divergence from the spec examples' PascalCase; fixing the casing at
     * the PHP SDK level is tracked separately.
     */
    public function testAddSpansSpanNameMatchesMethodVariation(): void
    {
        $result = $this->runLifecycleNoParent(new TracingHookOptions(addSpans: true), 'variation');
        $this->assertSame('LDClient.variation', $result['wrapper']->getName());
    }

    public function testAddSpansSpanNameMatchesMethodVariationDetail(): void
    {
        $result = $this->runLifecycleNoParent(new TracingHookOptions(addSpans: true), 'variationDetail');
        $this->assertSame('LDClient.variationDetail', $result['wrapper']->getName());
    }

    public function testAddSpansSpanNameMatchesMethodMigrationVariation(): void
    {
        $result = $this->runLifecycleNoParent(new TracingHookOptions(addSpans: true), 'migrationVariation');
        $this->assertSame('LDClient.migrationVariation', $result['wrapper']->getName());
    }

    /**
     * Spec §1.2.3.2.
     *
     * The `feature_flag` event must attach to the caller's parent span,
     * NOT to our wrapper span. This is the pin that catches a broken
     * detach-end-read ordering in `afterEvaluation`: if the scope were
     * detached after the event emission (or not at all), the current span
     * at emission time would be the wrapper and the event would land on it.
     */
    public function testFeatureFlagEventAttachesToParentSpanNotWrapper(): void
    {
        $result  = $this->runLifecycleWithParent(new TracingHookOptions(addSpans: true));
        $parent  = $result['parent'];
        $wrapper = $result['wrapper'];

        // Event is on the parent, exactly one.
        $parentEvents = $parent->getEvents();
        $this->assertCount(1, $parentEvents);
        $this->assertSame('feature_flag', $parentEvents[0]->getName());

        // Required attributes present on the parent's event.
        $eventAttrs = $parentEvents[0]->getAttributes()->toArray();
        $this->assertSame('my-flag', $eventAttrs[Attributes::FEATURE_FLAG_KEY]);
        $this->assertSame('LaunchDarkly', $eventAttrs[Attributes::FEATURE_FLAG_PROVIDER_NAME]);
        $this->assertSame(
            LDContext::create('user-abc')->getFullyQualifiedKey(),
            $eventAttrs[Attributes::FEATURE_FLAG_CONTEXT_ID],
        );

        // Wrapper has NO events of its own.
        $this->assertCount(0, $wrapper->getEvents());
    }

    /**
     * Spec §1.2.3.7.
     *
     * The wrapper span is ended during `afterEvaluation`. The in-memory
     * exporter only receives spans on end, so the wrapper's presence in
     * the storage array is itself a partial proof; we additionally assert
     * the recorded end time is non-zero and `hasEnded()` is true.
     */
    public function testWrapperSpanIsEndedBeforeEventWrite(): void
    {
        $result  = $this->runLifecycleWithParent(new TracingHookOptions(addSpans: true));
        $wrapper = $result['wrapper'];

        $this->assertTrue($wrapper->hasEnded());
        $this->assertGreaterThan(0, $wrapper->getEndEpochNanos());
    }

    /**
     * Spec §1.2.3 (hygiene).
     *
     * The reserved `__ld_otel_span` and `__ld_otel_scope` keys used to
     * thread state between `beforeEvaluation` and `afterEvaluation` MUST
     * NOT leak out of `afterEvaluation`. Downstream hook stages (and other
     * hooks in the chain) should never see OpenTelemetry objects in `$data`.
     */
    public function testDataKeysNotLeakedAfterAfterEvaluation(): void
    {
        $result    = $this->runLifecycleWithParent(new TracingHookOptions(addSpans: true));
        $afterData = $result['afterData'];

        $this->assertArrayNotHasKey('__ld_otel_span', $afterData);
        $this->assertArrayNotHasKey('__ld_otel_scope', $afterData);
    }
}
