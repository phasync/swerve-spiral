# Concurrency

The adapter serves one request at a time per worker (`phasync\Util\Synchronized` around
`Http::handle()`, `src/Handler.php`). A request waiting for a database or an API holds its
worker, with or without ext-phasync: give swerve as many workers as RoadRunner's `num_workers`.
Static files, streamed bodies, Server-Sent Events and WebSockets are served alongside, since they
run after `handle()` returned.

Paths below are in `vendor/spiral/framework/src/` unless they name another package.
`tests/ConcurrencyTest.php` sends four requests at once to one worker; each stores a value of its
own, waits, and reads it back, to check that none of the state below is shared between them.

## Why a request holds its worker

**Output buffers.** `CoreHandler::handle()` opens a buffer around every controller
(`Router/src/CoreHandler.php`) and on the way out collects every buffer above its own level; the
view engines do the same. Two requests sharing that stack would mix one controller's or view's
`echo` into another's response, and the adapter's own buffer (which turns what a controller
echoes into swerve's output, past PHP's output layer) would go with them, making
`session_set_save_handler()` fail for the rest of the worker's life.

**The native session.** `SessionFactory::initSession()` refuses a second active session
(`Session/src/SessionFactory.php`), and `Session` keeps the data in `$_SESSION` after
`session_start()` (`Session/src/Session.php`). A second concurrent request would throw
`MultipleSessionException`.

**The ORM heap and entity manager.** Cycle's `ORMInterface` and `EntityManagerInterface` are
singletons of the application (`cycle-bridge/src/Bootloader/CycleOrmBootloader.php`), and the
finalizer the adapter runs after each request cleans both. An overlapping request would find its
entities cleaned by another request's finalizer, or share its heap with it.

**The `http` scope's current request.** `CurrentRequest` is a singleton of the `http` scope
(`Framework/Bootloader/Http/HttpBootloader.php`), which the adapter keeps open for the worker's
life, as Spiral's RoadRunner dispatcher does; `ServerRequestInterface` there, and with it
`SessionScope` and the other scoped services used from middleware, resolve through it, and every
pipeline step sets it (`Http/src/Pipeline.php`). A middleware reading the request after a wait
would get whichever request set it last.

A lock around all of `Http::handle()` is what keeps them apart: the buffer opens before the
controller and closes after it, the session is active from its first use until the middleware
commits it, and the heap holds the request's entities until its finalizer runs.

## Container scope, kept apart without the lock

`ContainerScope`'s current container (`Core/src/ContainerScope.php`) is a static. The adapter
swaps it itself, with a `phasync::onScopeChange()` hook keyed by each request's `Scope`
(`src/Handler.php`), so the container a request's coroutines see is always its own - including a
WebSocket callback or a Server-Sent Events body still running after its own request returned,
while another request is being handled on the same worker. This holds regardless of the lock
above; it is not something the lock is needed for.

Route parameters, input and the logged-in user live on the PSR-7 request
(`AuthHttp/src/Middleware/AuthMiddleware.php`) or in the per-request `http-request` scope
(`Router/src/CoreHandler.php`), both already request-local, not process-wide.

## What a user can do

Capacity scales with `--workers`: each one runs the application's own copy and serves one request
at a time, so N workers serve N requests at once, as RoadRunner does with the same worker count.
A request that waits on a database or an API holds its worker for that wait, with or without
ext-phasync loaded. Open WebSocket and Server-Sent Events connections do not hold a worker; a
generator body or a WebSocket callback runs after `handle()` returns, alongside the requests that
follow.

## What could remove the lock

The simple fix for state like the container scope above is to swap it per request with
`phasync::onScopeChange()`, as the adapter already does for `ContainerScope`. The same technique
could be used for the rest of this page's state - a buffer, `$_SESSION`, the ORM heap, the
current request - if each were replaced by a reference the application swaps on every scope
change. It has real limits: it is specific to phasync, not to fiber-interop/ext-scope or the
Scope RFC it follows; the hook runs on every switch between requests on a worker, so only a few
references should be swapped in it; it only covers state the application swaps itself, so a
library's own statics still leak between requests; and an application built on it runs on phasync
only, not on another fiber-based server.

The proper fix is for state to be keyed on `\Scope::getCurrent()` directly (the
[Scope RFC](https://wiki.php.net/rfc/scope)), rather than held in one static the application
swaps by hand. `fiber-interop/ext-scope`, bundled in ext-phasync, or its userland polyfill
`fiber-interop/scope`, give every fiber and generator the scope of the operation it belongs to;
with `ext-scope` loaded, `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, the default
timezone and the default `intl` locale already follow the scope this way, with no code swapping
them. Spiral's own singletons - the session, the ORM heap, the current request - would need to
become state looked up by the current `Scope` instead of one instance shared by a container, to
be read concurrently by requests that overlap on a worker.
