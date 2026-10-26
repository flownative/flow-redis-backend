# Redis Backend for Flow and Neos

This package copies the Redis cache backend of Neos.Cache 8.3/9.x
and changes how it talks to Redis:

- The connection uses connect and read timeouts of 10 seconds.
- Failed commands are retried with a backoff (decorrelated jitter,
  10 to 999 ms).
- Connections are not persistent.
- Keys are removed with `UNLINK` instead of `DEL`, so Redis frees
  the memory in the background and does not block.

This should give Redis and the network connection a chance to
recover from short connection issues or a stalled Redis.

### Warning
This might be better or worse for your infrastructure and we
cannot predict what is better for you. We made this for use in
Flownative Beach, but most Kubernetes environments will probably
benefit from it. In the long run, these changes should go into
Neos.Cache.

## Installation

`composer require flownative/redis-backend`

In your `Caches.yaml` replace

`backend: 'Neos\Cache\Backend\RedisBackend'`

with

`backend: 'Flownative\RedisBackend\RedisBackend'`

All options remain the same, including `username` and `password`
for authentication.
