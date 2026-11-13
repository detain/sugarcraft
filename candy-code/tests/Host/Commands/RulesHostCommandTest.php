<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\RulesHostCommand;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

final class RulesHostCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rules-host-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/project', 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testListingAnEmptyProjectIsACleanReplyAndLeavesNoBufferOpen(): void
    {
        $level = ob_get_level();

        $result = (new RulesHostCommand())->run(CommandContext::new(root: $this->dir . '/project'), '/rules');

        self::assertSame($level, ob_get_level(), 'the captured output buffer is closed');
        self::assertFalse($result->isRefused());
        self::assertSame('/rules', $result->rows[0]->content);
        self::assertTrue($result->rows[1]->uiOnly);
        self::assertSame(\SugarCraft\Code\Role::Assistant, $result->rows[1]->role);
    }

    public function testToggleOfAPackThatDoesNotExistFailsAsANotice(): void
    {
        $result = (new RulesHostCommand())->run(CommandContext::new(root: $this->dir . '/project'), '/rules no-such-pack');

        self::assertSame(\SugarCraft\Code\Role::System, $result->rows[1]->role, 'a failure is a notice row, not an assistant reply');
        self::assertNotSame('', trim($result->rows[1]->content));
    }

    public function testAProjectRulebookIsListedByName(): void
    {
        mkdir($this->dir . '/project/.candy-code/rules', 0700, true);
        file_put_contents($this->dir . '/project/.candy-code/rules/terse.md', "Be terse.\n");

        $result = (new RulesHostCommand())->run(CommandContext::new(root: $this->dir . '/project'), '/rules');

        self::assertStringContainsString('terse', $result->rows[1]->content);
    }
}
