<?php

namespace Swerve\Spiral;

use phasync\Util\Synchronized;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Spiral\Boot\AbstractKernel;
use Spiral\Boot\Environment\AppEnvironment;
use Spiral\Boot\FinalizerInterface;
use Spiral\Core\Container;
use Spiral\Core\Options;
use Spiral\Core\Scope;
use Spiral\Exceptions\ExceptionHandlerInterface;
use Spiral\Exceptions\Verbosity;
use Spiral\Http\Http;

/**
 * A Spiral application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Spiral\Handler(__DIR__);
 *
 * Once per worker, the kernel boots as the skeleton's app.php boots it, without serve(), and
 * Spiral's 'http' scope opens for the worker's life. Per request, as Spiral's RoadRunner
 * dispatcher serves one: Http::handle() in that scope, a 500 reported to Spiral's exception
 * handler for what escapes it, then the finalizers (the Cycle ORM heap, the loggers). One request
 * at a time per worker: Spiral keeps request state in the process (output buffers around every
 * controller and view, the native session, the ORM heap and the database connections). What runs
 * after handle() returned (a streamed body, a WebSocket callback) runs outside that turn and the
 * request's scope, beside the requests that follow.
 */
final class Handler implements RequestHandlerInterface
{
    private readonly AbstractKernel $kernel;
    private readonly Container $container;
    private readonly \Fiber $scopeHolder;
    private readonly Container $scope;
    private readonly FinalizerInterface $finalizer;
    private readonly ExceptionHandlerInterface $errors;
    private readonly ResponseFactoryInterface $responses;
    private readonly bool $production;

    /**
     * @param string                                  $root             the application's root directory, where composer.json is
     * @param class-string<AbstractKernel>            $kernel           the application's kernel, as in app.php
     * @param class-string<ExceptionHandlerInterface> $exceptionHandler its exception handler, as in app.php
     */
    public function __construct(
        string $root,
        string $kernel = 'App\Application\Kernel',
        string $exceptionHandler = 'App\Application\Exception\Handler',
    ) {
        // What is echoed outside a controller (a streamed body, a coroutine) still goes to swerve's
        // terminal, but past PHP's output layer: once that sent output, the headers count as sent
        // for the rest of the worker, and Spiral's session_set_save_handler() fails on every request
        \ob_start(static function (string $output): string {
            \fwrite(\STDOUT, $output);

            return '';
        }, 1);

        // As the skeleton's app.php
        \mb_internal_encoding('UTF-8');
        $options                           = new Options();
        $options->allowSingletonsRebinding = false;
        $options->validateArguments        = false;
        $this->container                   = new Container(options: $options);
        // Kept: the kernel runs the finalizers for termination when it is destroyed
        $this->kernel = $kernel::create(
            directories: ['root' => $root],
            exceptionHandler: $exceptionHandler,
            container: $this->container,
        )->run() ?? throw new \RuntimeException('The Spiral application did not boot; its exception handler reported why');

        // The 'http' scope, open for the worker's life as Spiral's RoadRunner dispatcher keeps it
        // while it serves: runScope() returns when its callback does, so the callback suspends a
        // fiber that is never resumed (ContainerScope passes a suspension on to the caller)
        $this->scopeHolder = new \Fiber(fn () => $this->container->runScope(
            new Scope('http', autowire: false),
            static fn (Container $scope) => \Fiber::suspend($scope),
        ));
        $this->scope = $this->scopeHolder->start();

        $this->finalizer  = $this->container->get(FinalizerInterface::class);
        $this->errors     = $this->container->get(ExceptionHandlerInterface::class);
        $this->responses  = $this->container->get(ResponseFactoryInterface::class);
        $this->production = $this->container->get(AppEnvironment::class)->isProduction();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Synchronized::run($this, fn (): ResponseInterface => $this->scope->invoke(
            function (Http $http) use ($request): ResponseInterface {
                try {
                    return $http->handle($request);
                } catch (\Throwable $e) {
                    // As Spiral's RoadRunner dispatcher answers what its pipeline let through
                    $this->errors->report($e);
                    $response = $this->responses->createResponse(500);
                    $this->production or $response->getBody()->write($this->errors->render($e, Verbosity::VERBOSE));

                    return $response;
                } finally {
                    $this->finalizer->finalize(false);
                }
            },
        ));
    }
}
