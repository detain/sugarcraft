<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Message;

/**
 * 1021 v1:F-2 — one-shot TEXT mode must not echo raw control bytes.
 *
 * THE DEFECT: `--output-format json` escaped the answer's control bytes by
 * accident of json_encode, while the default TEXT arm returned
 * `$message->content` verbatim — a model or a command-tier stderr tail
 * carrying SGR/OSC sequences or stray C0 bytes sprayed them onto the
 * caller's terminal (and into whatever pipe consumed stdout).
 *
 * THE FIX UNDER TEST: the TEXT arm routes through Ansi::strip (every escape
 * sequence, lone ESC included) plus a C0/DEL sweep that spares \t, \n and
 * \r. The JSON arm is pinned UNCHANGED deliberately: it must keep escaping
 * rather than stripping, so a machine consumer still sees the answer the
 * model produced, and plain answers must survive byte-identically — the
 * sweep is a control-byte filter, not a content filter.
 */
final class NonInteractiveTextSanitizationTest extends TestCase
{
    private const FILTHY = "\x1b[31mred\x1b[0m bell\x07 nul\x00 keep\ttab\nnewline\r tail\x1b]8;;http://evil\x07gone";

    public function testTextFormatStripsSequencesAndControlBytesButKeepsLayoutAndText(): void
    {
        $rendered = NonInteractive::format(Message::assistant(self::FILTHY), NonInteractive::FORMAT_TEXT);

        $this->assertStringNotContainsString("\x1b", $rendered, 'no ESC may survive the text arm');
        $this->assertStringNotContainsString("\x07", $rendered);
        $this->assertStringNotContainsString("\x00", $rendered);
        $this->assertStringContainsString('red', $rendered, 'visible text must survive — this is a control filter, not a censor');
        $this->assertStringContainsString("keep\ttab\nnewline\r", $rendered, '\t \n \r are layout, not noise');
    }

    public function testPlainTextAnswersSurviveByteIdentically(): void
    {
        $plain = "Some **markdown** answer:\n  - one\n  - two\n\nEnd.";

        $this->assertSame(
            $plain,
            NonInteractive::format(Message::assistant($plain), NonInteractive::FORMAT_TEXT),
            'the sweep must be a no-op on ordinary answers',
        );
    }

    public function testJsonArmKeepsEscapingRatherThanStripping(): void
    {
        $rendered = NonInteractive::format(Message::assistant(self::FILTHY), NonInteractive::FORMAT_JSON);

        $decoded = json_decode($rendered, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            self::FILTHY,
            $decoded['result'],
            'the JSON arm carries the answer the model produced (C0 arrives escaped, not deleted)',
        );
        $this->assertStringNotContainsString("\x1b", $rendered, 'encoded bytes stay escape-free on the wire');
    }

    public function testTheSanitizerIsIdempotent(): void
    {
        $once = NonInteractive::format(Message::assistant(self::FILTHY), NonInteractive::FORMAT_TEXT);
        $twice = NonInteractive::format(Message::assistant($once), NonInteractive::FORMAT_TEXT);

        $this->assertSame($once, $twice);
    }
}
