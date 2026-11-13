<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\AgentStatusCommands;
use SugarCraft\Code\Lang;

/** S-H05: the five whole-sentence answers of AgentStatusCommands flow through the catalogue. */
final class AgentStatusCommandsLangTest extends TestCase
{
    public function testNoScopeTreeAnswerIsLocalised(): void
    {
        $out = Lang::inLocale('de', static fn (): string => AgentStatusCommands::tree(null));
        self::assertStringContainsString('Keine Run-Karten', $out);
    }

    public function testHandoffUnknownInterpolatesTheAgentAndLocalises(): void
    {
        $out = Lang::inLocale('de', static fn (): string => AgentStatusCommands::handoff(
            'ghost-h05',
            sys_get_temp_dir() . '/no-such-repo-h05',
            false,
        ));
        self::assertStringContainsString('ghost-h05', $out);
        self::assertStringContainsString('Worktree', $out);
        self::assertStringNotContainsString('No worktree is on record', $out);
    }

    public function testEmptyTreeAndEmptyCapabilitiesAreLocalised(): void
    {
        $caps = Lang::inLocale('fr', static fn (): string => AgentStatusCommands::capabilities(
            null,
            [],
            null,
            false,
            false,
            sys_get_temp_dir() . '/no-such-cards-h05-' . getmypid(),
        ));
        self::assertStringContainsString('Aucun manifeste', $caps);
    }

    public function testEnglishStaysByteIdentical(): void
    {
        self::assertSame(
            'No run cards to show: this session has no id to look its delegated runs up under.',
            Lang::inLocale('en', static fn (): string => AgentStatusCommands::tree('')),
        );
    }
}
