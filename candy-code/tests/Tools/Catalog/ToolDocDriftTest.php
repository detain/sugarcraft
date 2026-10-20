<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Catalog;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Catalog\ToolCatalog;
use SugarCraft\Code\Tools\Catalog\ToolDocGenerator;

/**
 * The documentation derived from the tool catalog is what
 * `tools/gen-tool-docs.php --write` would produce.
 */
final class ToolDocDriftTest extends TestCase
{
    private static function package(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
    private static function pages(): array
    {
        $pages = [];
        foreach (ToolDocGenerator::new()->targets() as $file) {
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
            ToolDocGenerator::new()->drift(self::pages()),
            'tool docs are stale — run `php tools/gen-tool-docs.php --write` from candy-code/',
        );
    }

    public function testRegeneratingIsIdempotent(): void
    {
        $generator = ToolDocGenerator::new();
        $once = $generator->rendered(self::pages());

        self::assertSame($once, $generator->rendered($once));
    }

    public function testTheReadmeRosterNamesEveryPlainLaunchBuiltTool(): void
    {
        // MCP-7: the roster is the plain-launch set. `mcp_list`/`mcp_call` are
        // classified by {@see ToolCatalog::CONDITIONAL_BUILDS} and still ride
        // the ceiling sentence (`tools:others`), which this file's count-anchor
        // tests keep honest — the roster only names what a bare launch ships.
        $readme = (string) file_get_contents(self::package() . '/README.md');
        self::assertSame(1, preg_match('/<!-- tools:roster:begin -->(.*?)These are \*\*runtime tool names\*\*/s', $readme, $m));
        preg_match_all('/`([A-Za-z_]+)`/', (string) preg_replace('/\([^)]*\)/', '', $m[1]), $names);

        self::assertSame(array_map(static fn ($e): string => $e->name, ToolCatalog::builtUnconditionally()), $names[1]);
    }

    public function testAMissingPageAndAMissingMarkerAreRefused(): void
    {
        try {
            ToolDocGenerator::new()->rendered([ToolDocGenerator::README => '']);
            self::fail('a missing page was accepted');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\RuntimeException::class);
        ToolDocGenerator::replaceBlock('no markers here', 'roster', 'x');
    }

    public function testACountAnchorKeepsTheCapitalisationOfTheWordItReplaces(): void
    {
        self::assertSame('"Thirteen tools" means', ToolDocGenerator::withCount('"Twelve tools" means', '/"(\w+) tools"/', 13));
        self::assertSame('all twenty tools', ToolDocGenerator::withCount('all eleven tools', '/all (\w+) tools/', 20));
    }
}
