<?php

declare(strict_types=1);

/**
 * A small scripted SMTP server for SmtpTransportTest, run as a separate process:
 *
 *     php smtp-server.php <scenario.json>
 *
 * Prints "PORT <n>" once listening on 127.0.0.1, serves one connection, then
 * writes the transcript to <scenario.json>.out and exits.
 *
 * Scenario keys: starttls (bool), cert (PEM file with certificate and key),
 * auth (list of mechanisms), user, pass, reject_rcpt (list), silent (bool:
 * never answer), close_after_greeting (bool), auth_before_tls (bool: offer AUTH
 * before STARTTLS), implicit_tls (bool: TLS from the start, as on port 465).
 */

$scenarioFile = $argv[1] ?? '';
$scenario = json_decode((string) file_get_contents($scenarioFile), true, 512, JSON_THROW_ON_ERROR);
\assert(is_array($scenario));
$strings = static fn(mixed $list): array => array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
$authMechanisms = $strings($scenario['auth'] ?? []);
$rejectRcpt = $strings($scenario['reject_rcpt'] ?? []);

$context = stream_context_create(['ssl' => [
    'local_cert' => $scenario['cert'] ?? '',
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
echo 'PORT ', substr($name, (int) strrpos($name, ':') + 1), "\n";
flush();

$transcript = ['commands' => [], 'data' => null, 'tls' => false, 'auth' => null];
$save = static function () use (&$transcript, $scenarioFile): void {
    file_put_contents($scenarioFile . '.out', json_encode($transcript, JSON_THROW_ON_ERROR));
};

// Wait up to 20 s for the client.
$client = false;
$deadline = microtime(true) + 20;

while ($client === false && microtime(true) < $deadline) {
    $read = [$server];
    $write = $except = null;

    if (@stream_select($read, $write, $except, 0, 200_000) > 0) {
        $client = @stream_socket_accept($server, 0);
    }
}

if ($client === false) {
    $save();
    exit(0);
}

if (($scenario['silent'] ?? false) === true) {
    sleep(4);
    $save();
    exit(0);
}

$tls = false;

if (($scenario['implicit_tls'] ?? false) === true) {
    stream_set_blocking($client, true);

    if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) !== true) {
        $transcript['tls_error'] = true;
        $save();
        exit(0);
    }

    $tls = true;
    $transcript['tls'] = true;
}

$send = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};
$read = static function () use ($client): ?string {
    $line = fgets($client, 10000);

    return $line === false ? null : rtrim($line, "\r\n");
};

$send('220 fake.test ESMTP ready');

if (($scenario['close_after_greeting'] ?? false) === true) {
    fclose($client);
    $save();
    exit(0);
}

while (($line = $read()) !== null) {
    $transcript['commands'][] = $line;
    $verb = strtoupper((string) strtok($line, ' '));

    switch ($verb) {
        case 'EHLO':
            $lines = ['fake.test hello'];
            if (($scenario['starttls'] ?? false) === true && !$tls) {
                $lines[] = 'STARTTLS';
            }
            if ($authMechanisms !== [] && ($tls || ($scenario['auth_before_tls'] ?? false) === true || ($scenario['starttls'] ?? false) !== true)) {
                $lines[] = 'AUTH ' . implode(' ', $authMechanisms);
            }
            $lines[] = '8BITMIME';
            foreach ($lines as $i => $text) {
                $send('250' . ($i === count($lines) - 1 ? ' ' : '-') . $text);
            }
            break;

        case 'STARTTLS':
            $send('220 go ahead');
            $ok = stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
            if ($ok !== true) {
                $transcript['tls_error'] = true;
                break 2;
            }
            $tls = true;
            $transcript['tls'] = true;
            break;

        case 'AUTH':
            $parts = explode(' ', $line);
            $mechanism = strtoupper($parts[1] ?? '');
            if ($mechanism === 'PLAIN') {
                $decoded = explode("\0", (string) base64_decode($parts[2] ?? '', true));
                $user = $decoded[1] ?? '';
                $pass = $decoded[2] ?? '';
            } elseif ($mechanism === 'LOGIN') {
                $send('334 VXNlcm5hbWU6');
                $user = (string) base64_decode((string) $read(), true);
                $send('334 UGFzc3dvcmQ6');
                $pass = (string) base64_decode((string) $read(), true);
            } else {
                $send('504 unknown mechanism');
                break;
            }
            $transcript['auth'] = ['mechanism' => $mechanism, 'user' => $user, 'pass' => $pass, 'tls' => $tls];
            $ok = $user === ($scenario['user'] ?? null) && $pass === ($scenario['pass'] ?? null);
            $send($ok ? '235 authenticated' : '535 5.7.8 authentication credentials invalid');
            break;

        case 'MAIL':
            $send('250 sender ok');
            break;

        case 'RCPT':
            $rejected = false;
            foreach ($rejectRcpt as $address) {
                $rejected = $rejected || str_contains($line, '<' . $address . '>');
            }
            $send($rejected ? '550 5.1.1 mailbox unavailable' : '250 recipient ok');
            break;

        case 'DATA':
            $send('354 end with <CRLF>.<CRLF>');
            $data = '';
            while (($dataLine = fgets($client, 10000)) !== false) {
                if ($dataLine === ".\r\n") {
                    break;
                }
                $data .= str_starts_with($dataLine, '.') ? substr($dataLine, 1) : $dataLine;
            }
            $transcript['data'] = $data;
            $send('250 2.0.0 queued as FAKE1');
            break;

        case 'QUIT':
            $send('221 bye');
            break 2;

        default:
            $send('502 not implemented');
    }
}

fclose($client);
$save();
