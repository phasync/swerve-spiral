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
composer config minimum-stability alpha   # while swerve is in alpha
composer config prefer-stable true         # everything else stays stable
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

## WebSockets

A controller action returns `Swerve\Http\WebSocket::from()`: a `101` and a callback that runs
on the connection, or a `426` for an ordinary request to the same route.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Router\Annotation\Route;
use Swerve\Http\WebSocket;

final class EchoController
{
    #[Route(route: '/echo', name: 'echo', methods: ['GET'])]
    public function echo(ServerRequestInterface $request): ResponseInterface
    {
        return WebSocket::from($request, static function (WebSocket $ws) {
            foreach ($ws as $message) {                 // ends when the connection closes
                $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
            }
        });
    }
}
```

**Server push.** `Swerve::subscribe()` receives what any worker publishes, so an ordinary action
reaches the sockets of every worker:

```php
use Swerve\Swerve;

#[Route(route: '/news', name: 'news', methods: ['GET'])]
public function news(ServerRequestInterface $request): ResponseInterface
{
    return WebSocket::from($request, static function (WebSocket $ws) {
        foreach (Swerve::subscribe('news') as $message) {
            $ws->send($message);
        }
    });
}

#[Route(route: '/news', name: 'news-post', methods: ['POST'])]
public function post(InputManager $input): string
{
    Swerve::publish('news', (string) $input->data('text'));

    return 'sent';
}
```

The callback ends when its client leaves, with or without a close frame, and a shutdown or
reload closes every socket with `1001`.

**The user.** The callback runs after the request has ended, outside its scope. Take what it
needs in the action:

```php
#[Route(route: '/chat', name: 'chat', methods: ['GET'])]
public function chat(ServerRequestInterface $request, AuthContextInterface $auth, SessionScope $session): ResponseInterface
{
    $user = $auth->getActor() ?? throw new ForbiddenException();
    $room = $session->getSection('chat')->get('room', 'lobby');

    return WebSocket::from($request, static function (WebSocket $ws) use ($user, $room) {
        // $user and $room, not $auth or $session
    });
}
```

Inside the callback the session is out of scope (`ContainerException: Proxy is out of scope`),
and `ContainerScope::getContainer()` is null, also while another request runs in the worker:
Spiral never hands the callback another request's scope. Leave the ORM alone there too.

Open sockets don't hold the worker: the tests keep 250 on one worker, half of them forwarding a
subscription, while it answers ordinary requests at once. Two caveats of swerve 0.1.0-alpha15:
messages published through different workers may reach subscribers in another order than they
were published ([phasync/swerve#5](https://github.com/phasync/swerve/issues/5)), and a client
that resets its connection logs an error, harmlessly
([phasync/swerve#6](https://github.com/phasync/swerve/issues/6)).

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
- **Concurrency:** one request at a time per worker (`phasync\Util\Synchronized`). Overlapping
  requests would share Spiral's output buffers around every controller and view (two requests get
  each other's output), PHP's native session (a second `session_start()` throws
  `MultipleSessionException`), the ORM heap that the finalizers clean after each request, and the
  current request of the `http` scope. Its container scopes keep apart. Tests and details:
  [docs/concurrency.md](docs/concurrency.md). Static files, streamed bodies, Server-Sent Events
  and WebSockets are served alongside, since they run after `handle()` returned.
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
  Take the user and session data it needs in the controller, and leave the ORM and the session
  alone there (see [WebSockets](#websockets)).
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
