<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Tools\BuiltIn\MemoryTool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 5.1-2: the `Memory` tool's five actions over the shared writer.
 */
final class MemoryToolTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

    private string $dir;

    private MemoryStore $store;

    private MemoryTool $tool;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_memtool_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/home', 0o700, true);
        mkdir($this->dir . '/repo', 0o700, true);
        $this->store = new MemoryStore($this->dir . '/home');
        $this->tool = new MemoryTool(MemoryWriter::new($this->store, $this->dir . '/repo'));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmrf($full) : @unlink($full);
        }
        @rmdir($path);
    }

    /** @param array<string, mixed> $args */
    private function call(array $args): ToolResult
    {
        return $this->tool->execute($args);
    }

    private static function savedId(ToolResult $result): string
    {
        self::assertFalse($result->isError(), $result->content());
        self::assertSame(1, preg_match('/^Saved note ([0-9a-f]+) /', $result->content(), $m), $result->content());

        return $m[1];
    }

    public function testTheSchemaNamesEveryAction(): void
    {
        self::assertSame('Memory', $this->tool->name());
        self::assertSame(
            ['view', 'save', 'str_replace', 'delete', 'recall'],
            $this->tool->inputSchema()['properties']['action']['enum'],
        );
        self::assertSame(['action'], $this->tool->inputSchema()['required']);
    }

    public function testSaveThenViewTheNoteInFull(): void
    {
        $long = 'Use make ship to deploy. ' . str_repeat('Then wait for the canary. ', 10) . 'END';
        $id = self::savedId($this->call(['action' => 'save', 'content' => $long, 'type' => 'decision', 'tags' => ['deploy']]));

        $view = $this->call(['action' => 'view', 'id' => $id]);

        self::assertFalse($view->isError());
        self::assertStringContainsString("[decision] {$id} · scope: project · in this repository · tags: deploy", $view->content());
        self::assertStringEndsWith('END', $view->content(), 'view returns the body the index only previews');
    }

    public function testViewWithoutAnIdIsTheIndex(): void
    {
        $this->call(['action' => 'save', 'content' => 'repo note']);
        $this->call(['action' => 'save', 'content' => 'my preference', 'scope' => 'user', 'type' => 'preference']);

        $index = $this->call(['action' => 'view'])->content();

        self::assertStringContainsString('## user scope (home store)', $index);
        self::assertStringContainsString('## project scope (this repository)', $index);
        self::assertLessThan(strpos($index, 'repo note'), strpos($index, 'my preference'));
    }

    public function testStrReplaceEditsOneOccurrence(): void
    {
        $id = self::savedId($this->call(['action' => 'save', 'content' => 'tabs, not spaces', 'scope' => 'user']));

        $result = $this->call(['action' => 'str_replace', 'id' => $id, 'old_str' => 'tabs, not spaces', 'new_str' => 'spaces, not tabs']);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame('spaces, not tabs', $this->store->get($id)?->content());
        self::assertTrue($this->call(['action' => 'str_replace', 'id' => $id, 'old_str' => 'absent', 'new_str' => 'x'])->isError());
    }

    public function testDeleteAndRecall(): void
    {
        $id = self::savedId($this->call(['action' => 'save', 'content' => 'the staging host is blue']));

        self::assertStringContainsString($id, $this->call(['action' => 'recall', 'query' => 'staging'])->content());
        self::assertSame("Deleted note {$id}.", $this->call(['action' => 'delete', 'id' => $id])->content());
        self::assertTrue($this->call(['action' => 'delete', 'id' => $id])->isError());
        self::assertStringStartsWith('No memory note matches', $this->call(['action' => 'recall', 'query' => 'staging'])->content());
    }

    public function testMalformedCallsAreAnsweredAsErrors(): void
    {
        foreach ([
            [],
            ['action' => 'forget'],
            ['action' => 'save'],
            ['action' => 'save', 'content' => 'x', 'scope' => 'agent'],
            ['action' => 'save', 'content' => 'x', 'type' => 'rumour'],
            ['action' => 'save', 'content' => 'x', 'tags' => 'not-a-list'],
            ['action' => 'save', 'content' => str_repeat('x', 8193)],
            ['action' => 'view', 'id' => ['array']],
            ['action' => 'view', 'id' => '../../etc/passwd'],
            ['action' => 'recall'],
        ] as $args) {
            self::assertTrue($this->call($args)->isError(), json_encode($args) ?: '');
        }
    }

    /**
     * Roadmap N-P4d: `memory.projectNoteMaxBytes` is the tool's ceiling too —
     * a raised one is not refused at the default before the writer runs.
     */
    public function testTheNoteCeilingIsTheSettingNotTheConstant(): void
    {
        $this->useHomeSandbox($this->dir . '/sandbox-home');
        try {
            mkdir($this->dir . '/sandbox-home/.sugar-crush', 0o700, true);
            file_put_contents(
                $this->dir . '/sandbox-home/.sugar-crush/config.json',
                json_encode([\SugarCraft\Crush\Context\ProjectMemoryWriter::SETTING_MAX_CONTENT_BYTES => 12_000]),
            );

            self::assertFalse($this->call(['action' => 'save', 'content' => str_repeat('x', 9_000)])->isError(), 'raised past the default');
            $refused = $this->call(['action' => 'save', 'content' => str_repeat('x', 12_001)]);
            self::assertTrue($refused->isError());
            self::assertStringContainsString('12000 bytes', $refused->content());
        } finally {
            $this->restoreHomeSandbox();
        }
    }

    public function testWithoutAWriterTheToolRefuses(): void
    {
        $result = (new MemoryTool())->execute(['action' => 'view']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('no memory store', $result->content());
    }

    public function testAUserNoteSavedByTheToolIsTheOneTheSlashCommandLists(): void
    {
        $id = self::savedId($this->call(['action' => 'save', 'content' => 'shared', 'scope' => 'user']));

        self::assertSame([$id], array_map(static fn ($e): string => $e->id(), $this->store->list(MemoryScope::User)));
    }
}
