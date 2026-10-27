# ADR 0020: Events

- Status: Accepted
- Date: 2026-10-26

## Context

The owner asked for the Laravel features VeyroPHP does not have yet, one at a time. Events come first because mail, queues and password reset build on them.

## Decisions

- **PSR-14.** `EventDispatcher` implements `EventDispatcherInterface` and `ListenerProviderInterface`; `psr/event-dispatcher` (interfaces only) becomes a dependency. Events are plain objects; there is no base class and no string event names.
- **Matching by type**: a listener for a class or interface receives subclasses and implementations, in registration order. No priorities and no wildcards: order is visible in one place (config or a provider), and string wildcards would bring back string event names.
- **Listener classes are lazy and not cached**: created through the container when their event is dispatched, as a new instance each time. Caching instances in the singleton dispatcher would keep per-request services (for example `Auth`) alive across requests in long-running processes.
- **Errors**: a listener exception stops the dispatch and reaches the caller (PSR-14). Configuration mistakes throw `EventException` at registration, not at the first dispatch, except for dependencies the container cannot build, which are only known when the listener is created.
- **Synchronous only for now.** Queued listeners will be added together with queues, so that the queue design decides how listeners are serialized.
- **Auth events**: `Login`, `LoginFailed`, `Logout`. `Auth` takes an optional dispatcher, so applications without events are unchanged. `LoginFailed` carries the identifier but never the password.
- `make:event` and `make:listener` generators.

## Verified

`EventDispatcherTest` (15 tests: order, parent and interface matching, laziness and fresh instances, stoppable events, exceptions, registration errors, the service provider and its config checks), the auth events through real HTTP requests in `AuthAndRbacTest`, and the generated event and listener loaded and dispatched in `DeveloperCommandsTest`.

## Not done

- Queued listeners (with queues).
- Model events (`creating`, `saved`…): the ORM is explicit by design (ADR 0014); revisit if the owner wants them.
- An `event:list` command.
