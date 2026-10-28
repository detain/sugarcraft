<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\StartupSummaryGatheredMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\CommandEffect;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Support\StartupSummary;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Code\Workspace\GitRunner;

/**
 * VIS-1-P2 (plan_comparison 6.6, C-TUI-7.a): the startup summary's expensive
 * facts — git branch, instruction docs, memories, skills, MCP inventory —
 * used to be read INSIDE view() on the first frame, per process-global memo
 * miss. The gather now rides Chat::init() (and the ClearTranscript /
 * SwitchSession arms of applyCommandResult()) as a Cmd whose promise settles
 * to {@see StartupSummaryGatheredMsg}; render() paints the cheap rows until
 * store() lands the rest. This file is the keystone pin for that split: the
 * view path must never reach an expensive source again.
 *
 * The main pin is STRUCTURAL — sliced from the real method bodies — because
 * GitRunner has no injection seam to spy on (it degrades via ok=false, and
 * the sibling fail-soft pin in StartupSummaryTest documents the same house
 * answer). A behavioural companion proves the paint path is inert for a
 * machine this test can inspect: a fresh Chat on a non-git root renders
 * byte-identical frames with no branch segment until a bag is stored.
 */
final class StartupSummaryGatherTest extends TestCase
{
    /** @var list<string> */
    private array $scratchDirs = [];

    protected function setUp(): void
    {
        StartupSummary::clearCacheForTesting();
    }

