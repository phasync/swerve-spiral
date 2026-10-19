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

```bash
vendor/bin/swerve --adapter=spiral --http=0.0.0.0:8080 --public=public
```

A worker serves one request at a time: a request waiting on a database or API holds its worker
until it returns. Each request still gets its own Spiral `ContainerScope`, swapped by
`phasync::onScopeChange()`, so code that runs after another request's `handle()` has returned (a
WebSocket callback, a Server-Sent Events body) never sees that request's container. Capacity
scales with `--workers`, as with RoadRunner. Details: [docs/concurrency.md](docs/concurrency.md).

The framework running correctly on swerve does not mean your application does: state your own
code keeps in statics, globals or shared services, and connections shared between requests, need
the same care. See [Making your application safe for concurrent requests](https://phasync.github.io/guides/concurrent-requests-in-your-application/).

That's the whole setup. `--adapter=spiral` picks this adapter over swerve-psr15's, which comes
with it (or put `"extra": {"swerve": {"adapter": "spiral"}}` in your `composer.json`). `app.php`
stays as it is, so the same application still runs under RoadRunner. A kernel or exception
handler other than the skeleton's `App\Application\Kernel` and `App\Application\Exception\Handler`
goes in as `kernel:` and `exceptionHandler:`, in a `swerve.php` run with `--adapter=swerve`:

```php
<?php // swerve.php, next to composer.json

return Swerve\Psr15\Bridge::wrap(new Swerve\Spiral\Handler(__DIR__, kernel: App\MyKernel::class));
```

The handler is a PSR-15 request handler, served by [swerve-psr15](https://github.com/phasync/swerve-psr15)'s
`Bridge`.

## WebSockets

A controller action returns swerve-psr15's `WebSocketResponse::from()`: a `101` and a callback
that gets swerve's `WebSocket` and runs on the connection, or a `426` for an ordinary request to
the same route.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Router\Annotation\Route;
use Swerve\Psr15\WebSocketResponse;
use Swerve\WebSocket;

final class EchoController
{
    #[Route(route: '/echo', name: 'echo', methods: ['GET'])]
    public function echo(ServerRequestInterface $request): ResponseInterface
    {
        return WebSocketResponse::from($request, static function (WebSocket $ws) {
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
    return WebSocketResponse::from($request, static function (WebSocket $ws) {
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

    return WebSocketResponse::from($request, static function (WebSocket $ws) use ($user, $room) {
        // $user and $room, not $auth or $session
    });
}
```

Inside the callback the session is out of scope (`ContainerException: Proxy is out of scope`),
and `ContainerScope::getContainer()` is null, also while another request runs in the worker:
Spiral never hands the callback another request's scope. Leave the ORM alone there too.

Open sockets don't hold the worker: the tests keep 250 on one worker, half of them forwarding a
subscription, while it answers ordinary requests at once. Messages from one publisher reach every
subscriber in the order it published them; there is no order between different workers'
publishers unless they publish through a `Swerve\OrderedChannel`.

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
  current request of the `http` scope. `ContainerScope`'s current container is kept apart
  regardless of the lock: a `phasync::onScopeChange()` hook swaps it per request, so code that
  runs after `handle()` has returned (a WebSocket callback, a Server-Sent Events body) never sees
  another request's container. Tests and details: [docs/concurrency.md](docs/concurrency.md).
  Static files, streamed bodies, Server-Sent Events and WebSockets are served alongside, since
  they run after `handle()` returned.
- **Sessions:** Spiral's own, with the handler in `app/config/session.php`. Spiral gives every request
  its session id, a new one for a visitor without a cookie, so none carries over. What is echoed
  outside a controller goes to swerve's terminal past PHP's output layer, which would otherwise
  count the headers as sent and make `session_set_save_handler()` fail for the worker's life.

## Before you deploy

- `exit` and `dd()` end the worker, and the request it is serving with it.
- **A stop ends the requests in flight.** On a shutdown or reload swerve stops a worker at once:
  a request still waiting then ends, answered `503` by swerve when nothing was sent yet (Spiral's
  error middleware still reports it); clients retry.
- A worker serves one request at a time, as a RoadRunner worker does: give swerve as many
  workers as RoadRunner's `num_workers`. A request waiting for an API holds its worker, with or
  without ext-phasync.
- ext-phasync makes the file session handler's reads and writes wait as coroutines, with nothing
  else to run meanwhile, since a worker serves one request at a time: load it for WebSockets,
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

## Compatibility

| Spiral | PHP | ext-phasync |
|---|---|---|
| 3.15 and later, tested with 3.17 (`spiral/app` 3.9 on PHP 8.4+, 3.8 below) | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
