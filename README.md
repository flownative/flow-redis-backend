# Redis Backend for Flow and Neos

This package copies over logic and code
from Neos 8.3/9.0 but changes how the
connection to redis will be made.
The new approach utilizes a lazy
connection with retries enabled.
Also connections are created as 
persistent connections.
This should give redis and the 
network connection opportunities to
recover if there should be short term
connection issues or a stalled redis.

### Warning
This might be better or worse for your 
infrastructure and we cannot predict what 
is better for you.
We made this for use in Flownative Beach
but probably most Kubernetes environments
will benefit from this. In the long run we
should provide these changes within Neos.Flow.

## Installation

`composer require flownative/redis-backend`

In your `Caches.yaml` replace 

`backend: 'Neos\Cache\Backend\RedisBackend'`

with

`backend: 'Flownative\RedisBackend\RedisBackend'`

All options remain the same.

## Cache tag integrity

Redis can evict an entry value, its reverse tag set, and its forward tag sets
independently. This backend validates both directions of the tag index before
returning a cache hit. If any part is missing, the entry is removed and
reported as a cache miss so the application can rebuild it instead of serving
content that can no longer be invalidated by tag.

Untagged entries receive an internal marker, which makes a missing reverse tag
set detectable for them as well. Existing tagged entries written by an older
version remain compatible. Existing untagged entries are rebuilt once.

New tag indexes inherit finite entry lifetimes instead of becoming persistent
when their Redis key does not exist yet.

Persistent connections are scoped by the Neos cache identifier. Failed write
transactions are discarded before the connection can be reused.
