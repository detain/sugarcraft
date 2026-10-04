<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Compaction;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Compaction\FilesTouched;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 2.5: the files a compaction's state block lists are read off the
 * tool rows, never off what a model says it touched.
 */
final class FilesTouchedTest extends TestCase
{
    public function testSuccessfulReadsAndWritesAreCollectedInFirstSeenOrderOnce(): void
    {
        $history = [
            Message::user('go'),
            Message::assistant('a')->withToolResults([
                new ToolResult('Read', 'x', arguments: ['file_path' => 'b.php']),
                new ToolResult('Read', 'x', arguments: ['file_path' => 'a.php']),
                new ToolResult('Read', 'x', arguments: ['file_path' => 'b.php']),
            ]),
            Message::assistant('b')->withToolResults([
                new ToolResult('Edit', 'ok', arguments: ['file_path' => 'c.php']),
                new ToolResult('Write', 'ok', arguments: ['file_path' => 'c.php']),
            ]),
        ];

        $files = FilesTouched::fromHistory($history);

        $this->assertSame(['b.php', 'a.php'], $files->read);
        $this->assertSame(['c.php'], $files->modified);
        $this->assertFalse($files->isEmpty());
    }

    public function testAFailedCallAndAToolWithNoPathTouchNothing(): void
    {
        $files = FilesTouched::new()
            ->withResult(new ToolResult('Edit', '', 'old_string not found', arguments: ['file_path' => 'x.php']))
            ->withResult(new ToolResult('Bash', 'ok', arguments: ['command' => 'sed -i s/a/b/ y.php']))
            ->withResult(new ToolResult('Read', 'x', arguments: []))
            ->withResult(new ToolResult('Read', 'x', arguments: ['file_path' => '   ']));

        $this->assertTrue($files->isEmpty(), 'a refused edit changed nothing, and a shell command is never parsed for paths');
    }

    public function testAFileModifiedAfterItWasReadIsListedAsModifiedOnly(): void
    {
        $files = FilesTouched::new()->withRead('a.php')->withModified('a.php')->withRead('a.php');

        $this->assertSame([], $files->read);
        $this->assertSame(['a.php'], $files->modified);
    }

    public function testMergedWithUnionsBothSetsAndModifiedWins(): void
    {
        $merged = FilesTouched::new()->withRead('a.php')->withModified('b.php')
            ->mergedWith(FilesTouched::new()->withRead('c.php')->withModified('a.php'));

        $this->assertSame(['c.php'], $merged->read);
        $this->assertSame(['b.php', 'a.php'], $merged->modified);
    }
}
