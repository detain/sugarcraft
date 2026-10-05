<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulePathNudge;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Write;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\ReadLedger;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.I-2: the session read ledger. Read records what the model saw,
 * Edit and an overwriting Write refuse a file that changed on disk since, the
 * writers record their own change as the new baseline, and the ledger crosses
 * both forks a read can sit behind — a parallel Read child (through
 * {@see \SugarCraft\Crush\Tools\CarriesSessionState}) and the turn child
 * (through the `result` frame's `readLedger` key).
 */
final class ReadLedgerStalenessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-read-ledger-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    // ── the ledger ──────────────────────────────────────────────────────

    public function testAPathNeverReadIsNeverStale(): void
    {
        $path = $this->file('a.txt', "one\n");

        $this->assertNull(ReadLedger::new()->staleness($path, "anything\n"));
        $this->assertFalse(ReadLedger::new()->has($path));
    }

    public function testStalenessIsDecidedByContentNotByTheClock(): void
    {
        $path = $this->file('a.txt', "one\n");
        $ledger = ReadLedger::new();
        $ledger->record($path, "one\n");

        $this->assertNull($ledger->staleness($path, "one\n"));
        touch($path, time() + 120);
        $this->assertNull($ledger->staleness($path, "one\n"), 'a touch over identical bytes is not a change');
        $this->assertSame('modified', $ledger->staleness($path, "two\n"));
    }

    public function testWithoutCurrentBytesTheStatSignatureDecides(): void
    {
        $path = $this->file('a.txt', "one\n");
        $ledger = ReadLedger::new();
        $ledger->record($path);

        $this->assertNull($ledger->staleness($path));
        file_put_contents($path, "one, longer\n");
        $this->assertSame('modified', $ledger->staleness($path));
        unlink($path);
        $this->assertSame('deleted', $ledger->staleness($path));
    }

    public function testChangedSinceReadListsRealChangesMostRecentFirstAndRebasesATouch(): void
    {
        $a = $this->file('a.txt', "a\n");
        $b = $this->file('b.txt', "b\n");
        $c = $this->file('c.txt', "c\n");
        $ledger = ReadLedger::new();
        $ledger->record($a);
        $ledger->record($b);
        usleep(1000);
        $ledger->record($c);

        $this->assertSame([], $ledger->changedSinceRead());
        $this->assertSame('', $ledger->notice());

        touch($a, time() + 120);
        file_put_contents($b, "bb\n");
        unlink($c);

        $this->assertSame([$c => 'deleted', $b => 'modified'], $ledger->changedSinceRead());
        $this->assertSame((int) filemtime($a), $ledger->toArray()[$a]['mtime'], 'the touched entry is re-based so the next scan skips its hash');

        $notice = $ledger->notice();
        $this->assertStringStartsWith('Files changed on disk since you last read them', $notice);
        $this->assertStringContainsString("- {$c} (deleted)", $notice);
        $this->assertStringContainsString("- {$b}\n", $notice . "\n");
        $this->assertStringNotContainsString($a, $notice);
    }

    public function testTheNoticeNamesAtMostTenPathsAndCountsTheRest(): void
    {
        $ledger = ReadLedger::new();
        for ($i = 0; $i < ReadLedger::MAX_NOTICE_PATHS + 3; $i++) {
            $ledger->record($this->file("f{$i}.txt", "x\n"));
        }
        foreach (glob($this->dir . '/f*.txt') ?: [] as $file) {
            file_put_contents($file, "changed\n");
        }

        $lines = explode("\n", $ledger->notice());

        $this->assertCount(1 + ReadLedger::MAX_NOTICE_PATHS + 1, $lines);
        $this->assertSame('- … and 3 more', end($lines));
    }

    public function testMergeIsOrderIndependentLastRecordedWinsAndMalformedRowsAreSkipped(): void
    {
        $path = $this->file('a.txt', "a\n");
        $older = ['mtime' => 1, 'size' => 2, 'ino' => 3, 'hash' => 'old', 'at' => 10.0];
        $newer = ['mtime' => 4, 'size' => 5, 'ino' => 6, 'hash' => 'new', 'at' => 20.0];

        $one = ReadLedger::new();
        $one->merge([$path => $older]);
        $one->merge([$path => $newer]);
        $two = ReadLedger::new();
        $two->merge([$path => $newer]);
        $two->merge([$path => $older]);
        $two->merge([$path => $newer]);

        $this->assertSame($one->toArray(), $two->toArray());
        $this->assertSame('new', $one->toArray()[$path]['hash']);

        $lenient = ReadLedger::new();
        $lenient->merge('garbage');
        $lenient->merge([0 => $newer, '' => $newer, 'x' => 'row', 'y' => ['mtime' => 'one'] + $newer, 'z' => ['hash' => 7] + $newer]);
        $this->assertSame([], $lenient->toArray());
    }

    public function testTheLedgerIsBoundedEvictingTheLeastRecentlyRecorded(): void
    {
        $ledger = ReadLedger::new();
        $rows = [];
        for ($i = 0; $i < ReadLedger::MAX_ENTRIES + 5; $i++) {
            $rows["/nowhere/{$i}"] = ['mtime' => 1, 'size' => 1, 'ino' => 1, 'hash' => null, 'at' => (float) $i];
        }
        $ledger->merge(array_reverse($rows, true));

        $this->assertCount(ReadLedger::MAX_ENTRIES, $ledger->toArray());
        $this->assertFalse($ledger->has('/nowhere/4'));
        $this->assertTrue($ledger->has('/nowhere/5'));
    }

    // ── the tools ───────────────────────────────────────────────────────

    public function testReadRecordsTheFileItPaged(): void
    {
        $path = $this->file('a.txt', "one\ntwo\n");
        $ledger = ReadLedger::new();

        $result = (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt', 'limit' => 1]);

        $this->assertFalse($result->isError());
        $this->assertTrue($ledger->has($path), 'even a one-line window records the whole file');
        $this->assertSame(hash('xxh128', "one\ntwo\n"), $ledger->toArray()[$path]['hash'], 'hashed from the bytes the counting pass read');
    }

    public function testEditRefusesAFileThatChangedSinceItWasReadAndLeavesItUntouched(): void
    {
        $path = $this->file('a.txt', "alpha\nbeta\n");
        $ledger = ReadLedger::new();
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt']);
        file_put_contents($path, "alpha\nbeta\ngamma\n");
        $edit = new Edit($this->dir, readLedger: $ledger);

        $refused = $edit->execute(['file_path' => 'a.txt', 'old_string' => 'beta', 'new_string' => 'BETA']);

        $this->assertTrue($refused->isError());
        $this->assertStringContainsString('changed on disk since you last read it', $refused->content());
        $this->assertStringContainsString('Read it again', $refused->content());
        $this->assertSame("alpha\nbeta\ngamma\n", file_get_contents($path));

        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt']);
        $this->assertFalse($edit->execute(['file_path' => 'a.txt', 'old_string' => 'beta', 'new_string' => 'BETA'])->isError(), 'a fresh Read lifts the refusal');
        $this->assertSame("alpha\nBETA\ngamma\n", file_get_contents($path));
    }

    public function testTheModelsOwnEditIsItsNewBaseline(): void
    {
        $this->file('a.txt', "alpha\nbeta\n");
        $ledger = ReadLedger::new();
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt']);
        $edit = new Edit($this->dir, readLedger: $ledger);

        $this->assertFalse($edit->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'ALPHA'])->isError());
        $this->assertFalse($edit->execute(['file_path' => 'a.txt', 'old_string' => 'beta', 'new_string' => 'BETA'])->isError(), 'the first edit must not make the file stale to the second');
        $this->assertSame([], $ledger->changedSinceRead());
    }

    public function testATouchBetweenReadAndEditIsNotAChange(): void
    {
        $path = $this->file('a.txt', "alpha\n");
        $ledger = ReadLedger::new();
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt']);
        touch($path, time() + 120);

        $this->assertFalse((new Edit($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'beta'])->isError());
    }

    public function testAnEditOfAFileNeverReadIsNotRefused(): void
    {
        $this->file('a.txt', "alpha\n");

        $result = (new Edit($this->dir, readLedger: ReadLedger::new()))->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'beta']);

        $this->assertFalse($result->isError(), 'staleness is about a picture going out of date, not read-before-write');
    }

    public function testWriteRefusesToOverwriteAStaleFileButCreatesAndOverwritesFreshOnes(): void
    {
        $path = $this->file('a.txt', "alpha\n");
        $ledger = ReadLedger::new();
        $write = new Write($this->dir, readLedger: $ledger);
        (new Read($this->dir, readLedger: $ledger))->execute(['file_path' => 'a.txt']);
        file_put_contents($path, "someone else\n");

        $refused = $write->execute(['file_path' => 'a.txt', 'content' => "mine\n", 'overwrite' => true]);
        $this->assertTrue($refused->isError());
        $this->assertStringContainsString('Read it again before overwriting it', $refused->content());
        $this->assertSame("someone else\n", file_get_contents($path));

        $this->assertFalse($write->execute(['file_path' => 'new.txt', 'content' => "fresh\n"])->isError(), 'a create has nothing to be stale against');
        $this->assertTrue($ledger->has($this->dir . '/new.txt'), 'and what it wrote is recorded');
        $this->assertFalse($write->execute(['file_path' => 'new.txt', 'content' => "again\n", 'overwrite' => true])->isError(), 'its own write is the baseline');
    }

    public function testOnlyAnInstanceWithALedgerClaimsTheRefusalInItsDescription(): void
    {
        $this->assertStringNotContainsString('changed on disk', (new Edit())->description());
        $this->assertStringNotContainsString('changed on disk', (new Write())->description());
        $this->assertStringContainsString('changed on disk after you last read it', (new Edit(readLedger: ReadLedger::new()))->description());
        $this->assertStringContainsString('changed on disk after you last', (new Write(readLedger: ReadLedger::new()))->description());
    }

    public function testToolsBuiltFromOneCatalogContextShareOneLedger(): void
    {
        $tools = ToolCatalog::build(self::context($this->dir));
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name()] = $tool;
        }

        $ledger = ReadLedger::in($tools);
        $this->assertNotNull($ledger);
        foreach (['Read', 'Edit', 'Write', 'ApplyPatch'] as $name) {
            $this->assertSame($ledger, $byName[$name]->readLedger(), "{$name} records into the shared ledger");
        }
        $this->assertNotSame($ledger, ReadLedger::in(ToolCatalog::build(self::context($this->dir))), 'a second build is a second session');

        $path = $this->file('a.txt', "alpha\n");
        $byName['Read']->execute(['file_path' => 'a.txt', 'description' => 'inspect']);
        file_put_contents($path, "beta\n");
        $this->assertTrue($byName['Edit']->execute(['file_path' => 'a.txt', 'old_string' => 'beta', 'new_string' => 'gamma'])->isError());
    }

    // ── across the forks ────────────────────────────────────────────────

    public function testReadCarriesTheLedgerHomeFromAParallelChild(): void
    {
        $path = $this->file('a.txt', "alpha\n");
        $child = new Read($this->dir, readLedger: ReadLedger::new());
        $child->execute(['file_path' => 'a.txt']);
        $parentLedger = ReadLedger::new();

        // Serialised exactly as Runtime carries it: scalars only, no objects.
        (new Read($this->dir, readLedger: $parentLedger))->mergeSessionState(
            unserialize(serialize($child->exportSessionState()), ['allowed_classes' => false]),
        );

        $this->assertTrue($parentLedger->has($path));
    }

    public function testTheResultFrameMergesTheChildsLedgerOnSuccessAndOnFailure(): void
    {
        $path = $this->file('a.txt', "alpha\n");
        $childLedger = ReadLedger::new();
        $childLedger->record($path);

        foreach ([['ok' => true, 'content' => 'x'], ['ok' => false, 'error' => 'boom']] as $frame) {
            $ledger = ReadLedger::new();
            $backend = EngineBackend::new(new ScriptedProvider([]), 'm')->withoutHooks()->withTools([new Read($this->dir, readLedger: $ledger)]);
            $deferred = new Deferred();
            $deferred->promise()->then(null, static fn () => null);

            (new \ReflectionMethod($backend, 'settleFromResultFrame'))->invoke($backend, $frame + ['readLedger' => unserialize(serialize($childLedger->toArray()), ['allowed_classes' => false])], $deferred, null);

            $this->assertTrue($ledger->has($path), 'a failed turn\'s reads still happened');
        }
    }

    public function testAReadInTheForkedTurnRefusesTheNextTurnsEditOfAChangedFile(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl, so nothing crosses a fork');
        }

        $a = $this->file('a.txt', "alpha\n");
        $b = $this->file('b.txt', "beta\n");
        $ledger = ReadLedger::new();
        $read = new Read($this->dir, readLedger: $ledger);
        $edit = new Edit($this->dir, readLedger: $ledger);
        // Two Reads in one step: Runtime fans them out to parallel children
        // inside the turn child, so each record crosses two forks home.
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('r1', 'Read', ['file_path' => 'a.txt', 'description' => 'inspect a']),
                new ToolCall('r2', 'Read', ['file_path' => 'b.txt', 'description' => 'inspect b']),
            ]),
            new CompleteResponse(content: 'read both'),
        ], contextWindow: 1_000_000);
        $backend = EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->dir)->withTools([$read, $edit]);

        $reply = $this->drainUntilSettled($backend->completeAsync([Message::user('read a and b')]));

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertTrue($ledger->has($a), 'the parent learned of the read made in a child of the turn child');
        $this->assertTrue($ledger->has($b));

        file_put_contents($a, "alpha\nchanged\n");
        $this->assertTrue($edit->execute(['file_path' => 'a.txt', 'old_string' => 'alpha', 'new_string' => 'ALPHA'])->isError());
        $this->assertFalse($edit->execute(['file_path' => 'b.txt', 'old_string' => 'beta', 'new_string' => 'BETA'])->isError());
    }

    /**
     * The ledger is the build context's own field (roadmap 3.I-2 remainder),
     * not a lookup keyed on the context: the launch that builds the tool set
     * hands it in, and every ledger-carrying tool takes exactly that one.
     */
    public function testTheContextsOwnLedgerIsTheOneEveryToolTakes(): void
    {
        $skills = new SkillRegistry();
        $ledger = ReadLedger::new();
        $tools = ToolCatalog::build(new ToolBuildContext(
            root: $this->dir,
            loader: new InstructionFileLoader($this->dir),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            readLedger: $ledger,
        ));

        $this->assertSame($ledger, ReadLedger::in($tools));
    }

    public function testTheLaunchBuildsOneLedgerForItsWholeToolSet(): void
    {
        $tools = \SugarCraft\Crush\Cli\Bootstrap::unfilteredTools($this->dir);
        $ledger = ReadLedger::in($tools);

        $this->assertNotNull($ledger, 'the launch\'s tools carry no read ledger — the staleness refusal is unwired');
        $carriers = 0;
        foreach ($tools as $tool) {
            if (method_exists($tool, 'readLedger')) {
                $this->assertSame($ledger, $tool->readLedger(), $tool->name() . ' holds a ledger of its own');
                ++$carriers;
            }
        }
        $this->assertSame(4, $carriers, 'Read, Edit, Write and ApplyPatch carry the ledger');
        $this->assertNotSame($ledger, ReadLedger::in(\SugarCraft\Crush\Cli\Bootstrap::unfilteredTools($this->dir)), 'each build is its own session');
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function file(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private static function context(string $root): ToolBuildContext
    {
        $skills = new SkillRegistry();

        return new ToolBuildContext(
            root: $root,
            loader: new InstructionFileLoader($root),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
        );
    }

    private function drainUntilSettled(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;

        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );

        if (!$settled) {
            $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
                $failure = new \RuntimeException('the forked completion never settled within the safety window');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($watchdog);
        }

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }

        return $value;
    }
}
