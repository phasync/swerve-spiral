# swerve for Spiral

[![CI](https://github.com/phasync/swerve-spiral/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-spiral/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-spiral)](https://packagist.org/packages/phasync/swerve-spiral)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-spiral/php)](https://packagist.org/packages/phasync/swerve-spiral)
![License](https://img.shields.io/github/license/phasync/swerve-spiral)

**Your Spiral application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a Spiral application unchanged.

```bash
composer require phasync/swerve-spiral
```

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/vendor/autoload.php';

return new Swerve\Spiral\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

That's the whole setup. `app.php` stays as it is, so the same application still runs under
RoadRunner. A kernel or exception handler other than the skeleton's `App\Application\Kernel` and
`App\Application\Exception\Handler` goes in as `kernel:` and `exceptionHandler:`.

## What changes

The Spiral skeleton, 4 workers, opcache on, production settings, `wrk -t4 -c64 -d10s`:

| Requests per second | PHP-FPM | RoadRunner | swerve | swerve + phasync-ext |
|---|---:|---:|---:|---:|
| Home page | 241 | 2,402 | 2,283 | 2,292 |
| JSON route | 242 | 2,525 | 2,389 | 2,394 |
| Page with session | 236 | 2,114 | 2,050 | 1,749 |

Under PHP-FPM Spiral boots for every request. swerve boots it once, as RoadRunner does, and
serves 9 to 10 times as much, within 6% of RoadRunner; what a request costs now is Spiral's own
request handling, about 85% of a worker's time. [Method and raw results](benchmarks/).

## How it runs

- **Once per worker:** the kernel boots as the skeleton's `app.php` boots it (the same container
  options, kernel and exception handler), without `serve()`. Spiral's `http` scope then opens for
  the worker's life, as Spiral's RoadRunner dispatcher keeps it open while it serves.
- **Per request:** what the RoadRunner dispatcher does. `Http::handle()` in that scope gets
  swerve's PSR-7 request and returns its PSR-7 response, with no bridge between them, so a
  controller that yields its body streams. An exception that gets through the pipeline is
  reported to Spiral's exception handler and answered with a 500. Then the finalizers run: the
  Cycle ORM heap and entity manager are cleaned and the loggers reset.
- **Concurrency:** one request at a time per worker (`phasync\Util\Synchronized`). Spiral keeps
  request state in the process: an output buffer around every controller and view (two
  overlapping requests get each other's output), PHP's native session (a second
  `session_start()` throws `MultipleSessionException`), and the ORM heap and database
  connections as singletons that the finalizers clear after each request. Its container scopes
  are fiber-safe, but that is not enough. Static files, streamed bodies, Server-Sent Events and
  WebSockets are served alongside, since they run after `handle()` returned.
- **Sessions:** Spiral's own, with the handler in `app/config/session.php`. Spiral gives every request
  its session id, a new one for a visitor without a cookie, so none carries over. What is echoed
  outside a controller goes to swerve's terminal past PHP's output layer, which would otherwise
  count the headers as sent and make `session_set_save_handler()` fail for the worker's life.

## Before you deploy

- `exit` and `dd()` end the worker, and the request it is serving with it.
- A worker serves one request at a time, as a RoadRunner worker does: give swerve as many
  workers as RoadRunner's `num_workers`. A request waiting for an API holds its worker, with or
  without phasync-ext.
- phasync-ext makes the file session handler's reads and writes wait as coroutines, with nothing
  else to run meanwhile: the session page was 15% slower with it. Load it for WebSockets,
  Server-Sent Events or thousands of connections, not for Spiral's pages.
- Code that runs after the controller returned (a generator body, a Server-Sent Events producer, a
  WebSocket callback) runs outside the request's scope while the worker serves other requests.
  Take what it needs from the container in the controller, and leave the ORM and the session
  alone there.
- Pages that start a session don't get the no-cache headers PHP-FPM adds (`session.cache_limiter`),
  as under RoadRunner. Add them in a middleware if a cache sits in front.
- Spiral's file session handler has no lock: one session used by requests on several workers at
  once fails now and then with a 500, as under RoadRunner. Use a handler that locks if clients
  send many requests at once.
- Each worker's shutdown writes `stream_socket_accept(): Accept failed` to standard error and to
  the application's error log: Spiral's shutdown handler reports an error swerve silenced
  ([phasync/swerve#2](https://github.com/phasync/swerve/issues/2)). It is harmless.

## Compatibility

| Spiral | PHP | phasync-ext |
|---|---|---|
| 3.15 and later, tested with 3.17 (`spiral/app` 3.9 on PHP 8.4+, 3.8 below) | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
