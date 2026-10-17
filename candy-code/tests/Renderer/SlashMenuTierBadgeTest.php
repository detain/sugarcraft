<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Renderer;

/**
 * ISS-036's display half: the "/" popup badges every file-based row with its
 * tier — `[user]` / `[project]` — so the operator can SEE which file a name
 * resolves to before pressing Enter, and a built-in row carries no badge.
 *
 * WHY A PIN AND NOT A SIGHT-SEEING TRIP: `CommandSpec::tierBadge()` shipped
 * with the override gate but nothing rendered it, so COMMANDS.md's promise
 * that the popup "can show which file is about to run" named a row the popup
 * never painted — the same half-story as the un-drained launch report this
 * lane closes beside it. The badge is also the only place a user-tier shadow
 * of a built-in is distinguishable from the built-in it replaced at the exact
 * moment of choosing, so the row budget matters: the description tail, not
 * the badge, is what the width math has to cut.
 *
 * Every needle is badge-bearing or popup-only text, never a bare name: the
 * INPUT row echoes what was typed, so `'/compact'` alone would match two
 * rows and count nothing.
 */
final class SlashMenuTierBadgeTest extends TestCase
{
    /** @param array<string, CommandSpec> $commands */
    private function frame(array $commands, string $buf, int $cols = 160): string
    {
        return Renderer::render((new Chat(
            backend: new EchoBackend(),
            inputBuf: $buf,
            customCommands: $commands,
        ))->withSize($cols, 40));
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
        self::assertCount(1, $rows, "exactly one frame row must carry '$needle'");

        return $rows[0];
    }

    private function fileSpec(string $name, string $tier): CommandSpec
    {
        return CommandSpec::new(
            name: $name,
            description: 'a file row',
            category: 'Custom',
            template: 'body',
            tier: $tier,
        );
    }

    public function testAUserTierRowIsBadgedRightBehindItsNameIncludingAnOverrideOfABuiltIn(): void
    {
        // '/compact' is the load-bearing shape: the row the popup shows for a
        // name that used to be a built-in now says whose file it is.
        $row = $this->rowContaining(
            $this->frame(['compact' => $this->fileSpec('compact', 'user')], '/comp'),
            '/compact [user]',
        );
        self::assertStringContainsString('a file row', $row, 'the description still rides the same row');
    }

    public function testAProjectTierRowIsBadgedWithProject(): void
    {
        $row = $this->rowContaining(
            $this->frame(['deploy' => $this->fileSpec('deploy', 'project')], '/dep'),
            '/deploy [project]',
        );
        self::assertStringContainsString('a file row', $row);
    }

    public function testABuiltInRowCarriesNoBadge(): void
    {
        // '/he' can only match the popup's /help row — the input echoes '/he'.
        $row = $this->rowContaining($this->frame([], '/he'), '/help');
        self::assertStringNotContainsString('[', $row, 'a row with no file behind it must not claim one');
    }

    public function testWidthPressureCutsTheDescriptionNotTheBadge(): void
    {
        $long = CommandSpec::new(
            name: 'compact',
            description: 'a file row whose description is far too long to fit beside a badge on this width',
            category: 'Custom',
            template: 'body',
            tier: 'user',
        );

        $row = $this->rowContaining($this->frame(['compact' => $long], '/comp', 48), '/compact [user]');
        self::assertStringNotContainsString('this width', $row, 'the description should have lost the tail to the clip');
    }
}
