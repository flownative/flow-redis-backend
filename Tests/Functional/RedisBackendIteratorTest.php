<?php
declare(strict_types=1);

namespace Flownative\RedisBackend\Tests\Functional;

use Flownative\RedisBackend\RedisBackend;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Frontend\VariableFrontend;
use PHPUnit\Framework\TestCase;

final class RedisBackendIteratorTest extends TestCase
{
    private EnvironmentConfiguration $environment;
    private string $cacheIdentifier;
    private VariableFrontend $cache;
    private VariableFrontend $otherClient;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The redis PHP extension is required.');
        }
        $this->environment = new EnvironmentConfiguration('iterator-tests', sys_get_temp_dir());
        $this->cacheIdentifier = 'Iterator_' . bin2hex(random_bytes(8));
        $this->cache = $this->createCache($this->cacheIdentifier);
        $this->otherClient = $this->createCache($this->cacheIdentifier);
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->flush();
        }
    }

    private function createCache(string $identifier): VariableFrontend
    {
        $backend = new RedisBackend($this->environment, [
            'hostname' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            'database' => (int)(getenv('REDIS_DATABASE') ?: 0),
        ]);
        $cache = new VariableFrontend($identifier, $backend);
        $cache->initializeObject();
        return $cache;
    }

    public function testDeletionBetweenValidityCheckAndReadReturnsCacheMiss(): void
    {
        $this->cache->set('session', ['storageIdentifier' => 'storage'], [], 0);
        $iterator = $this->cache->getIterator();
        self::assertTrue($iterator->valid());

        $this->otherClient->remove('session');

        self::assertFalse($iterator->current());
        self::assertSame('session', $iterator->key());
        $iterator->next();
        self::assertFalse($iterator->valid());
    }

    public function testDeletionAfterReadPreservesTheKeyForThatValue(): void
    {
        $this->cache->set('session', ['value' => 42], [], 0);
        $iterator = $this->cache->getIterator();
        self::assertTrue($iterator->valid());
        self::assertSame(['value' => 42], $iterator->current());

        $this->otherClient->remove('session');

        self::assertSame('session', $iterator->key());
    }

    public function testTagInvalidationBetweenValidityCheckAndReadReturnsCacheMiss(): void
    {
        $this->cache->set('session', ['value' => 42], ['session-tag'], 0);
        $iterator = $this->cache->getIterator();
        self::assertTrue($iterator->valid());

        $this->otherClient->flushByTag('session-tag');

        self::assertFalse($iterator->current());
        self::assertSame('session', $iterator->key());
    }

    public function testMissingEntryBeforeValidityCheckIsInvalid(): void
    {
        $this->cache->set('session', 'value', [], 0);
        $iterator = $this->cache->getIterator();
        $this->otherClient->remove('session');
        self::assertFalse($iterator->valid());
    }

    public function testExpirationBetweenValidityCheckAndReadReturnsCacheMiss(): void
    {
        $this->cache->set('session', 'value', [], 1);
        $iterator = $this->cache->getIterator();
        self::assertTrue($iterator->valid());
        $deadline = microtime(true) + 3;
        do {
            usleep(20000);
        } while ($this->otherClient->has('session') && microtime(true) < $deadline);
        self::assertFalse($this->otherClient->has('session'), 'The fixture must expire before reading the iterator.');
        self::assertFalse($iterator->current());
        self::assertSame('session', $iterator->key());
    }

    public function testUpdatedValueIsReadFromTheBackend(): void
    {
        $this->cache->set('session', 'old-value', [], 0);
        $iterator = $this->cache->getIterator();
        self::assertTrue($iterator->valid());
        self::assertSame('old-value', $iterator->current());
        $this->otherClient->set('session', 'new-value', [], 0);
        self::assertSame('new-value', $iterator->current());
        self::assertSame('session', $iterator->key());
    }

    public function testNormalIterationCanBeRewoundAndExhausted(): void
    {
        $expected = ['first' => ['value' => 1], 'second' => ['value' => 2]];
        foreach ($expected as $key => $value) {
            $this->cache->set($key, $value, ['group'], 0);
        }
        $iterator = $this->cache->getIterator();
        self::assertEquals($expected, iterator_to_array($iterator));
        self::assertFalse($iterator->valid());
        self::assertEquals($expected, iterator_to_array($iterator));
    }

    public function testEmptyCacheDoesNotProduceAnEntry(): void
    {
        self::assertSame([], iterator_to_array($this->cache->getIterator()));
    }

    public function testCacheIdentifiersIsolateIterationAndInvalidation(): void
    {
        $otherCache = $this->createCache($this->cacheIdentifier . '_other');
        try {
            $this->cache->set('session', 'first-cache', ['group'], 0);
            $otherCache->set('session', 'other-cache', ['group'], 0);
            $this->cache->flushByTag('group');
            self::assertFalse($this->cache->get('session'));
            self::assertSame(['session' => 'other-cache'], iterator_to_array($otherCache->getIterator()));
        } finally {
            $otherCache->flush();
        }
    }
}
