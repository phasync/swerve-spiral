# Concurrency

The adapter serves one request at a time per worker (`phasync\Util\Synchronized` around
`Http::handle()`, `src/Handler.php:95`). A request waiting for a database or an API holds its
worker, with or without phasync-ext: give swerve as many workers as RoadRunner's `num_workers`.
Static files, streamed bodies, Server-Sent Events and WebSockets are served alongside, since they
run after `handle()` returned.

Paths below are in `vendor/spiral/framework/src/` unless they name another package.
`tests/ConcurrencyTest.php` sends four requests at once to one worker; each stores a value of its
own, waits 200 ms (in `usleep()` with phasync-ext, `phasync::sleep()` without), and reads it back.
With the lock they pass. The results quoted are from the same tests with the lock removed; each
leak showed without phasync-ext and with it.

## Why

**Output buffers.** `CoreHandler::handle()` opens a buffer around every controller
(`Router/src/CoreHandler.php:98`) and on the way out collects every buffer above its own level
(`:150`); the view engines do the same. The first request's body was
`before-user0 before-user1 before-user2 before-user3 after-user0`, the others' empty. The adapter's
own buffer went with them, so output reached the terminal, PHP counted the headers as sent, and
every request with a session failed with `session_set_save_handler(): Session save handler cannot
be changed after headers have already been sent` until the worker restarted
(test: *keeps what controllers echo apart*).

**The native session.** `SessionFactory::initSession()` refuses a second active session
(`Session/src/SessionFactory.php:29`), and `Session` keeps the data in `$_SESSION` after
`session_start()` (`Session/src/Session.php:72`). Three of four requests failed with
`MultipleSessionException` (test: *keeps the session and the logged-in user apart*).

**The ORM heap and entity manager.** Cycle's `ORMInterface` and `EntityManagerInterface` are
singletons of the application (`cycle-bridge/src/Bootloader/CycleOrmBootloader.php:37`), and the
finalizer the adapter runs after each request cleans both (`:51-63`). The first request found all
four requests' entities in its heap; the other three found their own gone, cleaned by the first
request's finalizer (test: *keeps the ORM heap apart*).

**The `http` scope's current request.** `CurrentRequest` is a singleton of the `http` scope
(`Framework/Bootloader/Http/HttpBootloader.php:67`), which the adapter keeps open for the worker's
life, as Spiral's RoadRunner dispatcher does; `ServerRequestInterface` there, and with it
`SessionScope` and the other scoped services used from middleware, resolve through it (`:69-78`),
and every pipeline step sets it (`Http/src/Pipeline.php:69-72`). A middleware reading the request
after a wait got another request's: `user3`, `user0`, `user1`, `user2` for requests 0 to 3
(test: *keeps the request apart*).

## Not reasons

- **`ContainerScope`'s current container** (`Core/src/ContainerScope.php:19`) is a static, but
  `runScope()` runs the scope in a fiber and swaps the static at each suspension and resume
  (`:44-63`), so each request sees its own. The tests read it after the wait in every
  configuration (without and with phasync-ext, and with `virtualize()`) and found no leak. Keeping
  it in `phasync::getContext()` instead would need a change to Spiral (the class is final, the
  property private) and would fix nothing.
- **Route parameters, input and the logged-in user**: they live on the PSR-7 request
  (`AuthHttp/src/Middleware/AuthMiddleware.php:45-51`) or in the per-request `http-request` scope
  (`Router/src/CoreHandler.php:114`). No test found them leaking.

## phasync-ext's `virtualize()`

Run inside `virtualize()` (as `Swerve\Http\Virtual::run()` does), each request has its own output
buffers and native session state: the output test passes, and `MultipleSessionException` is gone.
`$_SESSION` stays one global variable, bound by the latest `session_start()`: all four requests
read back `user3`. The ORM and the current request are objects of the application, which
`virtualize()` does not separate: those two tests fail as without it.

## What else was tried

- **A pool of applications**, one per overlapping request, as swerve-symfony pools its kernels:
  the ORM and current-request tests pass; each kernel beyond the first costs 12 ms and 1.5 MB with
  the skeleton's production settings (330 ms with its development `.env`). The output and session
  tests fail as before, without `virtualize()`.
- **The `http` scope opened per request** instead of once per worker: the current-request test
  passes; the ORM test fails as before.
- **A narrower lock**: the shared state spans the request. Spiral's buffer opens before the
  controller and closes after it, the session is active from its first use until the middleware
  commits it after the controller, and the heap holds the request's entities until its
  finalizer runs. A lock covering all of them covers `Http::handle()`.
