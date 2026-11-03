<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GifView\Cli;

/**
 * Control-byte hygiene for every echo the viewer makes about EXTERNAL text.
 *
 * The channel this family was born from (REVIEW-1 M1): the rescue catch wraps
 * the engine's message into `cli.cannot_play`, and the engine's own
 * `decoder.no_file` string interpolates the RAW path — so a hostile filename
 * like "a\x1b[2Jb\x07c.gif" rode the relay verbatim to the terminal even
 * though the app's own `{path}` slot was already sanitized. Same family:
 * `cli.bad_flag`/`bad_value`/`bad_preset`/`too_many` echo argv tokens the
 * user (or a script, or a completion of a hostile name) chose. One choke
 * (`Cli::printable()`) now covers all of them; these pins keep it that way.
 *
 * The bytes under test are asserted at HEX level: no C0 control (the message
 * terminator \n excepted) and no DEL survives in any echoed line.
 */
final class ControlCharHygieneTest extends TestCase
{
    /** Any C0 byte except \n, plus DEL — the exact set printable() replaces. */
    private const FORBIDDEN_BYTES = '/[\x00-\x09\x0B-\x1F\x7F]/';

    /** @var list<string> */
    private array $artifacts = [];

    protected function tearDown(): void
    {
        foreach ($this->artifacts as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->artifacts = [];
    }

    /**
     * The M1 channel itself: an engine failure whose message embeds the raw
     * hostile path (exactly the decoder.no_file shape) must land on stderr
     * with every control byte neutralised — wording intact, bytes defused.
     */
    public function testTheCannotPlayRelayNeutralisesTheRawPathInsideTheEngineMessage(): void
    {
        $hostile = "/tmp/a\x1b[2Jb\x07c\r\x7f.gif";
        $engineMessage = 'sugar-animation: no such file: ' . $hostile;

        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $stdout = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);

        $play = static function (): int {
            self::fail('the play seam must never run after a decode failure');
        };
        $decode = static function () use ($engineMessage): array {
            throw new \RuntimeException($engineMessage);
        };

        $exit = Cli::main(['candy-gifview', 'x.gif'], $stdout, $stderr, $play, null, $decode);

        rewind($stderr);
        $err = (string) stream_get_contents($stderr);
        fclose($stderr);
        fclose($stdout);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('could not play', $err);
        // The Wording survives, the bytes do not: ESC/BEL/CR/DEL each became '?'.
        $this->assertStringContainsString('no such file: /tmp/a?[2Jb?c??.gif', $err);
        // The app's brand wrapped the relay, and the engine brand was stripped.
        $this->assertStringContainsString('candy-gifview: could not play', $err);
        $this->assertStringNotContainsString('sugar-animation:', $err);
        $this->assertSame(
            0,
            preg_match(self::FORBIDDEN_BYTES, $err),
            'the cannot_play relay emitted a raw control byte — the printable() choke came off',
        );
    }

    /**
     * Hostile argv tokens echoed by the intake refusals (the same family the
     * review listed alongside the relay): each refusal keeps its wording and
     * loses the smuggled bytes. These paths run BEFORE the gd preflight, so
     * they are behaviourally pinned on every host.
     */
    public function testEveryArgvEchoRefusalNeutralisesControlBytes(): void
    {
        /** @var array<string, array{0: list<string>, 1: string}> label => [argv, expected sanitized echo] */
        $cases = [
            'bad_value keeps --fps payload readable' => [['candy-gifview', '--fps=x' . "\x1b" . 'y'], 'x?y'],
            'bad_preset keeps the token readable' => [['candy-gifview', 'a.gif', 'sol' . "\x1b" . 'id'], 'sol?id'],
            'too_many keeps the surplus arg readable' => [['candy-gifview', 'a.gif', 'solid', 'ex' . "\x07" . 'tra'], 'ex?tra'],
            'bad_flag keeps the unknown long flag readable' => [['candy-gifview', '--fo' . "\x07" . 'o=1'], 'fo?o'],
        ];

        foreach ($cases as $label => [$argv, $expectedEcho]) {
            $stderr = fopen('php://memory', 'w+');
            self::assertIsResource($stderr, $label);
            $stdout = fopen('php://memory', 'w+');
            self::assertIsResource($stdout, $label);

            $exit = Cli::main($argv, $stdout, $stderr);

            rewind($stderr);
            $err = (string) stream_get_contents($stderr);
            rewind($stdout);
            $out = (string) stream_get_contents($stdout);
            fclose($stderr);
            fclose($stdout);

            $this->assertSame(1, $exit, $label);
            $this->assertStringContainsString($expectedEcho, $err, $label);
            $this->assertSame(
                0,
                preg_match(self::FORBIDDEN_BYTES, $err),
                "$label: refusal echoed a raw control byte",
            );
            // usage lines ride bad_flag/bad_preset refusals: clean too.
            $this->assertSame(0, preg_match(self::FORBIDDEN_BYTES, $out), $label);
        }
    }

    /**
     * End-to-end through the DEFAULT intake (no seam): a hostile, non-existent
     * filename drives the real engine's decoder.no_file into the relay, so the
     * pin covers the exact production path the review reproduced. Gated on
     * ext-gd because the preflight refuses (differently) without it — and the
     * preflight itself is pinned by GdGuardPolarityTest.
     */
    public function testAHostileFileNameIsNeutralisedThroughTheRealDecodePath(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('without ext-gd the intake refuses at the preflight, not at the relay');
        }

        $base = tempnam(sys_get_temp_dir(), 'gv-hostile-');
        $this->assertIsString($base);
        $this->artifacts[] = $base; // tempnam creates it; the hostile full path never exists
        $hostile = $base . "\x1b]0;pwn\x07" . '.gif';

        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $stdout = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);

        $exit = Cli::main(['candy-gifview', $hostile], $stdout, $stderr);

        rewind($stderr);
        $err = (string) stream_get_contents($stderr);
        fclose($stderr);
        fclose($stdout);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('could not play', $err);
        // OSC introducer (ESC ] ... BEL) fully defused — no raw 0x1b/0x07 anywhere.
        $this->assertStringNotContainsString("\x1b", $err);
        $this->assertStringNotContainsString("\x07", $err);
        $this->assertSame(0, preg_match(self::FORBIDDEN_BYTES, $err));
        // The path is still recognisable (both the {path} slot and the relayed
        // engine copy wear the same '?' mask).
        $this->assertMatchesRegularExpression('/\?\]0;pwn\?\.gif/', $err);
    }

    /**
     * Structural belt: the relayed reason must pass the choke at the echo
     * site (not merely behave today), and the choke itself must keep
     * replacing C0+DEL — a respell of the character class is the quiet way
     * this family regrows.
     */
    public function testTheRelayChokeIsWiredAtTheEchoSiteAndTheClassStaysWide(): void
    {
        $src = file_get_contents(__DIR__ . '/../src/Cli.php');
        $this->assertIsString($src);

        $this->assertStringContainsString(
            "'reason' => self::printable(\$reason),",
            $src,
            'the cannot_play relay must sanitize the engine reason at the interpolation site',
        );
        $this->assertStringContainsString(
            "'/[\\x00-\\x1F\\x7F]/', '?'",
            $src,
            'printable() must replace the full C0 band and DEL',
        );
    }
}
