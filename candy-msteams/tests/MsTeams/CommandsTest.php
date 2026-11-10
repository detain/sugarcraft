<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Commands;
use PHPUnit\Framework\TestCase;

/**
 * The Teams text-command leg (§7.6 roster, §8.4 fallback law): the shared
 * vocabulary arrives bare or <at>-prefixed; anything outside the roster is
 * ordinary text.
 */
final class CommandsTest extends TestCase
{
    public function testTheRosterIsTheSharedVocabulary(): void
    {
        self::assertSame(['new', 'stop', 'approve', 'queue', 'model', 'pair'], Commands::ROSTER);
    }

    public function testBareCommandsParseWithTheirArgs(): void
    {
        self::assertSame(['name' => 'new', 'args' => []], Commands::parseText('/new'));
        self::assertSame(
            ['name' => 'approve', 'args' => ['ask_1', 'ALWAYS']],
            Commands::parseText('  /approve ask_1 ALWAYS  '),
        );
        self::assertSame(['name' => 'queue', 'args' => ['two', 'words']], Commands::parseText('/queue two words'));
    }

    public function testAtPrefixedMentionsStillParse(): void
    {
        self::assertSame(
            ['name' => 'new', 'args' => ['side-project']],
            Commands::parseText('<at>candy-code</at>/new side-project'),
        );
        self::assertSame(
            ['name' => 'stop', 'args' => []],
            Commands::parseText("<at>a</at> <at>b</at>\n/stop"),
        );
    }

    public function testNonCommandsAreOrdinaryText(): void
    {
        self::assertNull(Commands::parseText('hello'));
        self::assertNull(Commands::parseText('/unknown'));
        self::assertNull(Commands::parseText(''));
        self::assertNull(Commands::parseText('/'));
        self::assertNull(Commands::parseText('say /new now'));
    }
}
