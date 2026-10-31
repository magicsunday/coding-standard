## Testing policy

Tests protect behavior whose regression would be materially incorrect, unsafe,
destructive or hard to notice before release. They are not a coverage target and
they are not a mutation-score target.

Protect these with durable tests:
- security and privacy boundaries, including untrusted data reaching a path,
  archive member, URL, header, query, command or DOM sink
- confidentiality outside access checks (secrets or personal data in logs,
  exceptions, temporary files, caches, exports, URLs)
- data integrity across import, export, update and migration
- lifecycle and failure semantics: atomic writes, rollback, cleanup, idempotency,
  retries, partial failure, and ordering where the order is the protocol
- calculation and decision rules, including their boundaries
- compatibility boundaries: public API, serialized formats, extension interfaces,
  persisted configuration keys, producer and consumer contracts
- every fixed behavioral bug (some test must fail on the old behavior). A bug
  that is only a wrong label, CSS class or default is not behavioral unless a
  named consumer breaks on it.

For side effects assert the postcondition and the identity of the affected
resource, not only that a collaborator was called.

Do not add tests whose only content is:
- a getter, setter, constructor that only stores values, DTO, enum or constant,
  unless it carries a persisted key or a serialized format from the list above
- a CSS class list, a label or message text, or a default value, unless the exact
  value is a documented interface that a named consumer breaks on. Prefer testing
  the consuming behavior over the producer literal and pin only the minimum literal.
- transparent delegation that adds no argument selection, ordering, error
  translation or security context
- the text of source files instead of the behavior they produce

Order and default assertions are fine when they encode a safety property, for
example authorize before the side effect, write before rename, private unless
made public.

A test that a reviewer, human or agent, questions must show the observable
behavior or invariant it protects, a plausible faulty change it rejects, and for
a pinned literal the consumer that would break. Mutating the code or reverting
the fix and watching the test fail is the way to show it.
