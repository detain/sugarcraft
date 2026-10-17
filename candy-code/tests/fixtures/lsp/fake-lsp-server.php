<?php

declare(strict_types=1);

/*
 * A minimal stdio language server for the step 3.F tests — just enough of the
 * protocol to drive LspLauncher, LspClient::freshDiagnostics()/outline() and
 * PostEditDiagnosticsHook through a REAL LspConnection.
 *
 *  - `initialize` is answered, and its params are appended to $FAKE_LSP_LOG as
 *    one JSON line (so a test can see the rootUri it was sent);
 *  - `didOpen` publishes one ERROR per line containing `BROKEN` and one
 *    WARNING per line containing `WARN` — unless $FAKE_LSP_SILENT is set;
 *  - `didClose` publishes an empty list, as real servers do;
 *  - `documentSymbol` returns one DocumentSymbol per `class X` line, with a
 *    child per `function y` line after it;
 *  - every other request is refused with -32601, which is how the client's
 *    `textDocument/diagnostic` pull is answered;
 *  - `shutdown`/`exit` end it.
 */

$log = getenv('FAKE_LSP_LOG') ?: '';
$silent = (getenv('FAKE_LSP_SILENT') ?: '') !== '';

$send = static function (array $message): void {
    $json = json_encode($message, JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, 'Content-Length: ' . strlen($json) . "\r\n\r\n" . $json);
    fflush(STDOUT);
};

$pathOf = static function (string $uri): string {
    return rawurldecode(substr($uri, strlen('file://')));
};

while (true) {
    $length = 0;
    while (($header = fgets(STDIN)) !== false) {
        $header = rtrim($header, "\r\n");
        if ($header === '') {
            break;
        }
        if (stripos($header, 'Content-Length:') === 0) {
            $length = (int) trim(substr($header, strlen('Content-Length:')));
        }
    }
    if ($header === false) {
        exit(0);
    }

    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread(STDIN, $length - strlen($body));
        if ($chunk === false || $chunk === '') {
            exit(0);
        }
        $body .= $chunk;
    }

    $message = json_decode($body, true);
    if (!is_array($message)) {
        continue;
    }
    $method = $message['method'] ?? null;
    $id = $message['id'] ?? null;
    $params = $message['params'] ?? [];

    switch ($method) {
        case 'initialize':
            if ($log !== '') {
                file_put_contents($log, json_encode($params, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            }
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['capabilities' => ['textDocumentSync' => 1, 'documentSymbolProvider' => true]]]);
            break;

        case 'textDocument/didOpen':
            if ($silent) {
                break;
            }
            $document = $params['textDocument'];
            $diagnostics = [];
            foreach (explode("\n", (string) $document['text']) as $index => $line) {
                foreach (['BROKEN' => 1, 'WARN' => 2] as $marker => $severity) {
                    $at = strpos($line, $marker);
                    if ($at !== false) {
                        $diagnostics[] = [
                            'range' => ['start' => ['line' => $index, 'character' => $at], 'end' => ['line' => $index, 'character' => $at + strlen($marker)]],
                            'severity' => $severity,
                            'message' => "{$marker} found\non line " . ($index + 1),
                        ];
                    }
                }
            }
            $send(['jsonrpc' => '2.0', 'method' => 'textDocument/publishDiagnostics', 'params' => [
                'uri' => $document['uri'],
                'version' => $document['version'],
                'diagnostics' => $diagnostics,
            ]]);
            break;

        case 'textDocument/didClose':
            $send(['jsonrpc' => '2.0', 'method' => 'textDocument/publishDiagnostics', 'params' => [
                'uri' => $params['textDocument']['uri'],
                'diagnostics' => [],
            ]]);
            break;

        case 'textDocument/documentSymbol':
            $lines = @file($pathOf((string) $params['textDocument']['uri']), FILE_IGNORE_NEW_LINES) ?: [];
            $symbols = [];
            foreach ($lines as $index => $line) {
                $range = ['start' => ['line' => $index, 'character' => 0], 'end' => ['line' => $index, 'character' => strlen($line)]];
                if (preg_match('/class (\w+)/', $line, $m) === 1) {
                    $symbols[] = ['name' => $m[1], 'kind' => 5, 'range' => $range, 'selectionRange' => $range, 'children' => []];
                } elseif (preg_match('/function (\w+)/', $line, $m) === 1 && $symbols !== []) {
                    $symbols[count($symbols) - 1]['children'][] = ['name' => $m[1] . 'FromServer', 'kind' => 6, 'range' => $range, 'selectionRange' => $range];
                }
            }
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $symbols]);
            break;

        case 'shutdown':
            $send(['jsonrpc' => '2.0', 'id' => $id, 'result' => null]);
            break;

        case 'exit':
            exit(0);

        default:
            if ($id !== null) {
                $send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => "unsupported: {$method}"]]);
            }
    }
}
