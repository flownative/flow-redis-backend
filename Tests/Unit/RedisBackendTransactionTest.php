<?php
declare(strict_types=1);

namespace Flownative\RedisBackend\Tests\Unit;

use Flownative\RedisBackend\RedisBackend;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\FrontendInterface;
use PHPUnit\Framework\TestCase;

final class RedisBackendTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The redis extension is not installed.');
        }
    }

    public function testSetFailsWhenTransactionCannotStart(): void
    {
        $redis = $this->getMockBuilder(\Redis::class)->disableOriginalConstructor()->getMock();
        $redis->method('exists')->willReturn(0);
        $redis->method('ttl')->willReturn(-2);
        $redis->expects(self::once())->method('multi')->willReturn(false);
        $redis->expects(self::atLeastOnce())->method('discard');

        $backend = $this->createBackend($redis);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Could not start Redis transaction');
        $backend->set('entry', 'value');
    }

    public function testSetFailsWhenTransactionCannotExecute(): void
    {
        $redis = $this->getMockBuilder(\Redis::class)->disableOriginalConstructor()->getMock();
        $redis->method('exists')->willReturn(0);
        $redis->method('ttl')->willReturn(-2);
        $redis->method('multi')->willReturn($redis);
        $redis->method('set')->willReturn($redis);
        $redis->method('sAdd')->willReturn($redis);
        $redis->method('persist')->willReturn($redis);
        $redis->expects(self::once())->method('exec')->willReturn(false);
        $redis->expects(self::atLeastOnce())->method('discard');

        $backend = $this->createBackend($redis);

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis transaction failed');
        $backend->set('entry', 'value');
    }

    private function createBackend(\Redis $redis): RedisBackend
    {
        $backend = new RedisBackend(
            new EnvironmentConfiguration('Flownative Redis Backend Unit Tests', sys_get_temp_dir()),
            ['redis' => $redis]
        );
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('getIdentifier')->willReturn('Unit_Test');
        $backend->setCache($cache);
        return $backend;
    }
}
