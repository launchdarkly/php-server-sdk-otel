<?php

declare(strict_types=1);

namespace LaunchDarkly\OpenTelemetry;

use Psr\Log\LoggerInterface;

/**
 * Immutable configuration for the LaunchDarkly OpenTelemetry tracing hook.
 *
 * Instances are constructed once and passed to the tracing hook at registration
 * time. All properties are read-only; mutation is not supported.
 *
 * @psalm-api
 */
final class TracingHookOptions
{
    /**
     * When true, the evaluated flag value will be attached to the `feature_flag`
     * span event as the `feature_flag.result.value` attribute.
     */
    public readonly bool $includeValue;

    /**
     * Experimental. When true, the tracing hook will create a span for every
     * variation method call in addition to the `feature_flag` span event.
     *
     * Span events are always added and are unaffected by this setting. The
     * structure and nesting of these spans may change in future versions.
     */
    public readonly bool $addSpans;

    /**
     * Optional environment ID emitted as the `feature_flag.set.id` attribute
     * on the `feature_flag` span event.
     *
     * An empty or whitespace-only input is discarded during construction and
     * will be stored as `null`.
     *
     * @var non-empty-string|null
     */
    public readonly ?string $environmentId;

    /**
     * Optional PSR-3 logger used to report configuration issues encountered
     * while constructing the options object (for example, an invalid
     * environment ID). When `null`, no diagnostic output is produced.
     */
    public readonly ?LoggerInterface $logger;

    /**
     * @param bool                 $includeValue  See {@see self::$includeValue}.
     * @param bool                 $addSpans      See {@see self::$addSpans}.
     * @param string|null          $environmentId See {@see self::$environmentId}.
     * @param LoggerInterface|null $logger        See {@see self::$logger}.
     */
    public function __construct(
        bool $includeValue = false,
        bool $addSpans = false,
        ?string $environmentId = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->includeValue  = $includeValue;
        $this->addSpans      = $addSpans;
        $this->logger        = $logger;
        $this->environmentId = self::validateEnvironmentId($environmentId, $logger);
    }

    /**
     * @return non-empty-string|null
     */
    private static function validateEnvironmentId(
        ?string $environmentId,
        ?LoggerInterface $logger,
    ): ?string {
        if ($environmentId === null) {
            return null;
        }

        if (trim($environmentId) === '') {
            $logger?->warning(
                'LaunchDarkly Tracing Hook: Invalid environmentId provided. It must be a non-empty string.'
            );
            return null;
        }

        // A value that survives the trim-empty check has at least one
        // non-whitespace character and is therefore a non-empty string.
        // Psalm needs the explicit === '' guard to narrow the return type.
        if ($environmentId === '') {
            return null;
        }

        return $environmentId;
    }
}
