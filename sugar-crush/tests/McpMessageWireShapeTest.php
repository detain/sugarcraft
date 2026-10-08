<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\Tests\Support\ClaudeMcpHandshakeFixture;

/**
 * The CONSUMER half of the wire-shape guard: the codec's own key-set pins
 * ({@see \SugarCraft\Mcp\McpMessage::toJson()} versus
 * {@see \SugarCraft\Mcp\McpMessage::toArray()}) moved to the canonical class
 * with the lane-A2 fold and now live in `sugarcraft/sugar-mcp`
 * (tests/McpMessageWireShapeTest.php). What stays here is the end-to-end claim
 * only this product can make — that the widened `parse()` output actually
 * reaches {@see ClaudeCodeMcpClient::readMessages()} through a real child.
 *
 * E478 — THE SECOND CONSUMER OF THE WIDENING, PINNED RATHER THAN ASSERTED
 * ABOUT IN PROSE.
 *
 * `McpMessage::parse()` has TWO callers, not one:
 * {@see \SugarCraft\Mcp\StdioMcpServer::readResponse()} (phase-2a home: `sugarcraft/sugar-mcp`) and
 * {@see ClaudeCodeMcpClient::readMessages()}. Every artefact of round 55
 * discussed `resultSet` as though the first were the only one, so this
 * method's output grew a shape nobody had looked at: a line such as
 * `{"jsonrpc":"2.0","id":"1","result":null}` that `parse()` previously
 * DROPPED now arrives as an element.
 *
 * It is benign today — the class never reads `->result` anywhere — but "it
 * is benign because nothing reads it" is a claim with a shelf life, and the
 * next person to give this class a result-reading path should find the shape
 * already described rather than re-derive it from the sibling.
 *
 * The DISCRIMINATOR row is the second one: `{"jsonrpc":"2.0"}` is still
 * dropped. Without it a `parse()` that accepted everything would pass.
 */
final class McpMessageWireShapeTest extends TestCase
{
    public function testANullResultReplyNowReachesReadMessagesAndAnEmptyEnvelopeStillDoesNot(): void
    {
        $dir = sys_get_temp_dir() . '/sc_mcpwire_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $script = $dir . '/emitter.php';
        // Emitted only AFTER the handshake connect() waits for (audit MCP-3):
        // unprompted, the "1" line would have been taken for the reply to
        // `initialize`, whose id is also "1".
        file_put_contents(
            $script,
            ClaudeMcpHandshakeFixture::around('<?php'
            . ' fwrite(STDOUT, \'{"jsonrpc":"2.0","id":"1","result":null}\' . "\n");'
            . ' fwrite(STDOUT, \'{"jsonrpc":"2.0"}\' . "\n");'
            . ' fwrite(STDOUT, \'{"jsonrpc":"2.0","id":"2","result":7}\' . "\n");'
            . ' fflush(STDOUT); $e = microtime(true) + 5; while (microtime(true) < $e) { usleep(20000); }'),
        );

        $client = new ClaudeCodeMcpClient(PHP_BINARY, [$script]);

        try {
            $client->connect();

            $seen = [];
            for ($i = 0; $i < 60 && \count($seen) < 2; $i++) {
                foreach ($client->readMessages() as $message) {
                    $seen[(string) $message->id] = $message;
                }
                usleep(20000);
            }

            $this->assertArrayHasKey(
                '1',
                $seen,
                'a legal "result": null reply did not reach readMessages(), so the widening did '
                . 'not in fact reach this consumer and the entry describing it is wrong',
            );
            $this->assertTrue($seen['1']->resultSet, 'the sentinel did not survive the trip');
            $this->assertNull($seen['1']->result);

            $this->assertArrayHasKey('2', $seen, 'the control: a scalar result must also arrive');
            $this->assertSame(7, $seen['2']->result);

            $this->assertCount(
                2,
                $seen,
                'an envelope with no method, no error and no result key reached the caller. It is '
                . 'not a request, not a response and not a notification, and letting it through '
                . 'hands a matcher an object with nothing to match on',
            );
        } finally {
            $client->disconnect();
            @unlink($script);
            @rmdir($dir);
        }
    }
}
