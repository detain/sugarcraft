<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\Pruning\CompressionBlock;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\DecompressHostCommand;
use SugarCraft\Code\Host\Commands\RecompressHostCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;

final class RecompressHostCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/recompress-host-' . bin2hex(random_bytes(5));
        $this->useHomeSandbox($this->home . '/home');
        putenv(PruningMode::ENV);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    private static function block(int $id = 1, array $consumed = []): CompressionBlock
    {
        return CompressionBlock::range($id, 'Reading a.php', ContextLedger::userRowKey('read it', 1), ContextLedger::stepKey('c1'), 1, 2, 'a.php defines login().', 1500, 12, $consumed);
    }

    private function context(ContextLedger $ledger): CommandContext
    {
        $output = str_repeat('a long line of output ', 100);
        $context = CommandContext::new(history: [
            Message::user('read it'),
            Message::assistant('')->withToolCalls([new ToolCall('Read', ['file_path' => 'a.php'], 'c1')])->withStepId('s_a_1')->withUserVisible(false),
            Message::assistant($output)->withToolResults([new ToolResult('Read', $output, null, 'c1')])->withStepId('s_a_1'),
            Message::assistant('read it')->withStepId('s_a_2'),
        ]);
        $context->saveContextLedger($ledger);

        return $context;
    }

    private static function reply(CommandContext $context, string $text): string
    {
        return (new RecompressHostCommand())->run($context, $text)->rows[1]->content;
    }

    public function testTheBareFormSharesTheDecompressListing(): void
    {
        $ledger = ContextLedger::new()->withBlock(self::block());

        self::assertSame(DecompressHostCommand::listing($ledger), self::reply($this->context($ledger), '/recompress'));
    }

    public function testRecompressingATakenBackBlockAppliesItAgain(): void
    {
        $context = $this->context(ContextLedger::new()->withBlock(self::block())->withBlockDecompressed(1));

        $reply = self::reply($context, '/recompress b1');

        self::assertStringStartsWith('Recompressed b1 (Reading a.php)', $reply);
        self::assertTrue($context->contextLedger()->block(1)?->active);
    }

    public function testABlockStillActiveSaysSo(): void
    {
        $context = $this->context(ContextLedger::new()->withBlock(self::block()));

        self::assertSame(Lang::t('host.recompress.already', ['id' => 1]), self::reply($context, '/recompress b1'));
    }

    public function testBadAndUnknownIdsAreRefused(): void
    {
        $context = $this->context(ContextLedger::new()->withBlock(self::block()));

        self::assertSame(Lang::t('host.recompress.usage'), self::reply($context, '/recompress what'));
        self::assertSame(Lang::t('host.decompress.unknown', ['id' => 7]), self::reply($context, '/recompress b7'));
    }

    public function testABlockInsideAnotherPointsAtTheOuterOne(): void
    {
        $context = $this->context(ContextLedger::new()->withBlock(self::block())->withBlock(self::block(2, [1])));

        self::assertSame(Lang::t('host.decompress.inside', ['id' => 1, 'outer' => 2]), self::reply($context, '/recompress b1'));
    }
}
