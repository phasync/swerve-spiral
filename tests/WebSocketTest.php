<?php

/*
 * Swerve\Http\WebSocket from Spiral controllers (tests/Fixtures/src/WebSocketController.php).
 */

beforeAll(function () {
    \array_map('unlink', \glob(__DIR__ . '/Fixtures/app/runtime/ws-open/*') ?: []);
    [$GLOBALS['proc'], $GLOBALS['addr'], $GLOBALS['log']] = app_start(2);
});

afterAll(function () {
    app_stop($GLOBALS['proc']);
});

/** The number of WebSocket callbacks running, over every worker, as the fixture counts them. */
function ws_open(string $addr): int
{
    return \json_decode(http($addr, 'GET', '/swerve/ws/open')['body'], true)['open'];
}

/** Wait up to $timeout seconds for the callbacks running to be $n. */
function ws_open_wait(string $addr, int $n, float $timeout = 5): int
{
    $deadline = \microtime(true) + $timeout;
    while (($open = ws_open($addr)) !== $n && \microtime(true) < $deadline) {
        \usleep(50_000);
    }

    return $open;
}

/**
 * Clients of /swerve/ws/news, at least $n, until they are on $workers workers.
 *
 * @return list<resource>
 */
function news_clients(string $addr, int $n, int $workers): array
{
    $clients = $pids = [];
    while (\count($clients) < $n || (\count($pids) < $workers && \count($clients) < 10 * $n)) {
        $s                                                = ws_connect($addr, '/swerve/ws/news');
        [, $hello]                                        = ws_read($s);
        $pids[\substr($hello, \strlen('subscribed in '))] = true;
        $clients[]                                        = $s;
    }
    expect($pids)->toHaveCount($workers);

    return $clients;
}

it('echoes text and binary messages, several in a row', function () {
    $s = ws_connect($GLOBALS['addr'], '/swerve/ws');
    for ($i = 0; $i < 5; ++$i) {
        ws_send($s, 1, "hello $i ✓");
        expect(ws_read($s))->toBe([1, "echo: hello $i ✓"]);
    }
    $binary = \random_bytes(70_000);
    ws_send($s, 2, $binary);
    expect(ws_read($s))->toBe([2, $binary]);
    ws_send($s, 8, \pack('n', 1000));
    expect(ws_read($s))->toBe([8, \pack('n', 1000)])
        ->and(ws_read($s))->toBeNull();
});

it('pushes what an ordinary route publishes to every client on every worker, in order', function () {
    $clients = news_clients($GLOBALS['addr'], 8, 2);
    // From one connection, so one worker: messages published through different workers may
    // arrive in another order (phasync/swerve#5)
    $publisher = \curl_init("http://{$GLOBALS['addr']}/swerve/publish");
    \curl_setopt_array($publisher, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_POST => true]);
    for ($i = 1; $i <= 20; ++$i) {
        \curl_setopt($publisher, \CURLOPT_POSTFIELDS, "m=news $i");
        expect(\curl_exec($publisher))->toBe('published');
    }
    foreach ($clients as $s) {
        for ($i = 1; $i <= 20; ++$i) {
            expect(ws_read($s))->toBe([1, "news $i"]);
        }
        \fclose($s);
    }
    expect(ws_open_wait($GLOBALS['addr'], 0))->toBe(0);
});

it('ends every callback when its client leaves, with or without a close frame', function () {
    $clients = news_clients($GLOBALS['addr'], 8, 2);
    expect(ws_open($GLOBALS['addr']))->toBe(\count($clients));
    foreach ($clients as $n => $s) {
        if ($n % 2) {
            ws_send($s, 8, \pack('n', 1000));
            expect(ws_read($s))->toBe([8, \pack('n', 1000)]);
        }
        \fclose($s); // the other half leaves without a word
    }
    expect(ws_open_wait($GLOBALS['addr'], 0))->toBe(0)
        ->and(log_problems($GLOBALS['log']))->toBe([]);
});

it('gives each socket its own user and session, taken from the request before WebSocket::from()', function () {
    $sockets = [];
    foreach (['ada', 'bob'] as $name) {
        $browser = [];
        http($GLOBALS['addr'], 'POST', "/swerve/login/$name", $browser);
        http($GLOBALS['addr'], 'GET', "/swerve/iso/$name-data", $browser); // session 'iso.mine'
        $sockets[$name] = ws_connect($GLOBALS['addr'], '/swerve/ws/me', $browser);
    }
    $sockets['guest'] = ws_connect($GLOBALS['addr'], '/swerve/ws/me');
    for ($i = 0; $i < 3; ++$i) {
        foreach ($sockets as $name => $s) {
            ws_send($s, 1, "hi $i");
        }
        foreach ($sockets as $name => $s) {
            $session = 'guest' === $name ? null : "$name-data";
            expect(\json_decode(ws_read($s)[1], true))->toBe(['user' => $name, 'session' => $session, 'message' => "hi $i"]);
        }
    }
    \array_map('fclose', $sockets);
});

