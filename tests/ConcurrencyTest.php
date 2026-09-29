<?php

/*
 * Requests that overlap on one worker: each stores something of its own, waits (200 ms, while
 * the others run), and reads it back. Each case is state that one worker's requests would share
 * without the adapter's lock (docs/concurrency.md).
 */

beforeAll(function () {
    [$GLOBALS['proc'], $GLOBALS['addr'], $GLOBALS['log']] = app_start(1);
});

afterAll(function () {
    app_stop($GLOBALS['proc']);
});

/** Four requests at once, made by $path(user0...user3), with the options of $options($i). */
function overlapping(Closure $path, ?Closure $options = null): array
{
    $requests = [];
    for ($i = 0; $i < 4; ++$i) {
        $requests[] = ['GET', $path("user$i"), $options ? $options($i) : []];
    }

    return http_all($GLOBALS['addr'], $requests);
}

it('keeps the request apart: route, input, container scope, and the http scope\'s current request after a wait', function () {
    foreach (overlapping(static fn ($v) => "/swerve/iso/$v?v=$v&session=0&wait=1") as $i => $r) {
        expect(\json_decode($r['body'], true))->toMatchArray(['arg' => "user$i", 'query' => "user$i", 'route' => "user$i", 'input' => "user$i", 'scope' => "user$i"])
            ->and($r['headers']['x-current-v'])->toBe(["user$i"]);
    }
});

it('keeps the session and the logged-in user apart', function () {
    $browsers = [];
    for ($i = 0; $i < 4; ++$i) {
        $browsers[$i] = [];
        expect(http($GLOBALS['addr'], 'POST', "/swerve/login/user$i", $browsers[$i])['body'])->toBe('logged in');
    }
    foreach (overlapping(static fn ($v) => "/swerve/iso/$v?v=$v", static fn ($i) => $browsers[$i]) as $i => $r) {
        expect($r['status'])->toBe(200)
            ->and(\json_decode($r['body'], true))->toMatchArray(['actor' => "user$i", 'session' => "user$i", 'before' => null]);
    }
    // What each wrote after its wait is in its own session
    foreach ($browsers as $i => $browser) {
        expect(\json_decode(http($GLOBALS['addr'], 'GET', "/swerve/iso/user$i?v=user$i", $browser)['body'], true)['before'])->toBe("user$i");
    }
});

it('keeps what controllers echo apart', function () {
    foreach (overlapping(static fn ($v) => "/swerve/output/$v") as $i => $r) {
        expect($r['body'])->toBe("before-user$i after-user$i");
    }
});

it('keeps the ORM heap apart, and one request\'s finalizers off another\'s', function () {
    foreach (overlapping(static fn ($v) => "/swerve/orm/$v") as $i => $r) {
        expect(\json_decode($r['body'], true))->toBe(['mine' => true, 'heap' => ["user$i"]]);
    }
});

it('serves one request at a time per worker, with or without phasync-ext', function () {
    $t = \microtime(true);
    overlapping(static fn ($v) => "/swerve/iso/$v?v=$v&session=0");
    expect(\microtime(true) - $t)->toBeGreaterThan(0.8)
        ->and(log_problems($GLOBALS['log']))->toBe([]);
});
