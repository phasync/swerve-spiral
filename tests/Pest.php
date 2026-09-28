<?php

/*
 * The tests run the Spiral application in tests/Fixtures/app (made by tests/create-app.sh) on a
 * real swerve, the way users run it. SWERVE_PHP_ARGS adds PHP options, such as loading
 * phasync-ext: CI runs the suite without and with it.
 */

/**
 * Start swerve on a free port with the fixture application and wait until it answers.
 *
 * @return array{0: resource, 1: string, 2: string} the process, its address, its log file
 */
function app_start(int $workers = 2, array $env = []): array
{
    // A free port of 18900-18949, the range this suite may use on a shared machine
    for ($port = 18900; false === $socket = @\stream_socket_server("tcp://127.0.0.1:$port"); ++$port) {
        if ($port >= 18949) {
            throw new RuntimeException('No free port in 18900-18949');
        }
    }
    $addr = \stream_socket_get_name($socket, false);
    \fclose($socket);
    $log      = \tempnam(\sys_get_temp_dir(), 'swerve-log');
    $app      = __DIR__ . '/Fixtures/app';
    $php      = \trim((string) \getenv('SWERVE_PHP_ARGS'));
    $cmd      = 'exec ' . \PHP_BINARY . " $php " . \escapeshellarg("$app/vendor/bin/swerve") . " --workers=$workers --grace=2 --http=$addr --log=" . \escapeshellarg($log) . ' ' . \escapeshellarg("$app/swerve.php");
    $proc     = \proc_open($cmd, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $app, $env + \getenv());
    $deadline = \microtime(true) + 20;
    $probe    = \curl_init("http://$addr/");
    \curl_setopt_array($probe, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_TIMEOUT => 1]);
    while (false === \curl_exec($probe)) {
        if (\microtime(true) > $deadline) {
            throw new RuntimeException("swerve did not start:\n" . \file_get_contents($log));
        }
        \usleep(100_000);
    }

    return [$proc, $addr, $log];
}

/** Stop swerve as SIGTERM does (a graceful drain), and return its exit code. */
function app_stop($proc): int
{
    \proc_terminate($proc, \SIGTERM);
    $deadline = \microtime(true) + 10;
    while (\proc_get_status($proc)['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }

    return \proc_close($proc);
}

/**
 * A curl handle for one request, on a connection of its own.
 *
 * @param array{cookies?: array<string, string>, body?: string|array, headers?: list<string>} $options
 */
function http_handle(string $addr, string $method, string $path, array $options, array &$headers): CurlHandle
{
    $ch = \curl_init("http://$addr$path");
    \curl_setopt_array($ch, [
        \CURLOPT_CUSTOMREQUEST  => $method,
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_FORBID_REUSE   => true,
        \CURLOPT_TIMEOUT        => 20,
        \CURLOPT_HTTPHEADER     => $options['headers'] ?? [],
        \CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers) {
            if (\str_contains($line, ':')) {
                [$name, $value]                       = \explode(':', $line, 2);
                $headers[\strtolower(\trim($name))][] = \trim($value);
            }

            return \strlen($line);
        },
    ]);
    if ($options['cookies'] ?? []) {
        \curl_setopt($ch, \CURLOPT_COOKIE, \implode('; ', \array_map(static fn ($name, $value) => "$name=$value", \array_keys($options['cookies']), $options['cookies'])));
    }
    if (isset($options['body'])) {
        \curl_setopt($ch, \CURLOPT_POSTFIELDS, $options['body']);
    }

    return $ch;
}

/** The response as [status, headers, body], with the cookies it sets merged into $options['cookies']. */
function http_result(CurlHandle $ch, array $headers, ?string $body, array &$options): array
{
    foreach ($headers['set-cookie'] ?? [] as $cookie) {
        [$name, $value]            = \explode('=', \explode(';', $cookie, 2)[0], 2);
        $options['cookies'][$name] = $value;
    }

    return ['status' => \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
}

/** One request; the cookies it sets are kept in $options['cookies'], as a browser keeps them. */
function http(string $addr, string $method, string $path, array &$options = []): array
{
    $headers = [];
    $ch      = http_handle($addr, $method, $path, $options, $headers);
    $body    = \curl_exec($ch);

    return http_result($ch, $headers, false === $body ? null : $body, $options);
}

/**
 * Requests sent at once, each with its own options (cookie jar).
 *
 * @param list<array{0: string, 1: string, 2: array}> $requests [method, path, options]
 */
function http_all(string $addr, array &$requests): array
{
    $mh      = \curl_multi_init();
    $handles = $headers = [];
    foreach ($requests as $i => [$method, $path, $options]) {
        $headers[$i] = [];
        $handles[$i] = http_handle($addr, $method, $path, $options, $headers[$i]);
        \curl_multi_add_handle($mh, $handles[$i]);
    }
    do {
        \curl_multi_exec($mh, $running);
        \curl_multi_select($mh, 0.1);
    } while ($running > 0);
    $results = [];
    foreach ($handles as $i => $ch) {
        $results[$i] = http_result($ch, $headers[$i], \curl_multi_getcontent($ch), $requests[$i][2]);
        \curl_multi_remove_handle($mh, $ch);
    }

    return $results;
}