it('answers an ordinary GET to a WebSocket route with 426', function () {
    $r = http($GLOBALS['addr'], 'GET', '/swerve/ws');
    expect($r['status'])->toBe(426)
        ->and($r['headers']['upgrade'][0])->toBe('websocket');
});

it("finds the request's session and scope gone inside the callback, and never another request's", function () {
    [$proc, $addr, $log] = app_start(1);
    $ada                 = $bob = [];
    http($addr, 'POST', '/swerve/login/ada', $ada);
    http($addr, 'POST', '/swerve/login/bob', $bob);
    http($addr, 'GET', '/swerve/iso/ada-data', $ada);
    $s = ws_connect($addr, '/swerve/ws/me-late', $ada);

    // No request running
    ws_send($s, 1, 'now');
    $alone = \json_decode(ws_read($s)[1], true);

    // Bob's request running: it waits 0.2 s in the middle, inside its scope
    $mh = \curl_multi_init();
    $h  = [];
    $ch = http_handle($addr, 'GET', '/swerve/iso/bob-data', $bob, $h);
    \curl_multi_add_handle($mh, $ch);
    $deadline = \microtime(true) + 0.1;
    do {
        \curl_multi_exec($mh, $running);
        \curl_multi_select($mh, 0.01);
    } while (\microtime(true) < $deadline);
    ws_send($s, 1, 'now');
    $during = \json_decode(ws_read($s)[1], true);
    do {
        \curl_multi_exec($mh, $running);
        \curl_multi_select($mh, 0.01);
    } while ($running > 0);
    $bobs = \json_decode(\curl_multi_getcontent($ch), true);
    \fclose($s);
    expect(app_stop($proc))->toBe(0);

    // The session is out of scope and ContainerScope has none; the auth context injected into
    // the controller stays bound to its own request
    $gone = [
        'user'    => 'ada',
        'session' => 'Spiral\Core\Exception\Container\ContainerException: Proxy is out of scope.',
        'request' => null,
    ];
    expect($alone)->toBe($gone)
        ->and($during)->toBe($gone)
        ->and($bobs)->toMatchArray(['actor' => 'bob', 'session' => 'bob-data']);
});

it('keeps serving requests promptly while 250 sockets are open on its only worker', function () {
    [$proc, $addr, $log] = app_start(1);
    $sockets             = [];
    for ($i = 0; $i < 250; ++$i) {
        if ($i % 2) {
            $sockets[] = $s = ws_connect($addr, '/swerve/ws/news');
            expect(ws_read($s)[0])->toBe(1); // subscribed
        } else {
            $sockets[] = $s = ws_connect($addr, '/swerve/ws');
            ws_send($s, 1, 'hi'); // and then quiet: its callback waits for the next message
            expect(ws_read($s))->toBe([1, 'echo: hi']);
        }
    }
    expect(ws_open($addr))->toBe(125);

    $slowest = 0;
    for ($i = 0; $i < 50; ++$i) {
        $t = \microtime(true);
        expect(http($addr, 'GET', '/swerve/json')['status'])->toBe(200);
        $slowest = \max($slowest, \microtime(true) - $t);
    }
    $options = ['body' => 'm=still here'];
    http($addr, 'POST', '/swerve/publish', $options);
    foreach ($sockets as $i => $s) {
        if ($i % 2) {
            expect(ws_read($s))->toBe([1, 'still here']);
        }
    }
    \array_map('fclose', $sockets);
    expect(app_stop($proc))->toBe(0)
        ->and($slowest)->toBeLessThan(0.2)
        ->and(log_problems($log))->toBe([]);
});

it('closes every socket with 1001 on SIGTERM, ends every callback, and exits cleanly', function () {
    \array_map('unlink', \glob(__DIR__ . '/Fixtures/app/runtime/ws-open/*') ?: []);
    [$proc, $addr, $log] = app_start(2);
    $sockets             = news_clients($addr, 8, 2);
    for ($i = 0; $i < 4; ++$i) {
        $sockets[] = $s = ws_connect($addr, '/swerve/ws');
        ws_send($s, 1, 'hi');
        expect(ws_read($s))->toBe([1, 'echo: hi']);
    }
    \proc_terminate($proc, \SIGTERM);
    foreach ($sockets as $s) {
        expect(ws_read($s))->toBe([8, \pack('n', 1001)]);
        ws_send($s, 8, \pack('n', 1001));
        \fclose($s);
    }
    expect(app_wait($proc, 10))->toBe(0)
        ->and(\glob(__DIR__ . '/Fixtures/app/runtime/ws-open/*'))->toBe([])
        ->and(log_problems($log))->toBe([]);
});
