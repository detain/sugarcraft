<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\SweepCommand;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\SweepHostCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;

final class SweepHostCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/sweep-host-' . bin2hex(random_bytes(5));
        $this->useHomeSandbox($this->home . '/home');
        putenv(PruningMode::ENV);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    /** @return list<Message> */
    private static function history(): array
    {
        $output = str_repeat('a long line of output ', 100);

        return [
            Message::user('read it'),
            Message::assistant('')->withToolCalls([new ToolCall('Read', ['file_path' => 'a.php'], 'c1')])->withStepId('s_a_1')->withUserVisible(false),
            Message::assistant($output)->withToolResults([new ToolResult('Read', $output, null, 'c1')])->withStepId('s_a_1'),
            Message::assistant('done')->withStepId('s_a_2'),
        ];
    }

    private static function reply(CommandContext $context, string $text): string
    {
        return (new SweepHostCommand())->run($context, $text)->rows[1]->content;
    }

    public function testABadArgumentAnswersTheUsageLine(): void
    {
        self::assertSame(SweepCommand::usage(), self::reply(CommandContext::new(), '/sweep lots'));
        self::assertSame(SweepCommand::usage(), self::reply(CommandContext::new(), '/sweep 0'));
    }

    public function testAnEmptyTranscriptHasNothingToSweep(): void
    {
        self::assertSame(Lang::t('cmd.sweep.nothing-since-prompt'), self::reply(CommandContext::new(), '/sweep'));
        self::assertSame(Lang::t('cmd.sweep.nothing-at-all'), self::reply(CommandContext::new(), '/sweep 3'));
    }

    public function testItIsRefusedWhenPruningIsOff(): void
    {
        $context = CommandContext::new(history: self::history());
        $context->saveContextLedger($context->contextLedger()->withMode(PruningMode::Off));

        self::assertSame(Lang::t('cmd.sweep.off'), self::reply($context, '/sweep 1'));
    }

    public function testSweepingPrunesTheOutputAndSavesTheLedger(): void
    {
        $context = CommandContext::new(history: self::history());
        self::assertFalse($context->contextLedger()->isPruned('c1'));

        $reply = self::reply($context, '/sweep 1');

        self::assertTrue($context->contextLedger()->isPruned('c1'), $reply);
        self::assertStringContainsString('Read', $reply);
    }

    public function testSweepingTwiceDoesNotDoubleCount(): void
    {
        $context = CommandContext::new(history: self::history());
        self::reply($context, '/sweep 1');

        $second = self::reply($context, '/sweep 1');

        self::assertTrue($context->contextLedger()->isPruned('c1'));
        self::assertStringContainsString('already', strtolower($second));
    }
}
