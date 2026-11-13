<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * S-H01: the newline splitting the migrated seats used to hand-roll is
 * sugar-mcp-client's JsonLines\Reader (R17a). Pinned per seat: the census of
 * hand-rolled splitters in the migrated files is zero, and the daemon-report
 * reader keeps its three policies (greeting skipped, torn tail at EOF is not a
 * report, a non-JSON line ends the wait).
 */
final class JsonLinesAdoptionTest extends TestCase
{
    private const MIGRATED = [
        'Host/AgentResume.php',
        'Cli/Serve/ServeStartup.php',
        'Cli/Acp.php',
        'Providers/CustomProvider.php',
        'Providers/CodexProvider.php',
        'Providers/ClaudeCodeProvider.php',
        'Providers/SglangProvider.php',
        'Providers/Concerns/AnthropicMessagesWire.php',
        'Providers/Concerns/OpenAiResponsesWire.php',
        'Server/Workspace/WorkspaceHostClient.php',
    ];

    public function testNoMigratedFileSplitsItsBufferOnNewlineByHand(): void
    {
        foreach (self::MIGRATED as $file) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/' . $file);
            self::assertDoesNotMatchRegularExpression('/strpos\(\s*\$(buffer|this->buffer)\s*,\s*"\\\\n"/', $source, $file);
            self::assertStringContainsString('JsonLines\\Reader', $source, $file);
        }
    }

    /** @return array<string, mixed>|null */
    private function report(string $bytes, ?int &$pid = null): ?array
    {
        [$a, $b] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        fwrite($b, $bytes);
        fclose($b);
        $read = \Closure::bind(
            static fn (mixed $s, ?int &$p): ?array => \SugarCraft\Code\Cli\Serve::readReport($s, 1.0, $p),
            null,
            \SugarCraft\Code\Cli\Serve::class,
        );
        $out = $read($a, $pid);
        fclose($a);

        return $out;
    }

    public function testTheGreetingIsSkippedAndTheReportReturned(): void
    {
        $pid = null;
        $out = $this->report("{\"hello\":true,\"pid\":4242}\n{\"ok\":true,\"url\":\"u\"}\n", $pid);

        self::assertSame(['ok' => true, 'url' => 'u'], $out);
        self::assertSame(4242, $pid);
    }

    public function testATornReportAtEofIsNotAReport(): void
    {
        self::assertNull($this->report('{"ok":tr'));
    }

    public function testANonJsonLineEndsTheWait(): void
    {
        self::assertNull($this->report("garbage\n{\"ok\":true}\n"));
    }
}
