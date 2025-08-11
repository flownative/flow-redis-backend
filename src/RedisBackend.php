<?php
declare(strict_types=1);

namespace Flownative\RedisBackend;

/**
 * A more opinionated version of the default redis backend
 * for testing the changes in here in production environments.
 * These changes should eventually find their way back to
 * upstream.
 * 
 * @see \Neos\Cache\Backend\RedisBackend
 */
class RedisBackend extends \Neos\Cache\Backend\RedisBackend
{
    private function getRedisClient(): \Redis
    {
        $configuration = [
            'host' => $this->hostname,
            'readTimeout' => 10,
            'connectTimeout' => 10,
            'persistent' => true,
            'backoff' => [
                'algorithm' => \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
                'base' => 5,
                'cap' => 75,
            ],
        ];

        if (str_starts_with($this->hostname, '/') === false) {
            $configuration['port'] = $this->port;
        }

        if ($this->password !== '') {
            $configuration['auth'] = [$this->password];
        }

        $redis = new \Redis($configuration);
        $redis->select($this->database);
        return $redis;
    }
}
