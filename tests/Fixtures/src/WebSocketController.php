<?php

namespace App\SwerveTest;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Auth\AuthContextInterface;
use Spiral\Boot\DirectoriesInterface;
use Spiral\Core\ContainerScope;
use Spiral\Http\Request\InputManager;
use Spiral\Router\Annotation\Route;
use Spiral\Session\SessionScope;
use Swerve\Http\WebSocket;
use Swerve\Swerve;

/** The WebSocket routes the tests of phasync/swerve-spiral use. */
final class WebSocketController
{
    public function __construct(private readonly DirectoriesInterface $dirs)
    {
    }

    /** Text and binary messages back as they came. */
    #[Route(route: '/swerve/ws', name: 'swerve-ws', methods: ['GET'])]
    public function echo(ServerRequestInterface $request): ResponseInterface
    {
        return WebSocket::from($request, static function (WebSocket $ws) {
            foreach ($ws as $message) {
                $ws->isBinary() ? $ws->sendBinary($message) : $ws->send("echo: $message");
            }
        });
    }

    /** Server push: the callback only forwards the 'news' topic. */
    #[Route(route: '/swerve/ws/news', name: 'swerve-ws-news', methods: ['GET'])]
    public function news(ServerRequestInterface $request): ResponseInterface
    {
        $open = $this->openDir();

        return WebSocket::from($request, static function (WebSocket $ws) use ($open) {
            // One file per running callback: the count of callbacks, over every worker
            $file = \tempnam($open, 'ws');
            try {
                $news = Swerve::subscribe('news');
                $ws->send('subscribed in ' . \getmypid()); // for the tests: which worker, and when to publish
                foreach ($news as $message) {
                    $ws->send($message);
                }
            } finally {
                \unlink($file);
            }
        });
    }

    #[Route(route: '/swerve/ws/open', name: 'swerve-ws-open', methods: ['GET'])]
    public function open(): array
    {
        return ['open' => \count(\glob($this->openDir() . '/*'))];
    }

    #[Route(route: '/swerve/publish', name: 'swerve-publish', methods: ['POST'])]
    public function publish(InputManager $input): string
    {
        Swerve::publish('news', (string) $input->data('m'));

        return 'published';
    }

    /** The user and the session's data, taken from the request before WebSocket::from(). */
    #[Route(route: '/swerve/ws/me', name: 'swerve-ws-me', methods: ['GET'])]
    public function me(ServerRequestInterface $request, AuthContextInterface $auth, SessionScope $session): ResponseInterface
    {
        $user = $auth->getActor()?->name ?? 'guest';
        $mine = $session->getSection('iso')->get('mine');

        return WebSocket::from($request, static function (WebSocket $ws) use ($user, $mine) {
            foreach ($ws as $message) {
                $ws->send(\json_encode(['user' => $user, 'session' => $mine, 'message' => $message]));
            }
        });
    }

    /**
     * The wrong way: the request's services read inside the callback. They are proxies that
     * resolve in the request running at that moment, if any.
     */
    #[Route(route: '/swerve/ws/me-late', name: 'swerve-ws-me-late', methods: ['GET'])]
    public function meLate(ServerRequestInterface $request, AuthContextInterface $auth, SessionScope $session): ResponseInterface
    {
        return WebSocket::from($request, static function (WebSocket $ws) use ($auth, $session) {
            foreach ($ws as $message) {
                $ws->send(\json_encode([
                    'user'    => self::attempt(static fn () => $auth->getActor()?->name ?? 'guest'),
                    'session' => self::attempt(static fn () => $session->getSection('iso')->get('mine')),
                    'request' => self::attempt(static fn () => ContainerScope::getContainer()?->get(ServerRequestInterface::class)->getUri()->getPath()),
                ]));
            }
        });
    }

    /** What $read returns, or the class of what it throws. */
    private static function attempt(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }
    }

    private function openDir(): string
    {
        $dir = $this->dirs->get('runtime') . 'ws-open';
        \is_dir($dir) || \mkdir($dir, 0o777, true);

        return $dir;
    }
}
