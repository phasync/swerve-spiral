<?php

/*
 * A worker's life: a drain that lets a request in flight finish, and memory over many requests.
 */

it('finishes a slow request on SIGTERM, and logs no error', function () {
    [$proc, $addr, $log] = app_start(1);
    [$host, $port]       = \explode(':', $addr);
    $s                   = \stream_socket_client("tcp://$host:$port", timeout: 5);
    \fwrite($s, "GET /swerve/slow HTTP/1.1\r\nHost: $host\r\n\r\n");
    \usleep(300_000); // the request is in flight
    \proc_terminate($proc, \SIGTERM);
    $response = \stream_get_contents($s);
    \fclose($s);
    expect($response)->toStartWith('HTTP/1.1 200')
        ->and($response)->toEndWith('slow done');
    for ($deadline = \microtime(true) + 5; \proc_get_status($proc)['running'] && \microtime(true) < $deadline;) {
        \usleep(50_000);
    }
    expect(\proc_close($proc))->toBe(0);
    $lines = \array_filter(\explode("\n", \file_get_contents($log)), static fn ($line) => \preg_match('/error|exception|warning|fatal/i', $line));
    expect($lines)->toBe([]);
});

it('keeps memory flat over 10,000 requests', function () {
    [$proc, $addr] = app_start(1);
    $ch            = \curl_init();
    \curl_setopt_array($ch, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_URL => "http://$addr/swerve/json"]);
    $memory = static function () use ($ch, $addr) {
        \curl_setopt($ch, \CURLOPT_URL, "http://$addr/swerve/memory");
        $m = \json_decode(\curl_exec($ch), true)['memory'];
        \curl_setopt($ch, \CURLOPT_URL, "http://$addr/swerve/json");

        return $m;
    };
    for ($i = 0; $i < 1_000; ++$i) {
        \curl_exec($ch);
    }
    $before = $memory();
    for ($i = 0; $i < 10_000; ++$i) {
        \curl_exec($ch);
        expect(\curl_getinfo($ch, \CURLINFO_RESPONSE_CODE))->toBe(200);
    }
    $after = $memory();
    app_stop($proc);
    expect($after - $before)->toBeLessThan(256 * 1024);
});
