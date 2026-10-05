<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tools\BuiltIn\Compress;

/**
 * Roadmap 3.B-4 through the TUI: `/compress [focus]` sends the manual trigger
 * as the user's turn (refused, without a turn, where the session cannot
 * compress); `/decompress bN` and `/recompress bN` flip a section in the
 * SESSION's ledger; and the transcript marks each compressed section and the
 * status bar says how much the ledger takes out.
 */
final class CompressCommandsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string|false $env = false;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-compress-cmds-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->env = getenv(PruningMode::ENV);
        putenv(PruningMode::ENV);
    }

    protected function tearDown(): void
    {
        $this->env === false ? putenv(PruningMode::ENV) : putenv(PruningMode::ENV . '=' . $this->env);
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testCompressSendsTheManualTriggerAsTheTurn(): void
    {
        [$next, $cmd] = $this->type(new Chat(history: self::history()), '/compress the auth work');

        $this->assertNotNull($cmd, 'a turn is dispatched');
        $this->assertTrue($next->inFlight);
        $prompts = array_values(array_filter($next->history, static fn (Message $m): bool => $m->role === \SugarCraft\Crush\Role::User && !$m->uiOnly));
        $this->assertSame(Compress::triggerPrompt('the auth work'), $prompts[array_key_last($prompts)]->content);
    }

    public function testCompressIsRefusedWhereTheSessionCannotCompress(): void
    {
        [$off] = $this->type(new Chat(history: self::history()), '/pruning off');
        [$after, $cmd] = $this->type($off, '/compress');

        $this->assertNull($cmd, 'no turn');
        $this->assertFalse($after->inFlight);
        $this->assertStringStartsWith('Context pruning is `off` for this session', $after->history[array_key_last($after->history)]->content);

    }

    public function testCompressRunsInAManualSessionToo(): void
    {
        [$manual] = $this->type(new Chat(history: self::history()), '/pruning manual');
        [$next, $cmd] = $this->type($manual, '/compress');

        $this->assertNotNull($cmd, '`manual` leaves compaction to the person, and /compress is the person asking');
        $this->assertTrue($next->inFlight);
    }

    public function testDecompressAndRecompressFlipASectionOfTheSessionsLedger(): void
    {
        $chat = new Chat(history: self::history());
        [$empty] = $this->type($chat, '/decompress');
        $this->assertStringStartsWith('No compressed sections in this session', self::lastText($empty));

        self::runnerOf($chat)->saveLedger(null, null, self::compressed());

        [$listed] = $this->type($chat, '/recompress');
        $this->assertStringContainsString("Compressed sections:\nb1 · Reading a.php · r1…r2 · −1.5K +12 · active", self::lastText($listed));

        [$open] = $this->type($chat, '/decompress b1');
        $this->assertStringStartsWith('Decompressed b1 (Reading a.php): r1…r2 are sent in full again', self::lastText($open));
        $this->assertTrue(self::ledgerOf($open)->block(1)?->deactivatedByUser);

        [$again] = $this->type($open, '/decompress b1');
        $this->assertSame('b1 is already decompressed; /recompress b1 restores it.', self::lastText($again));

        [$closed] = $this->type($open, '/recompress b1');
        $this->assertStringStartsWith('Recompressed b1 (Reading a.php)', self::lastText($closed));
        $this->assertTrue(self::ledgerOf($closed)->block(1)?->active);

        [$missing] = $this->type($closed, '/decompress b9');
        $this->assertSame('No compressed section b9 in this session.', self::lastText($missing));
        [$usage] = $this->type($closed, '/decompress banana');
        $this->assertStringStartsWith('Usage: /decompress bN', self::lastText($usage));
    }

    public function testASectionInsideAnotherIsRefused(): void
    {
        $chat = new Chat(history: self::history());
        $outer = CompressionBlock::range(2, 'Everything', ContextLedger::userRowKey('read it', 1), ContextLedger::stepKey('c1'), 1, 2, 'all (b1)', 1600, 20, [1]);
        self::runnerOf($chat)->saveLedger(null, null, self::compressed()->withBlock($outer));

        [$refused] = $this->type($chat, '/decompress b1');

        $this->assertSame('b1 is inside b2. Restore b2 first: /decompress b2.', self::lastText($refused));
    }

    public function testTheTranscriptMarksTheSectionAndTheStatusBarCountsWhatIsTakenOut(): void
    {
        $chat = (new Chat(history: self::history()))->withSize(160, 30);
        $plain = self::plain(Renderer::render($chat));
        $this->assertStringNotContainsString('▣ Compressed', $plain);
        $this->assertStringNotContainsString('pruned)', $plain);

        self::runnerOf($chat)->saveLedger(null, null, self::compressed());
        $frame = self::plain(Renderer::render($chat));

        $this->assertStringContainsString('▣ Compressed b1 · Reading a.php · −1.5K +12', $frame);
        $this->assertLessThan(strpos($frame, 'user> read it'), strpos($frame, '▣ Compressed b1'), 'above the section\'s first row');
        $this->assertStringContainsString('user> read it', $frame, 'the person still reads every row');
        $this->assertMatchesRegularExpression('/context \(\d+%, −1\.5K pruned\)/u', $frame);
    }

    // ── harness ─────────────────────────────────────────────────────────

    private static function compressed(): ContextLedger
    {
        return ContextLedger::new()->withBlock(CompressionBlock::range(
            1,
            'Reading a.php',
            ContextLedger::userRowKey('read it', 1),
            ContextLedger::stepKey('c1'),
            1,
            2,
            'a.php defines login().',
            1500,
            12,
        ));
    }

    /** @return array{0: Chat, 1: mixed} */
    private function type(Chat $chat, string $draft): array
    {
        $drafted = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat->withSize(120, 30), ['inputBuf' => $draft]);

        return $drafted->update(new KeyMsg(KeyType::Enter));
    }

    private static function runnerOf(Chat $chat): TurnRunner
    {
        return (new \ReflectionMethod($chat, 'turnRunner'))->invoke($chat);
    }

    private static function ledgerOf(Chat $chat): ContextLedger
    {
        return (new \ReflectionMethod($chat, 'sessionContextLedger'))->invoke($chat);
    }

    private static function lastText(Chat $chat): string
    {
        return $chat->history[array_key_last($chat->history)]->content;
    }

    private static function plain(string $frame): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a|[\x{E000}-\x{F8FF}]/u', '', $frame);
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
}
