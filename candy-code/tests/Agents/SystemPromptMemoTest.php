<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\SystemPromptMemo;
use SugarCraft\Code\Context\EnvironmentBlock;

final class SystemPromptMemoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        SystemPromptMemo::reset();
        $this->dir = sys_get_temp_dir() . '/spm-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->git('init -q');
        $this->git('config user.email t@t && git config user.name t');
        file_put_contents($this->dir . '/a.txt', "1\n");
        $this->git('add a.txt && git commit -qm one');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        SystemPromptMemo::reset();
    }

    private function git(string $args): void
    {
        exec('cd ' . escapeshellarg($this->dir) . ' && git ' . $args . ' 2>&1');
    }

    private function block(bool $write): EnvironmentBlock
    {
        return EnvironmentBlock::capture($this->dir, 'm')->withWriteSinceLastRender($write);
    }

    public function testUnchangedRepoWithNoWriteIsOneRenderPass(): void
    {
        $a = SystemPromptMemo::render($this->block(false));
        $b = SystemPromptMemo::render($this->block(false));

        self::assertSame($a, $b);
        self::assertSame(['renders' => 1, 'hits' => 1], SystemPromptMemo::stats());
    }

    public function testStatusChangeInvalidates(): void
    {
        SystemPromptMemo::render($this->block(false));
        file_put_contents($this->dir . '/b.txt', "x\n");
        $after = SystemPromptMemo::render($this->block(false));

        self::assertStringContainsString('b.txt', $after);
        self::assertSame(2, SystemPromptMemo::stats()['renders']);
    }

    public function testNewCommitInvalidates(): void
    {
        SystemPromptMemo::render($this->block(false));
        file_put_contents($this->dir . '/a.txt', "2\n");
        $this->git('commit -qam two');
        $after = SystemPromptMemo::render($this->block(false));

        self::assertStringContainsString(' two', $after);
        self::assertSame(2, SystemPromptMemo::stats()['renders']);
    }

    public function testRenderCarryingDiffsIsNeverServedFromTheMemo(): void
    {
        SystemPromptMemo::render($this->block(true));
        SystemPromptMemo::render($this->block(true));

        self::assertSame(['renders' => 2, 'hits' => 0], SystemPromptMemo::stats());
    }

    public function testNonRepoDirectoryBypassesTheMemo(): void
    {
        $plain = sys_get_temp_dir() . '/spm-plain-' . bin2hex(random_bytes(4));
        mkdir($plain);
        try {
            $b = EnvironmentBlock::capture($plain, 'm')->withWriteSinceLastRender(false);
            SystemPromptMemo::render($b);
            SystemPromptMemo::render($b);
            self::assertSame(0, SystemPromptMemo::stats()['hits']);
        } finally {
            rmdir($plain);
        }
    }
}
