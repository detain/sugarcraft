<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\Specs\CommandDocGenerator;

/**
 * The generated command docs are byte-equal to what the spec files produce —
 * `php tools/gen-command-docs.php --check`, as a test. A spec file added or
 * changed without re-running `--write` reds here, naming the stale page.
 */
final class CommandDocsDriftTest extends TestCase
{
    private static function package(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    private static function pages(): array
    {
        $pages = [];
        foreach (CommandDocGenerator::new()->targets() as $file) {
            $text = file_get_contents(self::package() . '/' . $file);
            self::assertIsString($text, "{$file} is unreadable");
            $pages[$file] = $text;
        }

        return $pages;
    }

    public function testTheGeneratedDocsAreUpToDate(): void
    {
        self::assertSame(
            [],
            CommandDocGenerator::new()->drift(self::pages()),
            'command docs are stale — run `php tools/gen-command-docs.php --write` from sugar-crush/',
        );
    }

    public function testTheTableNamesEveryRegistryRowOnceInOrder(): void
    {
        $doc = self::pages()[CommandDocGenerator::COMMANDS_DOC];
        self::assertSame(1, preg_match('/<!-- commands:table:begin -->(.*?)<!-- commands:table:end -->/s', $doc, $block));
        preg_match_all('/^\| `\/([a-z-]+)` \|/m', $block[1], $rows);

        self::assertSame(array_map(static fn ($s): string => $s->name, CommandRegistry::all()), $rows[1]);
    }

    public function testAnEscapedPipeStaysInsideItsCell(): void
    {
        self::assertStringContainsString('`[md\|html\|json] [path]`', CommandDocGenerator::new()->commandsTable());
    }

    public function testAMissingMarkerIsRefusedRatherThanAppended(): void
    {
        $this->expectException(\RuntimeException::class);
        CommandDocGenerator::replaceBlock("no markers here\n", 'roster', 'x');
    }

    public function testAMissingPageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandDocGenerator::new()->rendered([CommandDocGenerator::README => '']);
    }

    public function testTheCheckModeAgreesWithTheTest(): void
    {
        $out = [];
        exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(self::package() . '/tools/gen-command-docs.php') . ' --check 2>&1', $out, $code);

        self::assertSame(0, $code, implode("\n", $out));
    }
}
