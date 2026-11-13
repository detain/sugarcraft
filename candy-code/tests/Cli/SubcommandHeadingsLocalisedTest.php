<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;

/**
 * S-L11 (G-13): the seven subcommand headings ride the catalogue. The command
 * name stays a literal token inside every locale's value (it is the command
 * identity); the framing around it is translated, and `en` equals the old
 * literal so output-scraping callers see no change.
 */
final class SubcommandHeadingsLocalisedTest extends TestCase
{
    public function testEnglishHeadingsAreTheOldLiterals(): void
    {
        self::assertSame('candy-code gc status', Lang::t('cli.gc.status_heading'));
        self::assertSame('candy-code gc sessions', Lang::t('cli.gc.sessions_heading'));
        self::assertSame('candy-code gc analyze', Lang::t('cli.gc.analyze_heading'));
        self::assertSame('candy-code gc vacuum [--incremental]', Lang::t('cli.gc.vacuum_heading', ['flags' => ' [--incremental]']));
        self::assertSame('candy-code gc purge', Lang::t('cli.gc.purge_heading', ['flags' => '']));
        self::assertSame('candy-code workflow status: w — done', Lang::t('cli.workflow.status_heading', ['name' => 'w', 'status' => 'done']));
        self::assertSame('candy-code workflow run: w — done (3 stages)', Lang::t('cli.workflow.report_heading', ['action' => 'run', 'name' => 'w', 'status' => 'done', 'stages' => 3]));
    }

    public function testGermanFramingIsTranslatedAroundTheCommandToken(): void
    {
        Lang::inLocale('de', function (): void {
            self::assertSame('candy-code gc status — Zustand', Lang::t('cli.gc.status_heading'));
            self::assertSame('candy-code gc purge [--dry-run] — Bereinigung', Lang::t('cli.gc.purge_heading', ['flags' => ' [--dry-run]']));
            self::assertStringContainsString('3 Stufen', Lang::t('cli.workflow.report_heading', ['action' => 'run', 'name' => 'w', 'status' => 'done', 'stages' => 3]));
        });
    }

    public function testNoHeadingLiteralRemainsInTheSource(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php');

        self::assertDoesNotMatchRegularExpression("/echo 'candy-code (gc|workflow) /", $source);
    }
}
