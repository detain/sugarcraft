<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandRegistry;

/**
 * The README "Day one" section teaches slash commands to a new user; each
 * backticked `/command` there must be a command the roster really ships
 * (a stale `/resume` was taught here after the command became `/sessions`).
 */
final class ReadmeDayOneCommandDriftTest extends TestCase
{
    public function testEveryDayOneSlashCommandExists(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertSame(1, preg_match('/^## Day one\n(.*?)^## /ms', $readme, $m), 'Day one section not found');

        preg_match_all('/`\/([a-z][a-z0-9-]*)[^`]*`/', $m[1], $found);
        self::assertNotEmpty($found[1]);

        $roster = array_map(static fn($s) => $s->name, CommandRegistry::all());
        foreach (array_unique($found[1]) as $name) {
            self::assertContains($name, $roster, "Day one teaches /{$name}, which is not a registered command");
        }
    }
}
