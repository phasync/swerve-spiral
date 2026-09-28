<?php

namespace App\SwerveTest;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Spiral\Auth\AuthContextInterface;
use Spiral\Auth\TokenStorageInterface;
use Spiral\Core\ContainerScope;
use Spiral\Csrf\Middleware\CsrfMiddleware;
use Spiral\Http\Request\InputManager;
use Spiral\Router\Annotation\Route;
use Spiral\Router\Router;
use Spiral\Session\SessionScope;

/** The routes the tests of phasync/swerve-spiral use. */
final class TestController
{
    /** Let other requests run: sleep() waits as a coroutine with phasync-ext. */
    private static function nap(float $seconds): void
    {
        \extension_loaded('phasync') ? \usleep((int) ($seconds * 1e6)) : \phasync::sleep($seconds);
    }

    #[Route(route: '/swerve/json', name: 'swerve-json', methods: ['GET'])]
    public function json(): array
    {
        return ['framework' => 'spiral', 'ok' => true];
    }

    /** The token a form would carry. */
    /** A wait of ?ms= (default 10) in usleep(), as a database query waits: it blocks the worker without phasync-ext. */
    #[Route(route: '/swerve/usleep', name: 'swerve-usleep', methods: ['GET'])]
    public function usleep(ServerRequestInterface $request): array
    {
        $ms = (int) ($request->getQueryParams()['ms'] ?? 10);
        \usleep(1000 * $ms);

        return ['waited' => $ms];
    }

    #[Route(route: '/swerve/form', name: 'swerve-form', methods: ['GET'])]
    public function form(ServerRequestInterface $request): string
    {
        return $request->getAttribute(CsrfMiddleware::ATTRIBUTE);
    }

    #[Route(route: '/swerve/form', name: 'swerve-form-post', methods: ['POST'], group: 'csrf')]
    public function formPost(InputManager $input): string
    {
        return 'Hello, ' . $input->data('name');
    }

    #[Route(route: '/swerve/echo', name: 'swerve-echo', methods: ['POST'])]
    public function echo(ServerRequestInterface $request): array
    {
        return ['received' => $request->getParsedBody()];
    }

    #[Route(route: '/swerve/upload', name: 'swerve-upload', methods: ['POST'])]
    public function upload(ServerRequestInterface $request): array
    {
        /** @var UploadedFileInterface $file */
        $file = $request->getUploadedFiles()['file'];

        return ['name' => $file->getClientFilename(), 'size' => $file->getSize(), 'md5' => \md5((string) $file->getStream())];
    }

    /**
     * Stores $v in the request-scoped places, waits while other requests run, and reads back.
     */
    #[Route(route: '/swerve/iso/<v>', name: 'swerve-iso', methods: ['GET'])]
    public function isolation(string $v, ServerRequestInterface $request, InputManager $input, SessionScope $session, AuthContextInterface $auth): array
    {
        $section = $session->getSection('iso');
        $before  = $section->get('mine');
        $section->set('mine', $v);
        self::nap(0.2);

        return [
            'arg'     => $v,
            'query'   => $request->getQueryParams()['v'] ?? null,
            'route'   => $request->getAttribute(Router::ROUTE_MATCHES)['v'] ?? null,
            'input'   => $input->query('v'),
            'scope'   => ContainerScope::getContainer()->get(ServerRequestInterface::class)->getQueryParams()['v'] ?? null,
            'actor'   => $auth->getActor()?->name,
            'session' => $section->get('mine'),
            'before'  => $before,
            'sid'     => $session->getID(),
        ];
    }

    /** What a controller echoes is its response, as the output buffer Spiral opens around it has it. */
    #[Route(route: '/swerve/output/<v>', name: 'swerve-output', methods: ['GET'])]
    public function output(string $v): void
    {
        echo "before-$v ";
        self::nap(0.2);
        echo "after-$v";
    }

    #[Route(route: '/swerve/counter', name: 'swerve-counter', methods: ['GET'])]
    public function counter(SessionScope $session): array
    {
        $section = $session->getSection('counter');
        $section->set('n', $section->get('n', 0) + 1);

        return ['n' => $section->get('n'), 'pid' => \getmypid()];
    }

    #[Route(route: '/swerve/flash', name: 'swerve-flash-set', methods: ['POST'])]
    public function flashSet(SessionScope $session): string
    {
        $session->getSection('flash')->set('message', 'Saved');

        return 'ok';
    }

    #[Route(route: '/swerve/flash', name: 'swerve-flash', methods: ['GET'])]
    public function flash(SessionScope $session): string
    {
        return (string) $session->getSection('flash')->pull('message', '');
    }

    #[Route(route: '/swerve/login/<name>', name: 'swerve-login', methods: ['POST'])]
    public function login(string $name, AuthContextInterface $auth, TokenStorageInterface $tokens): string
    {
        $auth->start($tokens->create(['name' => $name]));

        return 'logged in';
    }

    #[Route(route: '/swerve/me', name: 'swerve-me', methods: ['GET'])]
    public function me(AuthContextInterface $auth): string
    {
        return $auth->getActor()?->name ?? 'guest';
    }

    #[Route(route: '/swerve/logout', name: 'swerve-logout', methods: ['POST'])]
    public function logout(AuthContextInterface $auth): string
    {
        $auth->close();

        return 'logged out';
    }

    /** Spiral's streamed response: a controller that yields its body. */
    #[Route(route: '/swerve/stream', name: 'swerve-stream', methods: ['GET'])]
    public function stream(): \Generator
    {
        yield 'first ' . \microtime(true) . "\n";
        self::nap(1);
        yield 'last ' . \microtime(true) . "\n";
    }

    /** Output from outside the controller's output buffer: the body is produced after it returned. */
    #[Route(route: '/swerve/stray-output', name: 'swerve-stray-output', methods: ['GET'])]
    public function strayOutput(): \Generator
    {
        echo "stray output\n";
        yield 'ok';
    }

    #[Route(route: '/swerve/slow', name: 'swerve-slow', methods: ['GET'])]
    public function slow(): string
    {
        self::nap(1);

        return 'slow done';
    }

    #[Route(route: '/swerve/memory', name: 'swerve-memory', methods: ['GET'])]
    public function memory(): array
    {
        \gc_collect_cycles();

        return ['memory' => \memory_get_usage(), 'pid' => \getmypid()];
    }
}
