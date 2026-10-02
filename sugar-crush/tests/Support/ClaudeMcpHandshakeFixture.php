<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * The server half of the MCP handshake, for the fake children the
 * {@see \SugarCraft\Crush\ClaudeCodeMcpClient} suites spawn.
 *
 * Since audit MCP-3 the client's `connect()` sends `initialize` as a REQUEST,
 * waits for its result, then sends `notifications/initialized` — so a fixture
 * that is deaf, chatty, slow or an emitter for some OTHER property still has to
 * complete the handshake before it can be any of those things. {@see PRELUDE}
 * is that code, to be pasted directly after a fixture's `<?php` tag.
 *
 * IT CONSUMES `notifications/initialized` TOO, and that is what makes it safe
 * for fixtures that write unprompted: the client sends the notification only
 * after it has read the `initialize` result, so a fixture that waits for it
 * before emitting can never put its own line into the read that carried the
 * handshake answer. It reads through `STDIN` (the constant), so a fixture that
 * keeps reading should use `STDIN` as well — a second `fopen('php://stdin')`
 * handle has its own buffer and would miss any bytes this one read ahead.
 */
final class ClaudeMcpHandshakeFixture
{
    public const PRELUDE = <<<'PHP'
        $__hsAnswered = false;
        while (($__hsLine = fgets(STDIN)) !== false) {
            $__hsMsg = json_decode($__hsLine, true);
            if (!is_array($__hsMsg)) {
                continue;
            }
            if (!$__hsAnswered && ($__hsMsg['method'] ?? null) === 'initialize' && array_key_exists('id', $__hsMsg)) {
                fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $__hsMsg['id'], 'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'fixture', 'version' => '0'],
                ]]) . "\n");
                fflush(STDOUT);
                $__hsAnswered = true;
                continue;
            }
            if ($__hsAnswered && ($__hsMsg['method'] ?? null) === 'notifications/initialized') {
                break;
            }
        }
        if ($__hsLine === false) {
            exit(0);
        }
        unset($__hsAnswered, $__hsLine, $__hsMsg);

        PHP;

    /** `$body` (which must start with `<?php`) with {@see PRELUDE} injected after the tag. */
    public static function around(string $body): string
    {
        if (!str_starts_with($body, '<?php')) {
            throw new \InvalidArgumentException('a fixture body must start with <?php');
        }

        return "<?php\n" . self::PRELUDE . substr($body, 5);
    }
}
