<?php
declare(strict_types=1);

namespace Flownative\RedisBackend;

use Neos\Cache\Backend\FreezableBackendInterface;
use Neos\Cache\Backend\IterableBackendInterface;
use Neos\Cache\Backend\PhpCapableBackendInterface;
use Neos\Cache\Backend\RequireOnceFromValueTrait;
use Neos\Cache\Backend\TaggableBackendInterface;
use Neos\Cache\Backend\WithStatusInterface;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Error\Messages\Error;
use Neos\Error\Messages\Notice;
use Neos\Error\Messages\Result;

/**
 * A more opinionated version of the default redis backend
 * for testing the changes in here in production environments.
 * These changes should eventually find their way back to
 * upstream.
 *
 * @see \Neos\Cache\Backend\RedisBackend
 */
class RedisBackend extends \Neos\Cache\Backend\AbstractBackend implements TaggableBackendInterface, IterableBackendInterface, FreezableBackendInterface, PhpCapableBackendInterface, WithStatusInterface
{
    use RequireOnceFromValueTrait;

    public const MIN_REDIS_VERSION = '6.0.0';

    /**
     * Stored in the reverse tag set so that untagged entries also have integrity metadata.
     */
    private const ENTRY_TAG_SENTINEL = "\0flownative-entry\0";

    /**
     * @var \Redis
     */
    protected $redis;

    protected ?bool $frozen = null;

    protected string $hostname = '127.0.0.1';

    protected int $port = 6379;

    protected int $database = 0;

    protected string $password = '';

    protected int $compressionLevel = 0;

    /**
     * Redis allows a maximum of 1024 * 1024 parameters, but we use a lower limit to prevent long blocking calls.
     */
    protected int $batchSize = 100000;

    /**
     * @var \ArrayIterator|null
     */
    private $entryIterator;

    /**
     * Constructs this backend
     *
     * @param EnvironmentConfiguration $environmentConfiguration
     * @param array $options Configuration options - depends on the actual backend
     * @throws CacheException
     */
    public function __construct(EnvironmentConfiguration $environmentConfiguration, array $options)
    {
        parent::__construct($environmentConfiguration, $options);
    }

    /**
     * The cache identifier is part of the persistent connection ID, so the
     * lazy Redis client must be created after the frontend has been assigned.
     */
    public function setCache(FrontendInterface $cache): void
    {
        parent::setCache($cache);
        if (!$this->redis instanceof \Redis) {
            $this->redis = $this->getRedisClient();
        }
    }

