<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\PruningCommand;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\HostCommand;
use SugarCraft\Code\Host\Commands\PruningHostCommand;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

final class PruningHostCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/pruning-host-' . bin2hex(random_bytes(5));
        $this->useHomeSandbox($this->home . '/home');
        putenv(PruningMode::ENV);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    public function testItIsAHostCommand(): void
    {
        self::assertInstanceOf(HostCommand::class, new PruningHostCommand());
    }

    public function testTheBareFormReportsTheModeInForceAndChangesNothing(): void
    {
        $context = CommandContext::new();

        $result = (new PruningHostCommand())->run($context, '/pruning');

        self::assertSame(PruningCommand::status($context->contextLedger()), $result->rows[1]->content);
        self::assertNull($context->contextLedger()->mode);
    }

    public function testNamingAModeSetsItOnTheSessionLedger(): void
    {
        $context = CommandContext::new();

        $result = (new PruningHostCommand())->run($context, '/pruning manual');

        self::assertSame(PruningMode::Manual, $context->contextLedger()->mode);
        self::assertStringContainsString('manual', $result->rows[1]->content);
    }

    public function testDefaultClearsTheSessionOverride(): void
    {
        $context = CommandContext::new();
        (new PruningHostCommand())->run($context, '/pruning off');

        (new PruningHostCommand())->run($context, '/pruning default');

        self::assertNull($context->contextLedger()->mode);
    }

    public function testAnUnknownModeAnswersTheUsageLine(): void
    {
        $context = CommandContext::new();

        $result = (new PruningHostCommand())->run($context, '/pruning sideways');

        self::assertSame(PruningCommand::usage(), $result->rows[1]->content);
        self::assertNull($context->contextLedger()->mode);
    }
}
