<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\StdioMcpServer;

/**
 * A JSON-RPC `result` IS ANY JSON VALUE. The TYPE MATRIX for the envelope
 * itself lives with the canonical class in `sugarcraft/sugar-mcp`
 * (tests/McpMessageTest.php, moved here by the lane-A2 fold); what stays in
 * this file is the CONSUMER half only — the {@see StdioMcpServer::callTool()}
 * and {@see StdioMcpServer::start()} behaviour the widening moved the crash
 * onto, driven through real server children.
 *
 * Historical premise, kept because it is why these rows exist: the product's
 * envelope once typed `$result` as `?array` while `parse()` handed it
 * `json_decode($raw, true)['result']` unchecked, so every reply whose `result`
 * decoded to a PHP scalar threw a `TypeError` out of `parse()` before the
 * object existed. A `TypeError` is not a swallowed warning and not a
 * `RuntimeException`, and `\SugarCraft\Crush\MCP\McpClient` wraps `start()` in
 * `catch (\RuntimeException)` precisely so that "a single unreachable/
 * misbehaving server must not abort loading the rest" — one conforming server
 * answering `initialize` with a non-object `result` therefore took down the
 * whole MCP subsystem for the session. The fix is `mixed` + the `resultSet`
 * sentinel; these rows pin that the product-side consumers read both halves.
 */
