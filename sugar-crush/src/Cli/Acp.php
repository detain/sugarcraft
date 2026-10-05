<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableResourceStream;
use SugarCraft\Crush\Acp\AcpServer;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Lang;

/**
 * `sugarcrush acp` — sugar-crush as an Agent Client Protocol agent, for an
 * editor that hosts agents over stdio: Zed, JetBrains, Neovim (roadmap 5.9,
 * Appendix O §6.12). The editor starts this process and speaks
 * newline-delimited JSON-RPC 2.0 on its stdin and stdout; {@see AcpServer}
 * is the protocol, this is the process around it.
 *
 * STDOUT IS THE WIRE, AND NOTHING ELSE MAY REACH IT. A stray `echo`, a PHP
 * notice, a library's progress line would land in the middle of the
 * editor's JSON stream and break its framing, so the wire is written through
 * its own handle and PHP's own output — everything `echo`/`print` would
 * send to stdout — is captured by an output buffer and moved to stderr,
 * where an editor shows it as the agent's log. Launch warnings go there too.
 *
 * STDIN CLOSING IS THE EDITOR GOING AWAY: every session is closed (a running
 * turn cancelled, its lock released) and the process exits 0. SIGINT and
 * SIGTERM do the same.
 *
 * EXITS. 2 for a usage error (an operand, `--output-format json`: the
 * protocol is the output). 1 when stdin is already closed. 0 when the editor
 * closed the connection.
 */
final class Acp
{
    /**
     * The longest line read from the editor. A prompt can embed whole files
     * (an unsaved buffer), so this is generous; past it the line is refused
     * as unparseable rather than buffered without end.
     */
    public const MAX_LINE_BYTES = 64 * 1024 * 1024;

    private function __construct()
    {
    }

    public static function run(ParsedArgs $args): int
    {
        if ($args->subcommandArgs !== []) {
            return NonInteractive::failUsage(Lang::t('cli.acp.unexpected_operand', ['operand' => $args->subcommandArgs[0]]), $args->outputFormat, Lang::t('cli.acp.usage'));
        }
        if ($args->outputFormat === NonInteractive::FORMAT_JSON) {
            return NonInteractive::failUsage(Lang::t('cli.acp.json_does_not_apply'), $args->outputFormat, Lang::t('cli.acp.usage'));
        }

        // Descriptor 0 is the editor's half of the connection. Checked before
        // the stream wraps it, which throws on a closed handle.
        if (!\defined('STDIN') || !\is_resource(\STDIN)) {
            self::stderr(Lang::t('cli.acp.stdin_closed') . "\n");

            return NonInteractive::EXIT_FAILURE;
        }

        return self::serve(\STDIN, \STDOUT, Loop::get());
    }

    /**
     * Serve one editor on $in/$out until $in closes.
     *
     * @param resource $in
     * @param resource $out
     * @param (\Closure(string): SessionHub)|null $hubFor null builds the
     *        launch's workspace for the session's root ({@see hubFor()})
     */
    public static function serve($in, $out, LoopInterface $loop, ?\Closure $hubFor = null): int
    {
        // Everything PHP itself would print goes to stderr: chunk size 1
        // flushes each write through the callback as it is made.
        \ob_start(static function (string $buffer): string {
            self::stderr($buffer);

            return '';
        }, 1);

        $server = AcpServer::new(
            $hubFor ?? self::hubFor(...),
            static function (string $line) use ($out): void {
                self::writeAll($out, $line . "\n");
            },
            Help::versionString(),
        );

        $handlers = [];
        $stopped = false;
        $stop = static function () use (&$stopped, &$handlers, $server, $loop): void {
            if ($stopped) {
                return;
            }
            $stopped = true;
            $server->stop();
            foreach ($handlers as $signal => $handler) {
                $loop->removeSignal($signal, $handler);
            }
            $loop->stop();
        };

        $buffer = '';
        $stream = new ReadableResourceStream($in, $loop);
        $stream->on('data', static function (string $chunk) use (&$buffer, $server): void {
            $buffer .= $chunk;
            while (($newline = \strpos($buffer, "\n")) !== false) {
                $line = \substr($buffer, 0, $newline);
                $buffer = \substr($buffer, $newline + 1);
                $server->receive($line);
            }
            if (\strlen($buffer) > self::MAX_LINE_BYTES) {
                $buffer = '';
                $server->receive('{');
            }
        });
        $stream->on('close', $stop);

        if (\function_exists('pcntl_signal')) {
            foreach ([\SIGINT, \SIGTERM] as $signal) {
                $handlers[$signal] = $stop;
                $loop->addSignal($signal, $stop);
            }
        }

        $server->start($loop);
        $loop->run();
        $stop();
        \ob_end_flush();

        return NonInteractive::EXIT_OK;
    }

    /**
     * The launch's workspace for the session's project root — the same bundle
     * a TUI session and `serve` are built on ({@see Bootstrap::workspace()}),
     * its launch warnings said on stderr — and the hub over it.
     */
    public static function hubFor(string $root): SessionHub
    {
        $workspace = Bootstrap::workspace($root);
        foreach (Bootstrap::launchNotices() as $notice) {
            self::stderr('sugarcrush acp: ' . $notice . "\n");
        }

        return SessionHub::new($workspace, SessionHub::DEFAULT_MAX_OPEN, CompactorConfig::fromSettings(Bootstrap::readUserConfig()));
    }

    /**
     * The verb's one stderr funnel: whatever PHP would have printed to the
     * wire, and the launch warnings. Stderr alone — stdout is the protocol,
     * and an editor shows an agent's stderr as its log.
     */
    private static function stderr(string $text): void
    {
        if ($text !== '') {
            \fwrite(\STDERR, $text);
        }
    }

    /**
     * Write all of $bytes. The editor's stdin may be the same socket as
     * ours, which the read side made non-blocking, so a write can be short:
     * wait until the pipe drains and write the rest rather than lose the
     * tail of a message.
     *
     * @param resource $out
     */
    private static function writeAll($out, string $bytes): void
    {
        while ($bytes !== '') {
            $written = @\fwrite($out, $bytes);
            if ($written === false) {
                return;
            }
            if ($written === 0) {
                $read = null;
                $except = null;
                $write = [$out];
                if (@\stream_select($read, $write, $except, 1) === false) {
                    return;
                }
                continue;
            }
            $bytes = \substr($bytes, $written);
        }
        @\fflush($out);
    }
}
