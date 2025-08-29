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