<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail as AgentPathJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\Support\ToolOutputSpill;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\PathJail;

/**
 * Roadmap 2.8: the one hole in the workspace jail. A saved tool output lives
 * outside every root, the model is told its path, and it must be able to read
 * that path back — with `Read` and `Grep`, and with nothing that writes or
 * walks.
 *
 * Both polarities are asserted for each tool: the spill file passes, and the
 * same tool still refuses an ordinary file sitting right beside the store, so
 * a test that only ever saw "allowed" cannot be passing against a jail that
 * allows everything.
 */
final class PathJailSpillAllowTest extends TestCase
{
    private string $sandbox;
    private string $root;
    private string $spill;
    private string $neighbour;

    protected function setUp(): void
    {
        $this->sandbox = (string) realpath(sys_get_temp_dir()) . '/sc_jail_spill_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $this->root = $this->sandbox . '/workspace';
        self::assertTrue(mkdir($this->root, 0o755, true));
        file_put_contents($this->root . '/inside.txt', "inside\n");

        ToolOutputSpill::useDirectoryForTesting($this->sandbox . '/store');
        $lines = [];
        for ($i = 1; $i <= 300; $i++) {
            $lines[] = "spilled line $i" . ($i === 250 ? ' NEEDLE_IN_THE_SPILL' : '');
        }
        $this->spill = (string) ToolOutputSpill::store(implode("\n", $lines) . "\n");
        self::assertFileExists($this->spill);

        $this->neighbour = $this->sandbox . '/out-' . str_repeat('e', 32) . '.txt';
        file_put_contents($this->neighbour, "not handed out by the store\n");
    }

    protected function tearDown(): void
    {
        ToolOutputSpill::useDirectoryForTesting(null);
        self::removeTree($this->sandbox);
    }

    public function testResolveAcceptsASpillFileAndStillRefusesItsNeighbour(): void
    {
        self::assertSame($this->spill, PathJail::resolve($this->root, $this->spill));
        self::assertNull(PathJail::resolve($this->root, $this->neighbour));
        self::assertNull(PathJail::resolve($this->root, '../store/../out-' . str_repeat('e', 32) . '.txt'));
    }

    public function testTheCreateAndWalkMethodsNeverConsultTheAllowList(): void
    {
        self::assertNull(PathJail::resolveForCreate($this->root, $this->spill), 'Write could replace saved output');
        self::assertNull(PathJail::resolveForCreate($this->root, \dirname($this->spill) . '/out-new.txt'));
        self::assertNull(PathJail::resolveDir($this->root, \dirname($this->spill)), 'Glob could walk the store');
    }

    public function testAWorktreeJailDelegatesTheSameAnswer(): void
    {
        $jail = new AgentPathJail($this->root, new PathJailConfig());

        self::assertSame($this->spill, $jail->resolve($this->spill));
        self::assertNull($jail->resolve($this->neighbour));
        self::assertNull($jail->resolveForCreate($this->spill));
    }

    public function testReadPagesThroughASpillFileWithOffsetAndLimit(): void
    {
        $read = new Read($this->root);

        $page = $read->execute(['file_path' => $this->spill, 'offset' => 249, 'limit' => 2]);
        self::assertFalse($page->isError(), $page->content());
        self::assertStringContainsString('NEEDLE_IN_THE_SPILL', $page->content());
        self::assertStringNotContainsString('spilled line 1' . "\n", $page->content());

        $refused = $read->execute(['file_path' => $this->neighbour]);
        self::assertTrue($refused->isError());
        self::assertStringContainsString('outside workspace root', $refused->content());
    }

    public function testASubAgentsReadReachesTheSpillToo(): void
    {
        $read = new Read(null, worktreeJail: new AgentPathJail($this->root, new PathJailConfig()));

        $page = $read->execute(['file_path' => $this->spill, 'offset' => 250, 'limit' => 1]);
        self::assertFalse($page->isError(), $page->content());
        self::assertStringContainsString('NEEDLE_IN_THE_SPILL', $page->content());
    }

    public function testGrepSearchesASpillFileAndStillRefusesItsNeighbour(): void
    {
        $grep = new Grep($this->root);

        $hit = $grep->execute(['pattern' => 'NEEDLE_IN_THE_SPILL', 'path' => $this->spill, 'description' => 'x']);
        self::assertFalse($hit->isError(), $hit->content());
        self::assertStringContainsString('250:spilled line 250 NEEDLE_IN_THE_SPILL', $hit->content());

        $refused = $grep->execute(['pattern' => 'handed', 'path' => $this->neighbour, 'description' => 'x']);
        self::assertTrue($refused->isError());
        self::assertStringContainsString('outside workspace root', $refused->content());
    }

    public function testEditRefusesSavedOutputAndLeavesItUntouched(): void
    {
        $before = (string) file_get_contents($this->spill);

        $result = (new Edit($this->root))->execute([
            'file_path' => $this->spill,
            'old_string' => 'spilled line 1',
            'new_string' => 'tampered',
        ]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('saved tool output and is read-only', $result->content());
        self::assertSame($before, file_get_contents($this->spill));
    }

    public function testWriteAndGlobCannotReachTheStore(): void
    {
        $write = (new Write($this->root))->execute(['file_path' => $this->spill, 'content' => 'overwritten']);
        self::assertTrue($write->isError());
        self::assertStringNotContainsString('overwritten', (string) file_get_contents($this->spill));

        $glob = (new Glob($this->root))->execute(['pattern' => '*.txt', 'path' => \dirname($this->spill)]);
        self::assertTrue($glob->isError(), 'Glob listed the spill store');
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