final class McpMessageResultTypeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_resulttype_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
        file_put_contents($this->tempDir . '/scalar.php', self::SCALAR_RESULT_SERVER);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    /**
     * A LEGAL `"result": null` REACHES THE MODEL AS THE TEXT `null`, END TO END,
     * AND AN ABSENT `result` STILL FAILS THE CALL.
     *
     * `callTool()` tested `$response->result === null` and answered
     * `['error' => 'Tool call failed']` for it — a wrong answer, but a graceful
     * one, which is why it was split off from the scalar crash rather than
     * bundled with it. With the sentinel the test is `!$response->resultSet`, so a
     * present-but-null result falls through to the same wrapping branch that
     * renders `false` and `0`, and is rendered as its own JSON text.
     *
     * DRIVEN THROUGH A REAL SERVER CHILD, because the sentinel has to survive
     * `toJson()` on the way out, the wire, `parse()` on the way back, and
     * `readResponse()`'s id matching — a unit test of `parse()` alone would pass
     * with the sentinel threaded nowhere else.
     *
     * BOTH POLARITIES IN ONE TEST on purpose. A `callTool()` that simply deleted
     * its null check would pass the first row and silently start reporting
     * success for servers that answer nothing at all.
     */
    public function testCallToolRendersANullResultAndStillFailsOnAnAbsentOne(): void
    {
        $server = new StdioMcpServer(
            name: 'nullable',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        $server->start();

        try {
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => 'null']]],
                $server->callTool('nullresult', []),
                'a legal "result": null was reported as a failed tool call — the resultSet '
                . 'sentinel is not reaching callTool()',
            );
            $this->assertSame(
                ['error' => 'Tool call failed'],
                $server->callTool('noresult', []),
                'a reply with NO result key was treated as a successful call, so callTool() has '
                . 'stopped distinguishing "answered null" from "answered nothing"',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * A SERVER THAT ANSWERS `initialize` WITH `"result": null` COMES UP EMPTY
     * RATHER THAN THROWING, AND ONE THAT ANSWERS NOTHING STILL THROWS.
     *
     * {@see StdioMcpServer::start()}'s gate reads "did the server answer at all",
     * and its old spelling — `result === null && error === null` — could not tell a
     * null answer from no answer. It never had to: `parse()` rejected the
     * null-result message upstream, so `$response` was simply null and that arm
     * distinguished nothing. Now that the message parses, the gate is
     * `!$response->resultSet` and the two cases genuinely diverge, so the choice
     * has to be made deliberately rather than inherited.
     *
     * COMING UP EMPTY IS THE CHOICE, and it matches what
     * {@see \SugarCraft\Crush\MCP\McpClient::startServer()} exists to do: one
     * misbehaving server must not cost the session. A null handshake result is
     * MCP-invalid — the spec says an object carrying a `protocolVersion` — but the
     * outcome of registering it is a server with zero tools, which is
     * indistinguishable from skipping it and costs no exception.
     *
     * SILENCE IS A DIFFERENT EVENT and still fails loudly. That polarity is the
     * second half of this test, without which the first is satisfied by a
     * `start()` that has stopped failing at all.
     *
     * ⚠️ AND A THIRD PART, WHICH IS THE ROSTER'S OWN KNOWN-POSITIVE. Those two
     * polarities are both about `start()`; NEITHER of them says anything about
     * `listTools()`. `assertSame([], $server->listTools())` is exactly what a
     * {@see \SugarCraft\Mcp\StdioMcpServer::parseTools()} that had stopped working AT ALL would
     * return, so on its own the empty roster is not evidence — it is the reading
     * a dead instrument gives. The well-behaved fixture is therefore driven
     * through the SAME `start()` -> `parseTools()` -> `listTools()` path first
     * and must come back with its one tool. Only against that does the `[]`
     * below mean "this server has no tools" rather than "this code returns no
     * tools".
     */
    public function testANullHandshakeResultComesUpEmptyWhileSilenceStillFails(): void
    {
        // The known-positive control, first: the same path must be able to
        // produce a NON-empty roster, or the emptiness asserted below proves
        // nothing about the null-answering server.
        $control = new StdioMcpServer(
            name: 'wellbehaved',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        try {
            $control->start();
            $names = array_map(
                static fn (\SugarCraft\Crush\MCP\McpTool $tool): string => $tool->name,
                $control->listTools(),
            );
            $this->assertSame(
                ['ping'],
                $names,
                'the roster came back empty for a server that DOES advertise a tool, so '
                . 'parseTools() is not working and the empty roster asserted below would be '
                . 'meaningless',
            );
        } finally {
            $control->stop();
        }

        $script = $this->tempDir . '/nullinit.php';
        file_put_contents($script, self::NULL_HANDSHAKE_SERVER);

        $server = new StdioMcpServer(
            name: 'nullinit',
            command: PHP_BINARY,
            args: [$script],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        try {
            $server->start();
            $this->assertSame(
                [],
                $server->listTools(),
                'a server whose handshake result was null reported tools it cannot have',
            );
        } finally {
            $server->stop();
        }

        $silent = $this->tempDir . '/silent.php';
        file_put_contents($silent, '<?php sleep(10);');
        $mute = new StdioMcpServer(
            name: 'silent',
            command: PHP_BINARY,
            args: [$silent],
            env: [],
            startTimeoutSeconds: 1.0,
        );

        // `fail()` USED TO LIVE INSIDE THIS TRY. It throws
        // PHPUnit\Framework\AssertionFailedError, which extends
        // PHPUnit\Framework\Exception, which extends \RuntimeException — so the
        // catch below caught the failure it was standing next to, and the
        // "start() reported success" case was reported as a string mismatch on
        // the fail() message rather than as the missing throw it is.
        $caught = null;

        try {
            $mute->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            $mute->stop();
        }

        $this->assertNotNull($caught, 'a server that answered nothing at all was reported as started');
        $this->assertStringContainsString('Failed to start MCP server: silent', $caught->getMessage());
    }

    /**
     * WIDENING `$result` TO `mixed` RELOCATES THE CRASH RATHER THAN CLOSING IT,
     * UNLESS `callTool()` IS FIXED TOO — that method is declared `: array` and
     * returned `$response->result` directly, so `"result": true` simply became a
     * `TypeError` on the return type instead of on the constructor. This drives
     * a REAL server child, end to end, so the two halves cannot drift apart.
     *
     * The wrap shape is `{content: [{type: text, …}]}` because that is what
     * {@see \SugarCraft\Crush\Tools\McpToolBridge::renderContent()} renders
     * verbatim: a misbehaving server's scalar reaches the model as its own text
     * rather than as an exception that costs the turn.
     *
     * Object results are asserted in the same test on purpose — a `callTool()`
     * that wrapped EVERYTHING would pass the scalar rows while breaking every
     * conforming server there is.
     */
    public function testCallToolWrapsAScalarResultAndLeavesAnObjectResultAlone(): void
    {
        $server = new StdioMcpServer(
            name: 'scalar',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        $server->start();

        try {
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => 'true']]],
                $server->callTool('boolean', []),
                'a boolean result must reach the model as readable text, not as a TypeError',
            );
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '42']]],
                $server->callTool('number', []),
            );
            // A string result passes through UNQUOTED — json_encode would hand
            // the model `"plain text"` with the quotes as part of the answer.
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => 'plain text']]],
                $server->callTool('string', []),
            );
            // THE CONTROL: the conforming shape is returned untouched.
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => 'pong']], 'isError' => false],
                $server->callTool('object', []),
                'a spec-shaped object result must be returned as-is; wrapping everything '
                . 'would pass the scalar rows above and break every real server',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * ZERO IS THE ONE SCALAR A FALSY TEST ERASES, and this pins both spellings
     * of it end to end.
     *
     * The wrap above first read
     * `json_encode($response->result) ?: ''`. `json_encode(0)` is the string
     * `"0"`, which is FALSY in PHP, so `?:` replaced a legal `"result": 0` with
     * an empty string and the tool result reached the model carrying nothing.
     * `0.0` went the same way, because `json_encode(0.0)` is also `"0"`. Every
     * other scalar survived, which is precisely why the first alphabet — three
     * non-zero numbers, no zero — could not see it.
     *
     * BOTH SPELLINGS, and they are genuinely different paths rather than the
     * same one twice: `json_decode()` yields `int(0)` for the literal `0` and
     * `float(0)` for the literal `0.0`, and only the second exercises
     * `json_encode()`'s float branch. The fixture server emits the float case
     * as raw text for the reason given in its own comment.
     *
     * THE NON-EMPTY CONTROL is the `number` row: a wrap mutated to return `''`
     * unconditionally would satisfy an assertion that only ever looked at zero.
     */
    public function testAZeroResultReachesTheModelAsZeroAndNotAsEmptyText(): void
    {
        $server = new StdioMcpServer(
            name: 'scalar',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        $server->start();

        try {
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '0']]],
                $server->callTool('zero', []),
                'an integer 0 result must reach the model as "0"; an empty text here means a '
                . 'falsy test (`json_encode($r) ?: \'\'`) has eaten the payload',
            );
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '0']]],
                $server->callTool('zerofloat', []),
                'a float 0.0 result must reach the model as "0" for the same reason',
            );
            // THE CONTROL: a wrap that returned '' for everything would pass
            // both rows above.
            $this->assertSame(
                ['content' => [['type' => 'text', 'text' => '42']]],
                $server->callTool('number', []),
                'the non-zero control must still carry its own digits',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * The handshake survives it too. `start()` reads `initialize`'s reply
     * through the same `parse()`, and its failure is not caught as a
     * `RuntimeException` anywhere above it — see this class's doc-block.
     */
    public function testTheHandshakeSurvivesAServerWhoseInitializeResultIsScalar(): void
    {
        file_put_contents($this->tempDir . '/scalar-init.php', self::SCALAR_INITIALIZE_SERVER);

        $server = new StdioMcpServer(
            name: 'scalar-init',
            command: PHP_BINARY,
            args: [$this->tempDir . '/scalar-init.php'],
            env: [],
            startTimeoutSeconds: 5.0,
        );

        $server->start();

        try {
            // It came up, and `tools/list` still parsed: a scalar `initialize`
            // result is odd but not fatal, which is the whole claim.
            $this->assertCount(1, $server->listTools());
            $this->assertSame('ping', $server->listTools()[0]->name);
        } finally {
            $server->stop();
        }
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    /**
     * Answers `initialize` with a LEGAL but MCP-invalid `"result": null`, and
     * every later message the same way. Spelled by hand rather than through
     * `json_encode()` so the null is unmistakably a present key.
     */
    private const NULL_HANDSHAKE_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            echo '{"jsonrpc":"2.0","id":', json_encode((string) $msg['id']), ',"result":null}', "\n";
            flush();
        }
        PHP;

    /**
     * Handshakes normally, then answers `tools/call` with whatever type the
     * requested tool name asks for.
     */
    private const SCALAR_RESULT_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize') {
                $result = ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()];
            } elseif ($method === 'tools/list') {
                $result = ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]];
            } else {
                $name = (string) ($msg['params']['name'] ?? '');
                if ($name === 'zerofloat') {
                    // RAW TEXT, not json_encode(): the encoder renders 0.0 as
                    // "0", so a float zero cannot be put on the wire through
                    // it at all. json_decode() is the only side of the pair
                    // that distinguishes 0.0 from 0, so the literal is spelled
                    // by hand to make the parent receive a genuine float.
                    echo '{"jsonrpc":"2.0","id":', json_encode((string) $msg['id']), ',"result":0.0}', "\n";
                    flush();
                    continue;
                }
                if ($name === 'noresult') {
                    // NO `result` KEY AT ALL — the other polarity of the
                    // resultSet sentinel, and the one that must still be
                    // reported as a failed call.
                    echo '{"jsonrpc":"2.0","id":', json_encode((string) $msg['id']), '}', "\n";
                    flush();
                    continue;
                }
                $result = match ($name) {
                    'boolean' => true,
                    'number' => 42,
                    'zero' => 0,
                    'nullresult' => null,
                    'string' => 'plain text',
                    default => ['content' => [['type' => 'text', 'text' => 'pong']], 'isError' => false],
                };
            }
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        PHP;

    /** Answers `initialize` with a bare `true`, then handshakes normally. */
    private const SCALAR_INITIALIZE_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $result = ((string) ($msg['method'] ?? '')) === 'initialize'
                ? true
                : ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                ]]];
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        PHP;

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
