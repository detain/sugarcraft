<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandLoader;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Audit 15b-16, render half: the "/" popup is joined into the frame without
 * passing through the transcript's untrusted-text boundary, so every byte of a
 * command's name, description and argument hint goes to the terminal as is.
 * A cloned repository's `.sugar-crush/commands/*.md` used that to put an OSC 52
 * clipboard write and a `\e[2J` screen clear on the wire as soon as the user
 * typed "/" - no trust prompt, no command run.
 *
 * Two routes in, both pinned:
 *
 * - a REAL project command file read by {@see CommandLoader}, the attack as
 *   the audit reproduced it;
 * - a spec built with {@see CommandSpec::new()}, which never sees
 *   `fromFile()`'s load-boundary sanitizer - the render site has to hold the
 *   line on its own for that one.
 *
 * The frame legitimately carries SGR styling (`\e[…m`), so the assertions name
 * the dangerous sequences rather than banning ESC outright.
 */
final class SlashMenuCommandTextSanitizationTest extends TestCase
{
    use HomeSandboxTrait;

    private const HOSTILE_DESCRIPTION = "start \e]52;c;eA==\x07 mid \e[2J\e[H x\r\ny \u{009B}2J end";

    private const HOSTILE_HINT = "<arg\e]52;c;eA==\x07\r\n\u{009B}tail>";

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-slash-sanitize-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/project/.sugar-crush/commands', 0700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    /** @param array<string, CommandSpec> $commands */
    private function chat(array $commands, string $buf, int $cols = 160): Chat
    {
        return (new Chat(
            backend: new EchoBackend(),
            inputBuf: $buf,
            customCommands: $commands,
        ))->withSize($cols, 40);
    }

    /** @return array<string, CommandSpec> */
    private function hostileProjectCommands(): array
    {
        // YAML double-quoted escapes: the parser turns these into the real
        // ESC/BEL/CR/LF/C1 bytes, exactly as a hostile repository would ship.
        file_put_contents(
            $this->sandbox . '/project/.sugar-crush/commands/evil.md',
            "---\n"
            . 'description: "start \e]52;c;eA==\a mid \e[2J\e[H x\r\ny \u009b2J end"' . "\n"
            . 'argument-hint: "<arg\e]52;c;eA==\a\r\n\u009btail>"' . "\n"
            . "---\nbody\n",
        );

        $commands = (new CommandLoader(false))->loadProjectCommands($this->sandbox . '/project');
        $this->assertArrayHasKey('evil', $commands, 'fixture: the project command must load');

        return $commands;
    }

    private function assertFrameSafe(string $frame, string $label): void
    {
        $this->assertStringNotContainsString("\e]", $frame, "$label: an OSC sequence (clipboard write) reached the frame");
        $this->assertStringNotContainsString(']52;', $frame, "$label: the OSC 52 payload reached the frame");
        $this->assertStringNotContainsString("\e[2J", $frame, "$label: a screen clear reached the frame");
        $this->assertStringNotContainsString("\e[H", $frame, "$label: a cursor-home reached the frame");
        $this->assertStringNotContainsString("\r", $frame, "$label: a CR reached the frame");
        $this->assertStringNotContainsString("\x07", $frame, "$label: a BEL reached the frame");
        $this->assertStringNotContainsString("\u{009B}", $frame, "$label: a C1 CSI reached the frame");

        // Anything that is still an escape must be plain SGR styling.
        $withoutSgr = (string) preg_replace('/\e\[[0-9;:]*m/', '', $frame);
        $this->assertStringNotContainsString("\e", $withoutSgr, "$label: a non-SGR escape reached the frame");
    }

    /** The one frame row that carries $needle, ANSI-stripped. */
    private function rowContaining(string $frame, string $needle): string
    {
        $rows = [];
        foreach (explode("\n", $frame) as $line) {
            $plain = (string) preg_replace('/\e\[[0-9;:]*m/', '', $line);
            if (str_contains($plain, $needle)) {
                $rows[] = $plain;
            }
        }
        $this->assertCount(1, $rows, "exactly one frame row must carry '$needle'");

        return $rows[0];
    }

    public function testAHostileProjectCommandFileCannotWriteEscapesThroughTheSlashPopup(): void
    {
        $frame = Renderer::render($this->chat($this->hostileProjectCommands(), '/ev'));

        $this->assertFrameSafe($frame, 'slash popup');

        // The popup row is ONE line: the description's CRLF did not split it,
        // so the text either side of it shares the row with the command name.
        $row = $this->rowContaining($frame, '/evil');
        $this->assertStringContainsString('start', $row);
        $this->assertStringContainsString('end', $row);
        $this->assertStringContainsString('<arg', $row);
        $this->assertStringContainsString('tail>', $row);
    }

    /**
     * Defence in depth: a spec that never went through `fromFile()` - any
     * in-process caller of {@see CommandSpec::new()} - still renders clean,
     * because the popup sanitizes every piece of the row itself.
     */
    public function testASpecBuiltWithoutTheFileBoundaryIsStillSanitizedByThePopup(): void
    {
        $spec = CommandSpec::new(
            name: 'evil',
            description: self::HOSTILE_DESCRIPTION,
            category: 'Custom',
            argumentHint: self::HOSTILE_HINT,
        );

        $frame = Renderer::render($this->chat(['evil' => $spec], '/ev'));

        $this->assertFrameSafe($frame, 'slash popup (CommandSpec::new)');
        $row = $this->rowContaining($frame, '/evil');
        $this->assertStringContainsString('start', $row);
        $this->assertStringContainsString('end', $row);
    }

    /**
     * The sibling surface: `/help` lists the same three fields in a column
     * layout that a CR/LF would break apart, and its listing is a transcript
     * message the frame then renders.
     */
    public function testTheHelpListingRendersAHostileSpecOnOneCleanRow(): void
    {
        $spec = CommandSpec::new(
            name: 'evil',
            description: self::HOSTILE_DESCRIPTION,
            category: 'Custom',
            argumentHint: self::HOSTILE_HINT,
        );

        [$next] = $this->chat(['evil' => $spec], '/help', 200)->update(new KeyMsg(KeyType::Enter));
        $history = $next->history;
        $listing = end($history)->content;

        $this->assertFrameSafe($listing, '/help listing');
        $evilRows = array_values(array_filter(
            explode("\n", $listing),
            static fn(string $line): bool => str_contains($line, '/evil'),
        ));
        $this->assertCount(1, $evilRows, 'the /evil entry must be one listing row');
        $this->assertStringContainsString('start', $evilRows[0]);
        $this->assertStringContainsString('end', $evilRows[0]);

        $this->assertFrameSafe(Renderer::render($next->withSize(200, 80)), '/help frame');
    }
}
