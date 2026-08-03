# Running on several servers

Everything in this bundle works on a single machine with no configuration at all. Spreading the
application over several servers or containers changes exactly **two** things, both configured
on the host side with standard Symfony settings. This page is the checklist.

## The short version

```yaml
# config/packages/framework.yaml
framework:
    cache:
        app: cache.adapter.redis        # 1. shared cache  → overrides propagate between nodes
    lock: '%env(REDIS_URL)%'            # 2. shared lock   → one generation run at a time
```

Nothing else. The rest of the bundle is either stateless or arbitrated by the database.

## 1. A shared `cache.app`, so an override reaches every node

Overrides are read through `cache.app`. With a node-local adapter (the filesystem default,
APCu…), a write on node A invalidates A's entry only: **B keeps serving the previous value
until its own entry expires** (1 h).

The translator's in-memory map has the same reach: it is dropped on every write by
`TranslatorOverrideCacheListener`, but that event fires in the process that wrote, so node B's
workers only see the change once their `cache.app` entry is gone.

| `cache.app` | What an override write does |
| --- | --- |
| node-local (filesystem, APCu) | correct on the writing node, up to 1 h late elsewhere |
| shared (Redis, Memcached…) | correct everywhere on the next read |

The same applies to the coverage report cache (`coverage_cache_ttl`), which lives in the same
pool.

Symfony's compiled catalogues (`kernel.cache_dir/translations`) are node-local too, but the
bundle never touches them: they hold the file translations, never the overrides, which are
applied on top at runtime.

## 2. A shared lock store, so a run is not billed twice

`GenerationLock` stops two concurrent runs of the same (catalogue, locale, scope) from calling
the paid provider for the same keys. It goes through `symfony/lock`, whose **default store is
local to the machine** (semaphore, else flock).

| Deployment | What to do |
| --- | --- |
| One machine, any number of workers | **nothing**: a local store arbitrates between every process of the host |
| Several machines or containers | `framework.lock` on a shared store (Redis, PDO…) |

See [Generation locks](../configuration-reference.md#generation-locks) for the TTL and its
trade-off.

## What needs nothing, and why

**Duplicate suggestions are impossible, lock or no lock.** The database holds at most one
PENDING suggestion per (locale, catalogue, key, scope): a unique index, which no race can
defeat. The lock protects the *money*; the database protects the *data*. That separation is
why an unreachable lock store lets a run proceed instead of refusing it: the worst case is a
double spend, never a corrupt review queue. Its symptom: the second of two concurrent runs fails
at its first flush, on that unique index, after paying for its first batch, and without a
journal entry, the entity manager being closed by then. Seeing it in the logs means the lock
store is not shared between the machines running the workers.

**A failed generation is never re-delivered.** The handler wraps every failure in
`UnrecoverableMessageHandlingException`, so no transport's retry strategy can re-run it,
which would re-bill the provider. The message goes to the failure transport instead. This
holds whatever the number of workers, and whatever transport the message was routed to.

**A crashed worker releases its lock.** With the local stores, the kernel releases it when the
process dies, `kill -9` included. With an expiring store, it is released after
`generation_lock_ttl`. Either way nothing is lost: suggestions are flushed batch by batch, and
a later run skips the keys already stored.

**Nothing is written to the filesystem.** Translation files are only ever read. Every write
goes to the database.

## Checking a deployment

```bash
# is cache.app shared?
bin/console debug:config framework cache

# is the lock store shared?
bin/console debug:config framework lock

# is the generation message routed, and where?
bin/console debug:messenger
```

A generation message with no transport listed is handled in-process, synchronously, by whatever
dispatched it (see [Messenger transport](../configuration-reference.md#messenger-transport)).

## See also

- [Caches](cache.md): the two layers and what invalidates each
- [Generation locks](../configuration-reference.md#generation-locks)
- [Messenger transport](../configuration-reference.md#messenger-transport)
