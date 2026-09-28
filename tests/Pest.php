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
    // (a handler rather than @: PHPUnit reports the suppressed warning of a port in use)
    \set_error_handler(static fn () => true);
    for ($port = 18900; false === $socket = \stream_socket_server("tcp://127.0.0.1:$port"); ++$port) {
        if ($port >= 18949) {
            throw new RuntimeException('No free port in 18900-18949');
        }
    }
    \restore_error_handler();
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

    return app_wait($proc, 10);
}

/**
 * Wait for swerve to exit and return its exit code: from the last proc_get_status(), since
 * proc_close() returns -1 once that has seen the exit (PHP 8.2).
 */
function app_wait($proc, float $timeout): int
{
    $deadline = \microtime(true) + $timeout;
    while (($status = \proc_get_status($proc))['running'] && \microtime(true) < $deadline) {
        \usleep(50_000);
    }
    \proc_close($proc);

    return $status['exitcode'];
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

/**
 * Open a WebSocket to $path, as a browser does, with the cookies of $options (a browser's jar).
 *
 * @return resource the connection, after the 101
 */
function ws_connect(string $addr, string $path, array $options = [])
{
    [$host, $port] = \explode(':', $addr);
    $s             = \stream_socket_client("tcp://$host:$port", timeout: 5);
    \stream_set_timeout($s, 10);
    $key    = \base64_encode(\random_bytes(16));
    $cookie = ($options['cookies'] ?? []) ? 'Cookie: ' . \implode('; ', \array_map(static fn ($name, $value) => "$name=$value", \array_keys($options['cookies']), $options['cookies'])) . "\r\n" : '';
    \fwrite($s, "GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n$cookie\r\n");
    $head = '';
    while (!\str_contains($head, "\r\n\r\n") && '' !== ($byte = (string) \fread($s, 1))) {
        $head .= $byte;
    }
    if (!\str_starts_with($head, 'HTTP/1.1 101') || !\str_contains($head, \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)))) {
        throw new RuntimeException("No WebSocket at $path:\n$head");
    }

    return $s;
}

/** Send one masked frame, as clients send: 1 text, 2 binary, 8 close. */
function ws_send($s, int $opcode, string $payload): void
{
    $n    = \strlen($payload);
    $mask = \random_bytes(4);
    $head = \chr(0x80 | $opcode) . ($n < 126 ? \chr(0x80 | $n) : ($n < 65536 ? \chr(0x80 | 126) . \pack('n', $n) : \chr(0x80 | 127) . \pack('J', $n)));
    \fwrite($s, $head . $mask . ($payload ^ \substr(\str_repeat($mask, \intdiv($n, 4) + 1), 0, $n)));
}

/**
 * The next frame from the server, as [opcode, payload]; null when the connection ended.
 *
 * @return array{0: int, 1: string}|null
 */
function ws_read($s): ?array
{
    $read = static function (int $n) use ($s): ?string {
        $bytes = '';
        while (\strlen($bytes) < $n) {
            $chunk = \fread($s, $n - \strlen($bytes));
            if (false === $chunk || '' === $chunk) {
                return null;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    };
    if (null === $head = $read(2)) {
        return null;
    }
    $n = \ord($head[1]) & 0x7F;
    if (126 === $n) {
        $n = \unpack('n', $read(2))[1];
    } elseif (127 === $n) {
        $n = \unpack('J', $read(8))[1];
    }

    return [\ord($head[0]) & 0x0F, $n > 0 ? $read($n) : ''];
}

/** The lines of a swerve log that report a problem. */
function log_problems(string $log): array
{
    return \array_values(\array_filter(\explode("\n", \file_get_contents($log)), static fn ($line) => \preg_match('/error|exception|warning|fatal/i', $line)));
}
