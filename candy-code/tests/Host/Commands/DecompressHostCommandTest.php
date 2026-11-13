<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\Pruning\CompressionBlock;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\DecompressHostCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\ToolResult;

final class DecompressHostCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/decompress-host-' . bin2hex(random_bytes(5));
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
            Message::assistant('read it')->withStepId('s_a_2'),
        ];
    }

    private static function block(int $id = 1, array $consumed = []): CompressionBlock
    {
        return CompressionBlock::range($id, 'Reading a.php', ContextLedger::userRowKey('read it', 1), ContextLedger::stepKey('c1'), 1, 2, 'a.php defines login().', 1500, 12, $consumed);
    }

    private function context(?ContextLedger $ledger = null): CommandContext
    {
        $context = CommandContext::new(history: self::history());
        if ($ledger !== null) {
            $context->saveContextLedger($ledger);
        }

        return $context;
    }

    private static function reply(CommandContext $context, string $text): string
    {
        return (new DecompressHostCommand())->run($context, $text)->rows[1]->content;
    }

    public function testBlockIdParsesBNAndBareNumbersOnly(): void
    {
        self::assertSame(3, DecompressHostCommand::blockId('b3'));
        self::assertSame(3, DecompressHostCommand::blockId('B3'));
        self::assertSame(12, DecompressHostCommand::blockId(' 12 '));
        self::assertNull(DecompressHostCommand::blockId('b0'));
        self::assertNull(DecompressHostCommand::blockId('banana'));
        self::assertNull(DecompressHostCommand::blockId('b1x'));
        self::assertNull(DecompressHostCommand::blockId('-2'));
        self::assertNull(DecompressHostCommand::blockId(''));
    }

    public function testListingOfAnEmptyLedgerSaysNone(): void
    {
        self::assertSame(Lang::t('host.decompress.none'), DecompressHostCommand::listing(ContextLedger::new()));
    }

    public function testListingShowsEachRangeBlockWithItsState(): void
    {
        $listing = DecompressHostCommand::listing(ContextLedger::new()->withBlock(self::block()));

        self::assertStringContainsString(Lang::t('host.decompress.heading'), $listing);
        self::assertStringContainsString('b1 · Reading a.php · r1…r2 · −1.5K +12 · ' . Lang::t('host.decompress.state.active'), $listing);
        self::assertStringContainsString(Lang::t('host.decompress.hint'), $listing);
    }

    public function testListingMarksDecompressedAndNestedBlocks(): void
    {
        $nested = ContextLedger::new()->withBlock(self::block())->withBlock(self::block(2, [1]));
        $listing = DecompressHostCommand::listing($nested);
        self::assertStringContainsString(Lang::t('host.decompress.state.inside', ['outer' => 2]), $listing);

        $taken = ContextLedger::new()->withBlock(self::block())->withBlockDecompressed(1);
        self::assertStringContainsString(Lang::t('host.decompress.state.decompressed'), DecompressHostCommand::listing($taken));
    }

    public function testInsideNamesTheOuterBlockOrFallsBackToInactive(): void
    {
        $ledger = ContextLedger::new()->withBlock(self::block())->withBlock(self::block(2, [1]));
        self::assertSame(Lang::t('host.decompress.inside', ['id' => 1, 'outer' => 2]), DecompressHostCommand::inside($ledger, $ledger->block(1)));

        $lone = ContextLedger::new()->withBlock(self::block());
        self::assertSame(Lang::t('host.decompress.inactive', ['id' => 1]), DecompressHostCommand::inside($lone, $lone->block(1)));
    }

    public function testTheBareFormListsTheSessionsSections(): void
    {
        $context = $this->context(ContextLedger::new()->withBlock(self::block()));

        self::assertStringContainsString('b1 · Reading a.php', self::reply($context, '/decompress'));
        self::assertSame(Lang::t('host.decompress.none'), self::reply($this->context(), '/decompress'));
    }

    public function testDecompressingFlipsTheBlockAndSavesTheLedger(): void
    {
        $context = $this->context(ContextLedger::new()->withBlock(self::block()));

        $reply = self::reply($context, '/decompress b1');

        self::assertStringContainsString('Reading a.php', $reply);
        self::assertTrue($context->contextLedger()->block(1)?->deactivatedByUser);
    }

    public function testRefusalsAnswerWithoutTouchingTheLedger(): void
    {
        $context = $this->context(ContextLedger::new()->withBlock(self::block())->withBlock(self::block(2, [1])));

        self::assertSame(Lang::t('host.decompress.usage'), self::reply($context, '/decompress nonsense'));
        self::assertSame(Lang::t('host.decompress.unknown', ['id' => 9]), self::reply($context, '/decompress b9'));
        self::assertSame(Lang::t('host.decompress.inside', ['id' => 1, 'outer' => 2]), self::reply($context, '/decompress b1'));
        self::assertTrue($context->contextLedger()->block(1)?->active === false && !$context->contextLedger()->block(1)?->deactivatedByUser);

        self::reply($context, '/decompress b2');
        self::assertSame(Lang::t('host.decompress.already', ['id' => 2]), self::reply($context, '/decompress b2'));
    }
}
