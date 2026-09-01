<?php

namespace szenario\craftobservatory\exceptions;

final class AnalyticsRateLimitedException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("PostHog rate limit active; retry after {$retryAfterSeconds} seconds.");
    }
}
