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
 *   §1.2.1   -- Hook class is exported.                      (file existence / class usage)
 *   §1.2.2.1 -- `afterEvaluation` attaches an event to span. (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.1.1 -- no active / invalid span → no-op.          (testNoActiveSpan..., testInvalidSpanContext...)
 *   §1.2.2.2 -- event name is `feature_flag`.                (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.3 -- `feature_flag.key` present.                  (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.4 -- `feature_flag.provider.name` = `LaunchDarkly`. (testActiveSpanReceivesFeatureFlagEvent...)
 *   §1.2.2.5 -- `feature_flag.context.id` = canonical key.   (testActiveSpanReceivesFeatureFlagEvent...)
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
            $hook->afterEvaluation($this->seriesContext('my-flag', $ctx), [], $this->detail());
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
}
