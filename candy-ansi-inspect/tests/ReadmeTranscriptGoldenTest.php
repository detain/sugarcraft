<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiInspect\Inspector;

/**
 * Pin the README's spelled-out report transcripts to the renderer (step AIP-19).
 *
 * WHY: byte-level fidelity is pinned exhaustively, but the human-facing
 * example lines in README.md ("Docs are pinned by tests") were prose
 * only — a describe() reword used to leave the README quietly lying.
 * Each case below is the exact input one of the README's `printf`
 * examples feeds the tool, and the exact transcript block the README
 * prints. The test asserts both directions: `Inspector::report()`
 * renders the transcript, and the README literally contains it (modulo
 * the fence's trailing newline). Rewording a label, a spacing column,
 * or the docs then reddens whichever side stopped matching.
 */
final class ReadmeTranscriptGoldenTest extends TestCase
{
    /**
     * input bytes => rendered transcript as the README shows it (no
     * trailing newline: the closing fence swallows the last one).
     *
     * @var array<string, string>
     */
    private const TRANSCRIPTS = [
        // $ printf '\e[31mhello\e[0m world\n' | candy-ansi-inspect
        "\x1b[31mhello\x1b[0m world\n" => "ESC[31m  SGR foreground red\n"
            . "hello\n"
            . "ESC[0m  SGR reset\n"
            . " world\n"
            . "LF  C0 LF (line feed)",
        // $ printf '\e]0;new title\e\\' | candy-ansi-inspect
        "\x1b]0;new title\x1b\\" => 'ESC]0;new titleBEL  set window title to "new title"',
        // $ printf '\e[?2026h' | candy-ansi-inspect
        "\x1b[?2026h" => 'ESC[?2026h  enable synchronized output',
        // capture.ansi: printf '\e[31merror: disk full\e[0m\n' > capture.ansi
        "\x1b[31merror: disk full\x1b[0m\n" => "ESC[31m  SGR foreground red\n"
            . "error: disk full\n"
            . "ESC[0m  SGR reset\n"
            . "LF  C0 LF (line feed)",
    ];

    public function testReadmeTranscriptsMatchTheRenderer(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        foreach (self::TRANSCRIPTS as $input => $transcript) {
            $rendered = rtrim(Inspector::report($input), "\n");
            $this->assertSame($transcript, $rendered, 'renderer drifted from the pinned transcript for: ' . $input);
            $this->assertStringContainsString(
                $transcript,
                $readme,
                'README no longer shows the transcript the renderer produces',
            );
        }
    }

    public function testTheOscTranscriptCarriesTheBelTerminatorToken(): void
    {
        // Documentation honesty (finding 059's sharp edge): the OSC line's
        // display column tokenises the BEL the dispatch normalises to, so
        // readers diffing the sample byte-for-byte meet no invisible byte.
        $rendered = Inspector::report("\x1b]0;new title\x1b\\");
        $this->assertStringContainsString('BEL', $rendered);
        $this->assertStringNotContainsString("\x07", $rendered);
    }
}
