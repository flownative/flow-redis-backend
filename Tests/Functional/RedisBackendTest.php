<?php
declare(strict_types=1);

namespace Flownative\RedisBackend\Tests\Functional;

use Flownative\RedisBackend\RedisBackend;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Frontend\FrontendInterface;
use PHPUnit\Framework\TestCase;

final class RedisBackendTest extends TestCase
{
    private const CACHE_IDENTIFIER = 'Flownative_RedisBackend_Test';

    private RedisBackend $backend;

    private \Redis $redis;

    private string $identifierPrefix;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The redis extension is not installed.');
        }

        $hostname = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $database = (int)(getenv('REDIS_DATABASE') ?: 0);
        $applicationIdentifier = 'Flownative Redis Backend Tests ' . getmypid();
        $this->identifierPrefix = md5($applicationIdentifier) . ':' . self::CACHE_IDENTIFIER . ':';

        try {
            $this->redis = new \Redis();
            $this->redis->connect($hostname, $port, 1.0);
            $this->redis->select($database);

            $this->backend = new RedisBackend(
                new EnvironmentConfiguration($applicationIdentifier, sys_get_temp_dir()),
                [
                    'hostname' => $hostname,
                    'port' => $port,
                    'database' => $database,
                ]
            );
            $cache = $this->createMock(FrontendInterface::class);
            $cache->method('getIdentifier')->willReturn(self::CACHE_IDENTIFIER);
            $this->backend->setCache($cache);
            $this->backend->flush();
        } catch (\RedisException $exception) {
            self::markTestSkipped('Redis is not available: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->backend)) {
            $this->backend->flush();
        }
        if (isset($this->redis)) {
            $this->redis->close();
        }
    }

    public function testCompleteTagIndexReturnsEntry(): void
    {
        $this->backend->set('entry', 'value', ['first', 'second']);

        self::assertSame('value', $this->backend->get('entry'));
        self::assertTrue($this->backend->has('entry'));
    }

    public function testMissingReverseTagIndexInvalidatesEntry(): void
    {
        $this->backend->set('entry', 'stale value', ['content']);
        $this->redis->unlink($this->identifierPrefix . 'tags:entry');

        self::assertFalse($this->backend->get('entry'));
        self::assertSame(0, $this->redis->exists($this->identifierPrefix . 'entry:entry'));
    }

    public function testMissingForwardTagIndexInvalidatesEntryAndKnownMemberships(): void
    {
        $this->backend->set('entry', 'stale value', ['first', 'second']);
        $this->redis->unlink($this->identifierPrefix . 'tag:first');

        self::assertFalse($this->backend->get('entry'));
        self::assertSame(0, $this->redis->exists($this->identifierPrefix . 'entry:entry'));
        self::assertSame(0, $this->redis->exists($this->identifierPrefix . 'tags:entry'));
        self::assertFalse($this->redis->sIsMember($this->identifierPrefix . 'tag:second', 'entry'));
    }

    public function testUntaggedEntryHasIntegrityMetadata(): void
    {
        $this->backend->set('entry', 'value');

        self::assertCount(1, $this->redis->sMembers($this->identifierPrefix . 'tags:entry'));
        self::assertSame('value', $this->backend->get('entry'));
    }

    public function testFiniteEntryLifetimeIsAppliedToNewTagIndexes(): void
    {
        $this->backend->set('entry', 'value', ['content'], 120);

        $reverseTtl = $this->redis->ttl($this->identifierPrefix . 'tags:entry');
        $forwardTtl = $this->redis->ttl($this->identifierPrefix . 'tag:content');
        self::assertGreaterThan(0, $reverseTtl);
        self::assertLessThanOrEqual(120, $reverseTtl);
        self::assertGreaterThan(0, $forwardTtl);
        self::assertLessThanOrEqual(120, $forwardTtl);
    }

    public function testLegacyTaggedEntryWithoutSentinelRemainsReadable(): void
    {
        $this->redis->set($this->identifierPrefix . 'entry:entry', 'legacy value');
        $this->redis->sAdd($this->identifierPrefix . 'tags:entry', 'content');
        $this->redis->sAdd($this->identifierPrefix . 'tag:content', 'entry');

        self::assertSame('legacy value', $this->backend->get('entry'));
    }

    public function testPersistentConnectionIsScopedToCache(): void
    {
        $redisProperty = new \ReflectionProperty($this->backend, 'redis');
        $backendRedis = $redisProperty->getValue($this->backend);

        self::assertSame($this->identifierPrefix, $backendRedis->getPersistentID());
    }
}
