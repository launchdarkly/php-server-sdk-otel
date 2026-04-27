<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry;

use LaunchDarkly\EvaluationDetail;
use LaunchDarkly\Hooks\EvaluationSeriesContext;
use LaunchDarkly\Hooks\Hook;
use LaunchDarkly\Hooks\Metadata;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;

/**
 * LaunchDarkly feature flag evaluation hook that enriches OpenTelemetry spans
 * with `feature_flag` events whenever a flag is evaluated.
 *
 * When a LaunchDarkly SDK variation call runs inside an active OpenTelemetry
 * span, this hook attaches a `feature_flag` span event carrying the required
 * semantic-convention attributes (`feature_flag.key`,
 * `feature_flag.provider.name`, `feature_flag.context.id`). When no span is
 * active, the hook is a no-op.
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
     * Stored for future use — the `addSpans` experimental feature will create
     * spans through this tracer. Currently unused by `afterEvaluation`, which
     * only adds events to the already-active span.
     *
     * @psalm-suppress UnusedProperty
     */
    private readonly TracerInterface $tracer;

    /**
     * Stored for future use — later stages will consult the options (for
     * example, to decide whether to include the evaluated value on the span
     * event). Currently inert in this PR.
     *
     * @psalm-suppress UnusedProperty
     */
    private readonly TracingHookOptions $options;

    /**
     * @param TracingHookOptions   $options Configuration object controlling optional attributes and features.
     * @param TracerInterface|null $tracer  Tracer used for future span-creation features. When `null`, a
     *                                      tracer named `launchdarkly` is acquired from the global
     *                                      OpenTelemetry tracer provider. Primarily an injection point for
     *                                      tests.
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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    #[\Override]
    public function afterEvaluation(
        EvaluationSeriesContext $seriesContext,
        array $data,
        EvaluationDetail $detail,
    ): array {
        $span = Span::fromContext(Context::getCurrent());

        // Spec §1.2.2.1.1: if the surrounding span context is not valid (no
        // active span, or the active span has an invalid context), the hook
        // must not emit an event.
        if (!$span->getContext()->isValid()) {
            return $data;
        }

        $span->addEvent(self::EVENT_NAME, [
            Attributes::FEATURE_FLAG_KEY           => $seriesContext->flagKey,
            Attributes::FEATURE_FLAG_PROVIDER_NAME => Attributes::PROVIDER_NAME,
            Attributes::FEATURE_FLAG_CONTEXT_ID    => $seriesContext->context->getFullyQualifiedKey(),
        ]);

        return $data;
    }
}
