<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Script;

/**
 * The child-side half of the bridge: the code {@see ScriptRunner} passes to
 * `php -r`, which gives a model-written script the two functions it may call
 * and hands the script's return value back over fd 3.
 *
 * ## What the child actually is
 *
 * The runner spawns `PHP_BINARY` with `-n`, so this source is the ONLY code
 * loaded: core PHP plus the always-present json extension. A distro that
 * ships mbstring or ctype as shared modules leaves the child without them —
 * the tool description the catalog step writes must say so rather than let a
 * model write `mb_strlen` and eat a fatal.
 *
 * ## The contract this file implements
 *
 * NDJSON frames over `php://fd/3`, one object per line, exactly the shapes
 * {@see ScriptBridge} decodes: `tool()` sends a `call` frame and blocks for
 * the response whose `id` matches; `tools()` sends `list`; and after the
 * staged script returns, a single `return` frame carries its value. The
 * staged file arrives as the last element of `$argv` (everything after the
 * `--` the runner puts in front of it, under `-r` where argv[0] is PHP's own
 * marker). A script that writes junk onto fd 3 does not crash the parent and
 * does not hang its own process group: the parent answers uncorrelatable
 * frames with an `id: null` error, and `tool()` surfaces that as an error
 * result instead of waiting for a response that will never carry its id.
 *
 * ## What is DELIBERATELY absent
 *
 * No autoloader, no config, no credentials, no second dispatcher — the child
 * has no channel that could carry an effect except fd 3, which is why the
 * runner can be policy-free: every effect passes through a closure the caller
 * injected, and the caller wires that closure through the same permission
 * gate the in-process tools use. A script exception is NOT converted into a
 * return frame: its class and message go to stderr and the child exits
 * non-zero, so the failure arrives at the parent as exit code plus stderr
 * rather than as a value the parent would have to guess the provenance of.
 */
final class ScriptPrelude
{
    /**
     * The child source, as one string for `php -r`.
     *
     * The length is bounded by `MAX_ARG_STRLEN` (131072 bytes on Linux) the
     * same way script hooks keep their payloads bounded —
     * {@see \SugarCraft\Code\Hooks\ScriptHook} measures it — and this string
     * is a few hundred bytes, constant for every run: nothing model-written
     * rides in it, the script rides in the staged FILE instead, which is why
     * arbitrarily long scripts never touch the argv limit at all.
     */
    public static function source(): string
    {
        return <<<'PHP'
            $__bridge = @fopen('php://fd/3', 'r+');
            if ($__bridge === false) {
                fwrite(STDERR, "script-runner: the tool bridge (fd 3) is not open\n");
                exit(2);
            }
            $GLOBALS['__script_bridge'] = $__bridge;
            $GLOBALS['__script_seq'] = 0;

            function __script_request(array $frame): ?array
            {
                $fh = $GLOBALS['__script_bridge'];
                $id = ++$GLOBALS['__script_seq'];
                $frame['id'] = $id;
                $encoded = json_encode($frame);
                if ($encoded === false) {
                    return ['id' => $id, 'error' => 'script-runner: frame arguments are not JSON-encodable'];
                }
                if (@fwrite($fh, $encoded . "\n") === false) {
                    return ['id' => $id, 'error' => 'script-runner: the tool bridge closed'];
                }
                while (($raw = @fgets($fh, 1048577)) !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $rid = $decoded['id'] ?? null;
                        if ($rid === $id) {
                            return $decoded;
                        }
                        if ($rid === null && array_key_exists('error', $decoded)) {
                            return ['id' => $id, 'error' => 'script-runner: ' . (string) $decoded['error']];
                        }
                        continue;
                    }
                    if (trim($raw) !== '') {
                        return ['id' => $id, 'error' => 'script-runner: uncorrelated frame on the bridge'];
                    }
                }
                return ['id' => $id, 'error' => 'script-runner: the tool bridge closed before a response'];
            }

            function tool(string $name, array $arguments = []): array
            {
                $response = __script_request(['method' => 'call', 'params' => ['name' => $name, 'arguments' => $arguments]]);
                if ($response === null) {
                    return ['content' => 'script-runner: the tool bridge closed', 'isError' => true];
                }
                if (array_key_exists('error', $response)) {
                    return ['content' => (string) $response['error'], 'isError' => true];
                }
                $result = $response['result'] ?? null;
                if (is_array($result) && array_key_exists('content', $result)) {
                    return [
                        'content' => is_string($result['content']) ? $result['content'] : '',
                        'isError' => (bool) ($result['isError'] ?? false),
                    ];
                }
                return ['content' => is_string($result) ? $result : '', 'isError' => false];
            }

            function tools(): array
            {
                $response = __script_request(['method' => 'list']);
                if ($response === null || array_key_exists('error', $response)) {
                    return [];
                }
                $rows = $response['result']['tools'] ?? null;
                return is_array($rows) ? $rows : [];
            }

            $staged = isset($argv) && $argv !== [] ? (string) end($argv) : '';
            if ($staged === '' || !is_file($staged)) {
                fwrite(STDERR, "script-runner: no staged script path followed --\n");
                exit(2);
            }

            try {
                $__value = require $staged;
            } catch (\Throwable $t) {
                fwrite(STDERR, get_class($t) . ': ' . $t->getMessage() . "\n");
                exit(1);
            }

            $payload = json_encode(['id' => 0, 'method' => 'return', 'value' => $__value]);
            if ($payload === false) {
                $payload = json_encode([
                    'id' => 0,
                    'method' => 'return',
                    'error' => 'the script returned a value that is not JSON-encodable (' . gettype($__value) . ')',
                ]);
            }
            if ($payload !== false) {
                @fwrite($GLOBALS['__script_bridge'], $payload . "\n");
            }
            fflush($GLOBALS['__script_bridge']);
            fclose($GLOBALS['__script_bridge']);
            exit(0);
            PHP;
    }
}
