<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\LedgerCommand;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

final class LedgerCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/ledger-cmd-' . bin2hex(random_bytes(5));
        $this->useHomeSandbox($this->home . '/home');
        putenv(PruningMode::ENV);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    public function testAChangedLedgerIsSavedAndTheReplyIsEchoedUiOnly(): void
    {
        $context = CommandContext::new();
        $before = $context->contextLedger();
        $after = $before->withMode(PruningMode::Off);

        $result = LedgerCommand::respond($context, '/pruning off', $before, $after, 'ok');

        self::assertSame('/pruning off', $result->rows[0]->content);
        self::assertSame('ok', $result->rows[1]->content);
        self::assertTrue($result->rows[1]->uiOnly);
        self::assertSame(PruningMode::Off, $context->contextLedger()->mode, 'the new ledger is what the session now holds');
    }

    public function testTheSameLedgerInstanceIsNotRewritten(): void
    {
        $context = CommandContext::new();
        $before = $context->contextLedger();
        $context->saveContextLedger($before->withMode(PruningMode::Manual));
        $held = $context->contextLedger();

        LedgerCommand::respond($context, '/x', $held, $held, 'same');

        self::assertSame(PruningMode::Manual, $context->contextLedger()->mode);
        self::assertInstanceOf(ContextLedger::class, $held);
    }
}