    /**
     * Saves data in the cache.
     *
     * @param string $entryIdentifier An identifier for this specific cache entry
     * @param string $data The data to be stored
     * @param array $tags Tags to associate with this cache entry. If the backend does not support tags, this option can be ignored.
     * @param integer|null $lifetime Lifetime of this cache entry in seconds. If NULL is specified, the default lifetime is used. "0" means unlimited lifetime.
     * @throws \RuntimeException
     * @throws CacheException
     * @api
     */
    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
        if ($this->isFrozen()) {
            throw new \RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1323344192);
        }

        if ($lifetime === null) {
            $lifetime = $this->defaultLifetime;
        }

        $setOptions = [];
        if ($lifetime > 0) {
            $setOptions['ex'] = $lifetime;
        }

        $tags = array_values(array_unique($tags));
        $reverseTagKey = $this->getPrefixedIdentifier('tags:' . $entryIdentifier);
        $reverseTagExpire = $this->calculateExpires($reverseTagKey, $lifetime);
        $redisTags = array_map(function (string $tag) use ($lifetime, $entryIdentifier): array {
            $key = $this->getPrefixedIdentifier('tag:' . $tag);
            return [
                'key' => $key,
                'value' => $entryIdentifier,
                'expire' => $this->calculateExpires($key, $lifetime)
            ];
        }, $tags);

        try {
            $this->beginTransaction('writing cache entry');

            $this->redis->set($this->getPrefixedIdentifier('entry:' . $entryIdentifier), $this->compress($data), $setOptions);
            $this->redis->sAdd($reverseTagKey, self::ENTRY_TAG_SENTINEL, ...$tags);
            $this->queueExpiration($reverseTagKey, $reverseTagExpire);
            foreach ($redisTags as $tag) {
                $this->redis->sAdd($tag['key'], $tag['value']);
                $this->queueExpiration($tag['key'], $tag['expire']);
            }

            $results = $this->redis->exec();
            if (!is_array($results)) {
                throw new CacheException('Redis transaction failed while writing cache entry.', 1753614722);
            }

            $this->verifySetTransactionResults($results, count($redisTags));
        } catch (\Throwable $exception) {
            $this->discardTransaction();
            if ($exception instanceof CacheException) {
                throw $exception;
            }
            throw new CacheException('Redis transaction failed while writing cache entry: ' . $exception->getMessage(), 1753614723, $exception);
        }
    }

    /**
     * Calculate the max lifetime for a tag
     */
    private function calculateExpires(string $tag, int $lifetime): int
    {
        $ttl = (int)$this->redis->ttl($tag);
        if ($ttl === -1 || $lifetime === self::UNLIMITED_LIFETIME) {
            return -1;
        }
        if ($ttl === -2) {
            return $lifetime;
        }
        return max($ttl, $lifetime);
    }

    private function queueExpiration(string $key, int $expire): void
    {
        if ($expire > 0) {
            $this->redis->expire($key, $expire);
        } else {
            $this->redis->persist($key);
        }
    }

    /**
     * SET and SADD must succeed. PERSIST may legitimately return false if a
     * set was already persistent; EXPIRE cannot fail after a successful SADD.
     *
     * @param array<int, mixed> $results
     * @throws CacheException
     */
    private function verifySetTransactionResults(array $results, int $tagCount): void
    {
        if (count($results) !== 3 + ($tagCount * 2)) {
            throw new CacheException('Redis transaction returned an unexpected number of results while writing cache entry.', 1753614724);
        }

        $requiredResultIndexes = [0, 1];
        for ($index = 0; $index < $tagCount; $index++) {
            $requiredResultIndexes[] = 3 + ($index * 2);
        }

        foreach ($requiredResultIndexes as $resultIndex) {
            if (!array_key_exists($resultIndex, $results) || $results[$resultIndex] === false) {
                throw new CacheException('Redis transaction contained a failed command while writing cache entry.', 1753614725);
            }
        }
    }

    private function discardTransaction(): void
    {
        try {
            $this->redis->discard();
        } catch (\Throwable) {
            // The transaction may already have been executed or the connection may be unavailable.
        }
        try {
            $this->redis->unwatch();
        } catch (\Throwable) {
            // The connection may be unavailable.
        }
    }

    /**
     * @throws CacheException
     */
    private function beginTransaction(string $operation): void
    {
        try {
            $result = $this->redis->multi();
        } catch (\Throwable $exception) {
            $this->discardTransaction();
            throw new CacheException('Could not start Redis transaction while ' . $operation . ': ' . $exception->getMessage(), 1753614732, $exception);
        }

        if (!$result instanceof \Redis) {
            $this->discardTransaction();
            throw new CacheException('Could not start Redis transaction while ' . $operation . '.', 1753614733);
        }
    }

    /**
     * Loads data from the cache.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     * @return bool|string The cache entry's content as a string or false if the cache entry could not be loaded
     * @api
     */
    public function get(string $entryIdentifier): string|bool
    {
        $value = $this->readValidatedEntry($entryIdentifier, true);
        return $value === false ? false : $this->uncompress((string)$value);
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier An identifier specifying the cache entry
     * @return boolean true if such an entry exists, false if not
     * @api
     */
    public function has(string $entryIdentifier): bool
    {
        return (bool)$this->readValidatedEntry($entryIdentifier, false);
    }

    /**
     * Only serve an entry when its reverse tag set and every forward tag
     * membership still exist. Redis eviction treats these keys independently,
     * so an incomplete tag index must be handled as a cache miss.
     */
    private function readValidatedEntry(string $entryIdentifier, bool $returnValue): bool|string
    {
        // language=lua
        $script = "
        local value = redis.call('GET', KEYS[1])
        if value == false then
            return false
        end

        local tags = redis.call('SMEMBERS', KEYS[2])
        if #tags == 0 then
            redis.call('UNLINK', KEYS[1])
            return false
        end

        for _, tagName in ipairs(tags) do
            if tagName ~= ARGV[3] and redis.call('SISMEMBER', ARGV[1]..'tag:'..tagName, ARGV[2]) == 0 then
                for _, cleanupTagName in ipairs(tags) do
                    if cleanupTagName ~= ARGV[3] then
                        redis.call('SREM', ARGV[1]..'tag:'..cleanupTagName, ARGV[2])
                    end
                end
                redis.call('UNLINK', KEYS[1], KEYS[2])
                return false
            end
        end

        if ARGV[4] == '1' then
            return value
        end
        return true
        ";

        $result = $this->redis->eval($script, [
            $this->getPrefixedIdentifier('entry:' . $entryIdentifier),
            $this->getPrefixedIdentifier('tags:' . $entryIdentifier),
            $this->getPrefixedIdentifier(''),
            $entryIdentifier,
            self::ENTRY_TAG_SENTINEL,
            $returnValue ? '1' : '0'
        ], 2);

        if ($returnValue) {
            return is_string($result) ? $result : false;
        }
        return $result !== false;
    }

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry but if - for what reason ever -
     * old entries for the identifier still exist, they are removed as well.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     * @throws \RuntimeException
     * @return boolean true if (at least) an entry could be removed or false if no entry was found
     * @api
     */
    public function remove(string $entryIdentifier): bool
    {
        if ($this->isFrozen()) {
            throw new \RuntimeException(sprintf('Cannot remove cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1323344192);
        }
        do {
            try {
                $tagsKey = $this->getPrefixedIdentifier('tags:' . $entryIdentifier);
                if ($this->redis->watch($tagsKey) !== true) {
                    throw new CacheException('Could not watch cache tags while removing cache entry.', 1753614734);
                }
                $tags = $this->redis->sMembers($tagsKey);
                if (!is_array($tags)) {
                    throw new CacheException('Could not read cache tags while removing cache entry.', 1753614726);
                }
                $this->beginTransaction('removing cache entry');
                $this->redis->unlink($this->getPrefixedIdentifier('entry:' . $entryIdentifier));
                foreach ($tags as $tag) {
                    if ($tag === self::ENTRY_TAG_SENTINEL) {
                        continue;
                    }
                    $this->redis->sRem($this->getPrefixedIdentifier('tag:' . $tag), $entryIdentifier);
                }
                $this->redis->unlink($this->getPrefixedIdentifier('tags:' . $entryIdentifier));
                $result = $this->redis->exec();
                if ($result !== false && !is_array($result)) {
                    throw new CacheException('Redis transaction returned an invalid result while removing cache entry.', 1753614727);
                }
            } catch (\Throwable $exception) {
                $this->discardTransaction();
                if ($exception instanceof CacheException) {
                    throw $exception;
                }
                throw new CacheException('Redis transaction failed while removing cache entry: ' . $exception->getMessage(), 1753614728, $exception);
            }
        } while ($result === false);

        // Reset iterator because it will be out of sync after a removal
        $this->entryIterator = null;

        return true;
    }

    /**
     * Removes all cache entries of this cache
     *
     * The flush method will use the EVAL command to flush all entries and tags for this cache
     * in an atomic way.
     *
     * @throws \RuntimeException
     * @api
     */
    public function flush(): void
    {
        // language=lua
        $script = "
        local cursor = '0'
        repeat
            local result = redis.call('SCAN', cursor, 'MATCH', ARGV[1] .. '*')
            cursor = result[1]
            local keys = result[2]
            for _, key in ipairs(keys) do
                redis.call('UNLINK', key)
            end
        until cursor == '0'
        ";
        $this->redis->eval($script, [$this->getPrefixedIdentifier('')], 0);

        $this->frozen = null;
        $this->entryIterator = null;
    }

    /**
     * This backend does not need an externally triggered garbage collection
     *
     * @api
     */
    public function collectGarbage(): void
    {
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     * @throws \RuntimeException
     * @return integer The number of entries which have been affected by this flush
     * @api
     */
    public function flushByTag(string $tag): int
    {
        if ($this->isFrozen()) {
            throw new \RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1323344192);
        }

        // language=lua
        $script = "
        local entries = redis.call('SMEMBERS', KEYS[1])
        for k1,entryIdentifier in ipairs(entries) do
            redis.call('UNLINK', ARGV[1]..'entry:'..entryIdentifier)

            local tags = redis.call('SMEMBERS', ARGV[1]..'tags:'..entryIdentifier)
            for k2,tagName in ipairs(tags) do
                redis.call('SREM', ARGV[1]..'tag:'..tagName, entryIdentifier)
            end

            redis.call('UNLINK', ARGV[1]..'tags:'..entryIdentifier)
        end
        redis.call('UNLINK', KEYS[1])
        return #entries
        ";
        return $this->redis->eval($script, [$this->getPrefixedIdentifier('tag:' . $tag), $this->getPrefixedIdentifier('')], 1);
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tags.
     *
     * @param array<string> $tags The tag the entries must have
     * @throws \RuntimeException
     * @return integer The number of entries which have been affected by this flush
     * @api
     */
    public function flushByTags(array $tags): int
    {
        if ($this->isFrozen()) {
            throw new \RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1647642328);
        }

        // language=lua
        $script = "
        local total_entries = 0
        local num_arg = #ARGV
        for i = 1, num_arg do
            local entries = redis.call('SMEMBERS', KEYS[i])
            for k1,entryIdentifier in ipairs(entries) do
                redis.call('UNLINK', ARGV[i]..'entry:'..entryIdentifier)

                local tags = redis.call('SMEMBERS', ARGV[i]..'tags:'..entryIdentifier)
                for k2,tagName in ipairs(tags) do
                    redis.call('SREM', ARGV[i]..'tag:'..tagName, entryIdentifier)
                end

                redis.call('UNLINK', ARGV[i]..'tags:'..entryIdentifier)
            end
            redis.call('UNLINK', KEYS[i])
            total_entries = total_entries + #entries
        end
        return total_entries
        ";

        $flushedEntriesTotal = 0;

        // Flush tags in batches
        for ($i = 0, $iMax = count($tags); $i < $iMax; $i += $this->batchSize) {
            $tagList = array_slice($tags, $i, $this->batchSize);
            $keys = array_map(function ($tag) {
                return $this->getPrefixedIdentifier('tag:' . $tag);
            }, $tagList);
            $values = array_fill(0, count($keys), $this->getPrefixedIdentifier(''));

            $flushedEntries = $this->redis->eval($script, array_merge($keys, $values), count($keys));
            $flushedEntriesTotal = is_int($flushedEntries) ? $flushedEntries : 0;
        }

        return $flushedEntriesTotal;
    }

    /**
     * Finds and returns all cache entry identifiers which are tagged by the
     * specified tag.
     *
     * @param string $tag The tag to search for
     * @return string[] An array with identifiers of all matching entries. An empty array if no entries matched
     * @api
     */
    public function findIdentifiersByTag(string $tag): array
    {
        return $this->redis->sMembers($this->getPrefixedIdentifier('tag:' . $tag));
    }

    /**
     * {@inheritdoc}
     */
    public function current(): string|bool
    {
        return $this->get($this->getEntryIterator()->current());
    }

    /**
     * {@inheritdoc}
     */
    public function next(): void
    {
        $this->getEntryIterator()->next();
    }

    /**
     * {@inheritdoc}
     */
    public function key(): string|bool
    {
        $entryIdentifier = $this->getEntryIterator()->current();

        if (!$entryIdentifier || !$this->has($entryIdentifier)) {
            return false;
        }

        return $entryIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function valid(): bool
    {
        return $this->key() !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind(): void
    {
        $this->getEntryIterator()->rewind();
    }

    /**
     * Freezes this cache backend.
     *
     * All data in a frozen backend remains unchanged and methods which try to add
     * or modify data result in an exception thrown. Possible expiry times of
     * individual cache entries are ignored.
     *
     * A frozen backend can only be thawn by calling the flush() method.
     *
     * @throws \RuntimeException
     */
    public function freeze(): void
    {
        if ($this->isFrozen()) {
            throw new \RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1323344192);
        }
        do {
            try {
                $iterator = $this->getEntryIterator();
                $this->beginTransaction('freezing cache');
                foreach ($iterator as $entryIdentifier) {
                    $this->redis->persist($this->getPrefixedIdentifier('entry:' . $entryIdentifier));
                }
                /** @var array|bool $result */
                $result = $this->redis->exec();
                if ($result !== false && !is_array($result)) {
                    throw new CacheException('Redis transaction returned an invalid result while freezing cache.', 1753614729);
                }
                if ($result !== false && $this->redis->set($this->getPrefixedIdentifier('frozen'), 1) === false) {
                    throw new CacheException('Could not persist frozen cache state.', 1753614730);
                }
            } catch (\Throwable $exception) {
                $this->discardTransaction();
                if ($exception instanceof CacheException) {
                    throw $exception;
                }
                throw new CacheException('Redis transaction failed while freezing cache: ' . $exception->getMessage(), 1753614731, $exception);
            }
        } while ($result === false);
        $this->frozen = true;
    }

    /**
     * Tells if this backend is frozen.
     */
    public function isFrozen(): bool
    {
        if (null === $this->frozen) {
            $this->frozen = (bool)$this->redis->exists($this->getPrefixedIdentifier('frozen'));
        }

        return $this->frozen;
    }

    /**
     * Sets the hostname or the socket of the Redis server
     * @api
     */
    public function setHostname(string $hostname): void
    {
        $this->hostname = $hostname;
    }

    /**
     * Sets the port of the Redis server.
     *
     * Unused if you want to connect to a socket (i.e. hostname contains a /)
     * @api
     */
    public function setPort(int|string $port): void
    {
        $this->port = (int)$port;
    }

    /**
     * Sets the database that will be used for this backend
     * @api
     */
    public function setDatabase(int|string $database): void
    {
        $this->database = (int)$database;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function setCompressionLevel(int|string $compressionLevel): void
    {
        $this->compressionLevel = (int)$compressionLevel;
    }

    /**
     * Sets the Maximum number of items for batch operations
     *
     * @api
     */
    public function setBatchSize(int|string $batchSize): void
    {
        $this->batchSize = (int)$batchSize;
    }

    public function setRedis(?\Redis $redis = null): void
    {
        if ($redis !== null) {
            $this->redis = $redis;
        }
    }

    private function uncompress(bool|string $value): bool|string
    {
        if (empty($value)) {
            return $value;
        }
        return $this->useCompression() ? gzdecode((string) $value) : $value;
    }

    private function compress(string $value): string
    {
        return $this->useCompression() ? gzencode($value, $this->compressionLevel) : $value;
    }

    private function useCompression(): bool
    {
        return $this->compressionLevel > 0;
    }

    private function getRedisClient(): \Redis
    {
        $configuration = [
            'host' => $this->hostname,
            'readTimeout' => 10,
            'connectTimeout' => 10,
            'persistent' => $this->identifierPrefix,
            'backoff' => [
                'algorithm' => \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
                'base' => 10,
                'cap' => 999,
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


    /**
     * @throws CacheException
     */
    protected function verifyRedisVersionIsSupported(): void
    {
        // Redis client could be in multi mode, discard for checking the version
        $this->redis->discard();

        $serverInfo = (array)$this->redis->info('SERVER');
        if (!isset($serverInfo['redis_version'])) {
            throw new CacheException('Unsupported Redis version, the Redis cache backend needs at least version ' . self::MIN_REDIS_VERSION, 1438251553);
        }
        if (version_compare($serverInfo['redis_version'], self::MIN_REDIS_VERSION) < 0) {
            throw new CacheException('Redis version ' . $serverInfo['redis_version'] . ' not supported, the Redis cache backend needs at least version ' . self::MIN_REDIS_VERSION, 1438251628);
        }
    }

    /**
     * Validates that the configured redis backend is accessible and returns some details about its configuration if that's the case
     *
     * @api
     */
    public function getStatus(): Result
    {
        $result = new Result();
        try {
            $this->verifyRedisVersionIsSupported();
        } catch (CacheException $exception) {
            $result->addError(new Error($exception->getMessage(), (int)$exception->getCode(), [], 'Redis Version'));
            return $result;
        }
        $serverInfo = (array)$this->redis->info('SERVER');
        if (isset($serverInfo['redis_version'])) {
            $result->addNotice(new Notice((string)$serverInfo['redis_version'], null, [], 'Redis version'));
        }
        if (isset($serverInfo['tcp_port'])) {
            $result->addNotice(new Notice((string)$serverInfo['tcp_port'], null, [], 'TCP Port'));
        }
        if (isset($serverInfo['uptime_in_seconds'])) {
            $result->addNotice(new Notice((string)$serverInfo['uptime_in_seconds'], null, [], 'Uptime (seconds)'));
        }
        return $result;
    }

    /**
     * Create iterator over all entry keys in the cache, prefixed by its identifier
     */
    private function getEntryIterator(): \Iterator
    {
        if (!$this->entryIterator) {
            $prefix = $this->getPrefixedIdentifier('entry:');
            $prefixLength = strlen($prefix);
            $keys = $this->redis->keys($prefix . '*');
            if (is_array($keys)) {
                $entryIdentifiers = array_map(static fn (string $key) => substr($key, $prefixLength), $keys);
            } else {
                $entryIdentifiers = [];
            }
            $this->entryIterator = new \ArrayIterator($entryIdentifiers);
        }
        return $this->entryIterator;
    }
}