    protected function tearDown(): void
    {
        StartupSummary::clearCacheForTesting();
        foreach ($this->scratchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $nodes = \iterator_to_array(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ), false);
            foreach ($nodes as $node) {
                if ($node->isDir()) {
                    @rmdir($node->getPathname());
                } else {
                    @unlink($node->getPathname());
                }
            }
            @rmdir($dir);
        }
        $this->scratchDirs = [];
    }

    /**
     * The keystone: no expensive fact source may appear in any method the
     * paint path touches. RED at base either way — base render() inlined
     * `self::gather(` (which read GitRunner, the loader, SkillManager and the
     * memory stores), and the cheap/expensive split's methods did not exist.
     */
    public function testThePaintPathTouchesNoExpensiveFactSource(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Support/StartupSummary.php');

        // Every method reachable from render() before a bag has been stored,
        // plus the store seam itself — none may gather.
        $paintSlices = [
            'public static function render',
            'private static function deriveKey',
            'private static function cheapFacts',
            'public static function compose',
            'public static function tipIndex',
            'private static function providerAndModel',
            'private static function oneLine',
            'private static function shortenHome',
            'private static function bytes',
            'private static function rowsToString',
            'private static function faintRows',
            'public static function store',
        ];
        $forbidden = [
            'GitRunner::',
            'self::branch(',
            'self::instructionFiles(',
            'self::memoryCount(',
            'self::skillCount(',
            'self::mcpCount(',
            'gatherExpensive',
            'self::gather(',
            'SkillManager',
            'SkillRegistry',
            'SkillLoader',
            'ProjectMemoryWriter',
            'Bootstrap::instructionLoader',
            'mcpServerInventory(',
            'loadAll(',
            'realpath(',
        ];

        foreach ($paintSlices as $signature) {
            $start = \strpos($src, $signature);
            $this->assertIsInt($start, "StartupSummary no longer declares {$signature}() — re-derive this pin, do not delete it");
            $end = \strpos($src, "\n    }\n", $start);
            $this->assertIsInt($end, "Could not find the end of {$signature}()");
            $body = \substr($src, $start, $end - $start);

            foreach ($forbidden as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $body,
                    "VIS-1-P2: {$signature}() reaches {$token} — a blocking fact source is back on the view() hot path",
                );
            }
        }
    }

    public function testARenderBeforeAnyBagLandsIsCheapStableAndBranchFree(): void
    {
        $root = $this->scratch('root');
        $chat = new Chat(projectRoot: $root);

        $first = StartupSummary::render($chat);
        $this->assertSame($first, StartupSummary::render($chat), 'an idle repaint paints the identical cheap block');

        $plain = (string) \preg_replace('/\e\[[0-9;]*m/', '', $first);
        $rows = \explode("\n", $plain);
        $this->assertSame($root, $rows[1], 'the place row carries the cwd and nothing gathered behind it');
        $this->assertStringStartsWith('Tip: ', \trim($rows[\count($rows) - 1]), 'the tip (a cheap fact) survives the split');
    }

    public function testGatherCmdSettlesToAMessageCarryingTheExpensiveFacts(): void
    {
        $repo = $this->gitRepo('alpha');
        $chat = new Chat(projectRoot: $repo);

        $gather = StartupSummary::gatherCmd($chat);
        $this->assertInstanceOf(\Closure::class, $gather);

        $async = $gather();
        $this->assertInstanceOf(AsyncCmd::class, $async, 'the gather must ride as a promise-backed Cmd, never a synchronous read');

        $resolved = null;
        $async->promise->then(static function (object $msg) use (&$resolved): void {
            $resolved = $msg;
        });

        $this->assertInstanceOf(StartupSummaryGatheredMsg::class, $resolved);
        $this->assertSame('alpha', $resolved->facts['branch'], 'the branch was gathered off the paint path');
    }

    public function testUpdateLandsTheMessageThroughStoreAndRenderPaintsIt(): void
    {
        $root = $this->scratch('land');
        $chat = new Chat(projectRoot: $root);

        $plainBefore = (string) \preg_replace('/\e\[[0-9;]*m/', '', StartupSummary::render($chat));
        $this->assertStringNotContainsString(' · omega', $plainBefore);

        [$next, $cmd] = $chat->update(new StartupSummaryGatheredMsg([
            'branch' => 'omega',
            'instructionFiles' => [],
            'memories' => 0,
            'skills' => 0,
            'mcpServers' => 0,
        ]));
        $this->assertNull($cmd, 'landing the gathered facts is a pure store — no follow-up work');
        $this->assertStringContainsString(' · omega', StartupSummary::render($next), 'the stored bag renders under the same key');
    }

    /**
     * VIS-1-P2's second half: /clear (and switching sessions) re-issues the
     * gather, so a branch changed out from under the UI cannot sit in the
     * memo forever. Driven through the real applyCommandResult arms — the
     * same CommandResult shape CommandsCommand produces for the transcript.
     */
    public function testClearTranscriptReGathersAndTheStoreOverwritesTheMemo(): void
    {
        $repo = $this->gitRepo('alpha');
        $chat = new Chat(projectRoot: $repo);

        $seed = $this->landStartupMessages(StartupSummary::gatherCmd($chat));
        $this->assertCount(1, $seed);
        [$stored, $seedCmd] = $chat->update($seed[0]);
        $this->assertNull($seedCmd);
        $this->assertStringContainsString(' · alpha', StartupSummary::render($stored));

        (GitRunner::new($repo))->run('checkout', '-b', 'beta');

        [$cleared, $regather] = $this->applyCommandResult(
            $stored,
            CommandResult::new()->withEffect(CommandEffect::clearTranscript()),
        );
        $this->assertSame([], $cleared->history, 'the arm still clears');

        $messages = $this->landStartupMessages($regather);
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(StartupSummaryGatheredMsg::class, $messages[0]);
        $this->assertSame('beta', $messages[0]->facts['branch'], 'the re-gather saw the branch change');

        [$final, $storeCmd] = $cleared->update($messages[0]);
        $this->assertNull($storeCmd);

        $frame = (string) \preg_replace('/\e\[[0-9;]*m/', '', StartupSummary::render($final));
        $this->assertStringContainsString(' · beta', $frame, 'the re-gathered fact replaced the memo');
        $this->assertStringNotContainsString('alpha', $frame, 'the stale memo did not survive store()');
    }

    /**
     * The SwitchSession arm re-gathers under the NEW session key: the first
     * render after the switch is cheap-only (the old bag sits under a
     * different key and must not leak across), and the landed message paints
     * the branch again.
     */
    public function testSwitchSessionReGathersUnderTheNewKeyWithoutLeakingTheOldBag(): void
    {
        $repo = $this->gitRepo('gamma');
        $chat = new Chat(projectRoot: $repo);

        $seed = $this->landStartupMessages(StartupSummary::gatherCmd($chat));
        [$stored, $seedCmd] = $chat->update($seed[0]);
        $this->assertNull($seedCmd);

        [$switched, $regather] = $this->applyCommandResult(
            $stored,
            CommandResult::new()->withEffect(CommandEffect::switchSession('sess-z')),
        );
        $this->assertSame('sess-z', $switched->currentSessionId(), 'the arm still switches');

        $between = (string) \preg_replace('/\e\[[0-9;]*m/', '', StartupSummary::render($switched));
        $this->assertStringNotContainsString(' · gamma', $between, 'the previous session bag leaked through the key change');

        $messages = $this->landStartupMessages($regather);
        $this->assertCount(1, $messages);
        $this->assertSame('gamma', $messages[0]->facts['branch']);

        [$landed] = $switched->update($messages[0]);
        $this->assertStringContainsString(' · gamma', StartupSummary::render($landed));
    }

    /**
     * Invoke the private model-side arm directly — the same seam
     * Chat::route() hands a command's CommandResult.
     *
     * @return array{0: Chat, 1: ?\Closure}
     */
    private function applyCommandResult(Chat $chat, CommandResult $result): array
    {
        $applied = (new \ReflectionMethod(Chat::class, 'applyCommandResult'))->invoke($chat, $result);
        $this->assertIsArray($applied);
        $this->assertContainsOnlyInstancesOf(Chat::class, [$applied[0]]);
        return [$applied[0], $applied[1]];
    }

    /**
     * Run a startup Cmd the way Program fans it out (recursively through
     * BatchMsg, resolving settled promises in-line) and collect every message
     * that lands — gather and wake included.
     *
     * @return list<object>
     */
    private function landStartupMessages(?\Closure $startupCmd): array
    {
        $this->assertNotNull($startupCmd);

        $landed = [];
        $queue = [$startupCmd()];
        while ($queue !== []) {
            $msg = \array_shift($queue);
            if ($msg instanceof BatchMsg) {
                foreach ($msg->cmds as $child) {
                    $queue[] = $child();
                }
                continue;
            }
            if ($msg instanceof AsyncCmd) {
                $msg->promise->then(static function (object $settled) use (&$queue): void {
                    $queue[] = $settled;
                });
                continue;
            }
            $landed[] = $msg;
        }

        return $landed;
    }

    private function scratch(string $tag): string
    {
        $dir = '/tmp/opencode/ctui7a-gather-' . $tag . '-' . \uniqid('', true);
        \mkdir($dir, 0700, true);
        $this->scratchDirs[] = $dir;
        return $dir;
    }

    private function gitRepo(string $branch): string
    {
        if (!GitRunner::available()) {
            $this->markTestSkipped('git is not available on this host');
        }
        $dir = $this->scratch('repo');
        $init = (GitRunner::new($dir))->run('init', '--initial-branch=' . $branch);
        $this->assertTrue($init['ok'], 'git init --initial-branch failed for the fixture repo: ' . $init['stderr']);
        return $dir;
    }
}
