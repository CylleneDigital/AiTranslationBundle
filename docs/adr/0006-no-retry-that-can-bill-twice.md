# 0006. No retry that can bill the same keys twice

- Status: accepted
- Date: 2026-10-14

## Context

Generation calls a paid API. A request that failed after the provider processed it has been
billed all the same, and re-sending it bills it again. Messenger retries a failed message by
default, and an HTTP client can retry a failed request, both without knowing what was paid for.

## Decision

- The bundle's transport retries nothing, and the handler marks every failure unrecoverable, so
  no transport a host routes the message to retries it either: the message goes to the failure
  transport when the host configured one, and is rejected otherwise.
- An HTTP request is only retried on a response meaning the provider did not process it - rate
  limited, unavailable, overloaded - never on an error or a timeout that may follow a processed
  request.
- A `Retry-After` longer than the retry ceiling is not waited for: the request fails at once.
- The keys of a failed request become errored suggestions. Sending them again is an explicit
  run, which someone or something decides.

## Consequences

- A transient failure is not healed by itself: the journal shows it, and a "retry errors" run
  re-sends its keys.
- A long wait would hold the worker and the generation lock; on an expiring lock store, the lock
  could expire meanwhile and let a second run bill the same keys.
- A host that routes the message to a transport of its own keeps that guarantee: it does not
  depend on the transport's retry strategy.
- A worker killed mid-run does not fail its message: the transport redelivers it after its
  redelivery timeout. The chunks already committed are skipped; the one in flight when the
  worker died is billed again.
- Any new retry, of a message or of a request, has to prove it cannot re-send what was billed.

## Related

- [0007. What was paid for is committed chunk by chunk](0007-paid-results-committed-chunk-by-chunk.md)
- [0008. One generation at a time per catalogue, locale and scope](0008-one-generation-at-a-time.md)
- [0010. Generation runs on the bundle's own transport](0010-own-messenger-transport.md)
