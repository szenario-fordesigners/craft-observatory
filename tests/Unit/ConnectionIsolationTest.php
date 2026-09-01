<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\sources\PostHogAnalyticsSource;

/**
 * Everything the plugin keys on a PostHog connection must key on host *and* project.
 *
 * Project IDs are per-instance sequential integers, so project 1 on eu.posthog.com and project 1
 * on a self-hosted instance are unrelated. The query cache was keyed on md5($sql) alone, and no
 * query carries the host or project — so after switching projects an identical query returned the
 * previous project's rows for up to 24h, and a background sync could persist them into the new
 * project's mirror and mark the day complete: cross-project disclosure plus a corrupted mirror.
 */
#[CoversClass(PostHogAnalyticsSource::class)]
class ConnectionIsolationTest extends TestCase
{
    private \ReflectionMethod $connectionId;
    private \ReflectionMethod $cacheKey;

    protected function setUp(): void
    {
        $this->connectionId = new \ReflectionMethod(PostHogAnalyticsSource::class, '_connectionIdFor');
        $this->cacheKey = new \ReflectionMethod(PostHogAnalyticsSource::class, '_queryCacheKeyFor');
    }

    public function testSameProjectIdOnDifferentHostsIsADifferentConnection(): void
    {
        $this->assertNotSame(
            $this->id('https://eu.posthog.com', '1'),
            $this->id('https://us.posthog.com', '1'),
            'sequential per-instance ids collide across hosts',
        );
    }

    public function testDifferentProjectsOnOneHostAreDifferentConnections(): void
    {
        $this->assertNotSame($this->id('https://eu.posthog.com', '1'), $this->id('https://eu.posthog.com', '2'));
    }

    public function testTheSameConnectionTypedDifferentlyIsOneConnection(): void
    {
        $canonical = $this->id('https://eu.posthog.com', '208146');

        // Scheme, case and trailing slash are the same instance written differently — treating
        // them as distinct would orphan the mirror on a cosmetic settings edit.
        $this->assertSame($canonical, $this->id('http://eu.posthog.com', '208146'));
        $this->assertSame($canonical, $this->id('https://EU.PostHog.com', '208146'));
        $this->assertSame($canonical, $this->id('https://eu.posthog.com/', '208146'));
        $this->assertSame($canonical, $this->id('  https://eu.posthog.com  ', '208146'));
        $this->assertSame($canonical, $this->id('https://eu.posthog.com', ' 208146 '));
    }

    public function testHalfConfiguredConnectionHasNoIdentity(): void
    {
        // A partial key would let a sync write into a mirror it cannot name.
        $this->assertNull($this->id('', '208146'));
        $this->assertNull($this->id('https://eu.posthog.com', ''));
        $this->assertNull($this->id(null, null));
        $this->assertNull($this->id('https://eu.posthog.com', null));
    }

    public function testIntegerProjectIdIsAccepted(): void
    {
        // Project config can hand back an int rather than a string.
        $this->assertSame($this->id('https://eu.posthog.com', '208146'), $this->id('https://eu.posthog.com', 208146));
    }

    public function testIdenticalSqlOnDifferentConnectionsGetsDifferentCacheKeys(): void
    {
        $sql = 'SELECT count() AS pageviews FROM events';

        $this->assertNotSame(
            $this->key($this->id('https://eu.posthog.com', '1'), $sql),
            $this->key($this->id('https://us.posthog.com', '1'), $sql),
        );
        $this->assertNotSame(
            $this->key($this->id('https://eu.posthog.com', '1'), $sql),
            $this->key($this->id('https://eu.posthog.com', '2'), $sql),
        );
    }

    public function testSameSqlOnTheSameConnectionSharesOneCacheKey(): void
    {
        // Single and batched callers must still hit one entry, which is the whole point of
        // keying on the SQL in the first place.
        $sql = 'SELECT count() AS pageviews FROM events';
        $id = $this->id('https://eu.posthog.com', '1');

        $this->assertSame($this->key($id, $sql), $this->key($id, $sql));
    }

    public function testDifferentSqlOnOneConnectionGetsDifferentCacheKeys(): void
    {
        $id = $this->id('https://eu.posthog.com', '1');

        $this->assertNotSame($this->key($id, 'SELECT 1'), $this->key($id, 'SELECT 2'));
    }

    public function testUnconfiguredConnectionStillYieldsAUsableKey(): void
    {
        // Reached before the settings check on a misconfigured install; it must not collide with
        // a real connection's entries.
        $key = $this->key(null, 'SELECT 1');

        $this->assertStringStartsWith('posthog_query_', $key);
        $this->assertNotSame($key, $this->key($this->id('https://eu.posthog.com', '1'), 'SELECT 1'));
    }

    private function id(mixed $host, mixed $projectId): ?string
    {
        return $this->connectionId->invoke(null, $host, $projectId);
    }

    private function key(?string $connectionId, string $sql): string
    {
        return $this->cacheKey->invoke(null, $connectionId, $sql);
    }
}
