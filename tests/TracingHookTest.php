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
 *   §1.2.2.10  -- `variationIndex` present when non-null.         (testVariationIndex*)
 *   §1.2.2.10.1 -- `variationIndex` type is int.                  (testVariationIndexIsInteger)
 *   §1.2.2.11  -- `reason.inExperiment` present when true.        (testInExperimentEmittedWhenTrue)
 *   §1.2.2.11.1 -- omitted when false (not emitted as `false`).   (testInExperimentOmittedWhenFalse)
 *
 * `feature_flag.set.id` (§1.2.2.9 and subclauses) is not yet emitted and
 * is not covered here.
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
}
