<?php

/*
 * The Spiral skeleton (spiral/app) with the routes of tests/Fixtures/src, on swerve with two
 * workers.
 */

beforeAll(function () {
    [$GLOBALS['proc'], $GLOBALS['addr'], $GLOBALS['log']] = app_start(2);
});

afterAll(function () {
    app_stop($GLOBALS['proc']);
});

it('serves the skeleton home page', function () {
    $r = http($GLOBALS['addr'], 'GET', '/');
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toContain('Spiral');
});

it('serves a JSON route', function () {
    $r = http($GLOBALS['addr'], 'GET', '/swerve/json');
    expect($r['status'])->toBe(200)
        ->and($r['headers']['content-type'][0])->toBe('application/json')
        ->and(\json_decode($r['body'], true))->toBe(['framework' => 'spiral', 'ok' => true]);
});

it('answers 404 for an unknown route', function () {
    expect(http($GLOBALS['addr'], 'GET', '/missing')['status'])->toBe(404);
});

it('takes a form POST with its CSRF token, and refuses one without', function () {
    $browser = [];
    $token   = http($GLOBALS['addr'], 'GET', '/swerve/form', $browser)['body'];
    expect($token)->toHaveLength(16);

    $without = ['cookies' => $browser['cookies'], 'body' => 'name=Ada'];
    expect(http($GLOBALS['addr'], 'POST', '/swerve/form', $without)['status'])->toBe(412);

    $with = ['cookies' => $browser['cookies'], 'body' => 'name=Ada&csrf-token=' . \urlencode($token)];
    $r    = http($GLOBALS['addr'], 'POST', '/swerve/form', $with);
    expect($r['status'])->toBe(200)
        ->and($r['body'])->toBe('Hello, Ada');
});

it('takes a JSON POST', function () {
    $options = ['body' => \json_encode(['a' => 1, 'b' => ['c' => 'd']]), 'headers' => ['Content-Type: application/json']];
    $r       = http($GLOBALS['addr'], 'POST', '/swerve/echo', $options);
    expect($r['status'])->toBe(200)
        ->and(\json_decode($r['body'], true))->toBe(['received' => ['a' => 1, 'b' => ['c' => 'd']]]);
});

it('takes an upload', function () {
    $file = \tempnam(\sys_get_temp_dir(), 'upload');
    \file_put_contents($file, \random_bytes(300_000));
    $options = ['body' => ['file' => new CURLFile($file, 'application/octet-stream', 'data.bin')]];
    $r       = http($GLOBALS['addr'], 'POST', '/swerve/upload', $options);
    expect($r['status'])->toBe(200)
        ->and(\json_decode($r['body'], true))->toBe(['name' => 'data.bin', 'size' => 300_000, 'md5' => \md5_file($file)]);
    \unlink($file);
});

it('keeps overlapping requests apart: request, route, input, container scope, auth, session, output', function () {
    // Ten visitors, each logged in as its own user
    $requests = [];
    for ($i = 0; $i < 10; ++$i) {
        $browser = [];
        expect(http($GLOBALS['addr'], 'POST', "/swerve/login/user$i", $browser)['body'])->toBe('logged in');
        $requests[] = ['GET', "/swerve/iso/user$i?v=user$i", $browser];
        $requests[] = ['GET', "/swerve/output/user$i", []];
    }

    $sids = [];
    foreach (http_all($GLOBALS['addr'], $requests) as $n => $r) {
        $v = 'user' . \intdiv($n, 2);
        expect($r['status'])->toBe(200);
        if ($n % 2) {
            expect($r['body'])->toBe("before-$v after-$v");
            continue;
        }
        $data = \json_decode($r['body'], true);
        expect($data)->toMatchArray([
            'arg'   => $v, 'query' => $v, 'route' => $v, 'input' => $v, 'scope' => $v,
            'actor' => $v, 'session' => $v, 'before' => null,
        ]);
        $sids[] = $data['sid'];
    }
    expect(\array_unique($sids))->toHaveCount(10);

    // A visitor without a session cookie, after them, gets a session of its own
    $data = \json_decode(http($GLOBALS['addr'], 'GET', '/swerve/iso/anon?v=anon')['body'], true);
    expect($data)->toMatchArray(['actor' => null, 'session' => 'anon', 'before' => null])
        ->and($sids)->not->toContain($data['sid']);
});

it('counts in the session across workers', function () {
    $browser = [];
    $pids    = [];
    for ($n = 1; $n <= 20; ++$n) {
        $data = \json_decode(http($GLOBALS['addr'], 'GET', '/swerve/counter', $browser)['body'], true);
        expect($data['n'])->toBe($n);
        $pids[$data['pid']] = true;
    }
    expect($pids)->toHaveCount(2);
});

it('keeps sessions working after output from outside a controller', function () {
    for ($i = 0; $i < 4; ++$i) {
        expect(http($GLOBALS['addr'], 'GET', '/swerve/stray-output')['body'])->toBe('ok');
    }
    $browser = [];
    for ($n = 1; $n <= 4; ++$n) {
        $r = http($GLOBALS['addr'], 'GET', '/swerve/counter', $browser);
        expect($r['status'])->toBe(200)
            ->and(\json_decode($r['body'], true)['n'])->toBe($n);
    }
});

it('shows a flash message once', function () {
    $browser = [];
    expect(http($GLOBALS['addr'], 'POST', '/swerve/flash', $browser)['body'])->toBe('ok')
        ->and(http($GLOBALS['addr'], 'GET', '/swerve/flash', $browser)['body'])->toBe('Saved')
        ->and(http($GLOBALS['addr'], 'GET', '/swerve/flash', $browser)['body'])->toBe('');
});

it('logs in and out', function () {
    $browser = [];
    expect(http($GLOBALS['addr'], 'GET', '/swerve/me', $browser)['body'])->toBe('guest')
        ->and(http($GLOBALS['addr'], 'POST', '/swerve/login/ada', $browser)['body'])->toBe('logged in')
        ->and(http($GLOBALS['addr'], 'GET', '/swerve/me', $browser)['body'])->toBe('ada')
        ->and(http($GLOBALS['addr'], 'GET', '/swerve/me', $browser)['body'])->toBe('ada')
        ->and(http($GLOBALS['addr'], 'POST', '/swerve/logout', $browser)['body'])->toBe('logged out')
        ->and(http($GLOBALS['addr'], 'GET', '/swerve/me', $browser)['body'])->toBe('guest');
});

it('streams a controller that yields its body', function () {
    [$host, $port] = \explode(':', $GLOBALS['addr']);
    $s             = \stream_socket_client("tcp://$host:$port", timeout: 5);
    \fwrite($s, "GET /swerve/stream HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
    $received = '';
    while (!\str_contains($received, 'first ')) {
        $received .= \fread($s, 8192);
    }
    $firstArrived = \microtime(true);
    $received .= \stream_get_contents($s);
    \preg_match('/last ([\d.]+)/', $received, $m);
    // The first chunk arrived before the last was produced, a second later
    expect((float) $m[1] - $firstArrived)->toBeGreaterThan(0.5);
});

it("answers a warning with Spiral's error page: its error handler turns it into an ErrorException", function () {
    $r = http($GLOBALS['addr'], 'GET', '/swerve/warning');
    expect($r['status'])->toBe(500)
        ->and($r['body'])->toContain('ErrorException');
});
