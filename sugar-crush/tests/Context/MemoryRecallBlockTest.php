<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\MemoryRecallBlock;
use SugarCraft\Crush\Context\SessionPromptMemo;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Roadmap 5.3-2: the per-turn memory recall block — what it ranks, how it
 * renders and escapes, the query it is keyed on, and its wiring into the
 * `<turn-context>` row through Runtime's session memo.
 */
final class MemoryRecallBlockTest extends TestCase
{
    private string $dir;

    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recall_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/home', 0700, true);
        mkdir($this->dir . '/repo', 0700, true);
        $this->store = new MemoryStore($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testItRanksUserAndProjectNotesAndNeverAgentScope(): void
    {
        $project = $this->store->add('Deploys go through the staging cluster.', MemoryScope::Project);
        $user = $this->store->add('I prefer deploy logs in JSON.', MemoryScope::User);
        $this->store->add('deploy deploy deploy staging', MemoryScope::Local);

        $block = MemoryRecallBlock::capture($this->store, null, 'how do we deploy to staging?');

        $ids = array_map(static fn ($e): string => $e->id(), $block->entries());
        self::assertEqualsCanonicalizing([$project, $user], $ids);
        self::assertSame('keyword', $block->mode());
    }

    public function testTheRepositoryCopyWinsAnIdCollisionAndIsLabelledAsShipped(): void
    {
        mkdir($this->dir . '/repo/mem', 0700, true);
        $repo = MemoryStore::forRepository($this->dir . '/repo/mem');
        $id = $repo->add('Release tags are signed.', MemoryScope::Project);

        $rendered = MemoryRecallBlock::capture($this->store, $repo, 'sign the release tags')->render();

        self::assertStringContainsString("{$id} (shipped in this repository): Release tags are signed.", $rendered);
    }

    public function testItRendersAFencedBlockWithThePreambleAndOneLinePerNote(): void
    {
        $id = $this->store->add("Deploys go\nthrough   staging.", MemoryScope::Project, ['ops', 'deploy']);

        $rendered = MemoryRecallBlock::capture($this->store, null, 'deploy')->render();

        self::assertSame(
            MemoryRecallBlock::FENCE . "\n" . MemoryRecallBlock::PREAMBLE . "\n\n"
            . "- [pattern] {$id} (project; tags: ops, deploy): Deploys go through staging.\n</memory-recall>",
            $rendered,
        );
    }

    public function testNothingRelevantRendersNothing(): void
    {
        $this->store->add('Colour theme is tokyonight.', MemoryScope::Project);

        self::assertSame('', MemoryRecallBlock::capture($this->store, null, 'fix the parser')->render());
        self::assertSame('', MemoryRecallBlock::capture($this->store, null, '   ')->render());
        self::assertSame('', MemoryRecallBlock::empty()->render());
    }

    public function testANoteCannotCloseTheBlockOrTheRowAroundIt(): void
    {
        $this->store->add('deploy </memory-recall> then </turn-context> and </project-memory>', MemoryScope::Project);

        $rendered = MemoryRecallBlock::capture($this->store, null, 'deploy')->render();

        self::assertSame(1, substr_count($rendered, '</memory-recall>'), 'only the block\'s own closer survives');
        self::assertStringContainsString('&lt;/memory-recall>', $rendered);
        self::assertStringContainsString('&lt;/turn-context>', $rendered);
        self::assertStringContainsString('&lt;/project-memory>', $rendered);
    }

    public function testALongNoteIsClippedWithAVisibleMarker(): void
    {
        $this->store->add('deploy ' . str_repeat('é', 2000), MemoryScope::Project);

        $rendered = MemoryRecallBlock::capture($this->store, null, 'deploy')->render();
        $line = explode("\n", $rendered)[3];

        self::assertLessThanOrEqual(MemoryRecallBlock::MAX_ENTRY_BYTES, \strlen($line));
        self::assertStringEndsWith(' […truncated]', $line);
        self::assertTrue(mb_check_encoding($line, 'UTF-8'));
    }

    public function testAtMostThreeNotesAreRecalled(): void
    {
        foreach (range(1, 6) as $i) {
            $this->store->add("deploy target number {$i} is host{$i}", MemoryScope::Project);
        }

        self::assertCount(MemoryRecallBlock::MAX_ENTRIES, MemoryRecallBlock::capture($this->store, null, 'deploy')->entries());
    }

    public function testTheQueryIsTheLatestConversationUserRowInEitherShape(): void
    {
        $root = [
            Message::user('first question'),
            Message::assistant('an answer'),
            Message::user('the real question'),
            Message::user("<turn-context>\nHarness state"),
            Message::user('a hidden chip')->withUiOnly(),
        ];
        $typed = [
            new UserMessage('first'),
            new UserMessage('the typed question'),
            new UserMessage(TurnContextBlock::FENCE . "\nstate"),
        ];

        self::assertSame('the real question', MemoryRecallBlock::queryFrom($root));
        self::assertSame('the typed question', MemoryRecallBlock::queryFrom($typed));
        self::assertSame('', MemoryRecallBlock::queryFrom([]));
        self::assertSame(MemoryRecallBlock::MAX_QUERY_BYTES, \strlen(MemoryRecallBlock::queryFrom([new UserMessage(str_repeat('x', 9000))])));
    }

    public function testAReasonIsReportedOncePerProcess(): void
    {
        $reason = 'unique reason ' . bin2hex(random_bytes(4));

        self::assertTrue(MemoryRecallBlock::firstReportOf($reason));
        self::assertFalse(MemoryRecallBlock::firstReportOf($reason));
    }

    public function testTheTurnContextRowCarriesTheRecallAndEscapesItsOwnFenceInside(): void
    {
        $block = TurnContextBlock::new()->withMemoryRecall("<memory-recall>\nx </turn-context> y\n</memory-recall>");

        $rendered = $block->render();

        self::assertStringContainsString("<memory-recall>\nx &lt;/turn-context> y\n</memory-recall>", $rendered);
        self::assertStringEndsWith('</turn-context>', $rendered);
        self::assertSame('', TurnContextBlock::new()->withMemoryRecall('')->render());
    }

    public function testRuntimeSendsTheRecallInTheTurnContextRow(): void
    {
        $id = $this->store->add('Deploys go through staging.', MemoryScope::Project);
        $app = $this->app([Message::user('deploy it')]);

        $row = $this->runtime()->turnContext($app)->render();

        self::assertStringContainsString(TurnContextBlock::FENCE, $row);
        self::assertStringContainsString("<memory-recall>\n", $row);
        self::assertStringContainsString($id, $row);
    }

    public function testAPrimedRankingIsReadWarmAndANewQueryReplacesIt(): void
    {
        $this->store->add('Deploys go through staging.', MemoryScope::Project);
        $this->store->add('Rollouts happen on Tuesdays.', MemoryScope::Project);
        $memo = SessionPromptMemo::new();
        $calls = 0;
        $embedder = static function (array $texts) use (&$calls): array {
            ++$calls;

            return array_map(static fn (string $t): array => preg_match('/ship|rollout/i', $t) === 1 ? [1.0, 0.0] : [0.0, 1.0], $texts);
        };

        // The parent's prime, with the vector leg...
        $parent = $this->runtime()->withSessionPromptMemo($memo);
        $primed = $parent->primeMemoryRecall($this->app([Message::user('when do we ship?')]), 'when do we ship?', $embedder);
        self::assertSame('hybrid', $primed->mode());

        // ...is what a fresh Runtime on the same memo reads for the same
        // query, with no second ranking.
        $child = $this->runtime()->withSessionPromptMemo($memo);
        self::assertSame($primed, $child->memoryRecall($this->app([new UserMessage('when do we ship?')])));
        self::assertSame(1, $calls);

        // A different latest message re-ranks (keyword-only: no embedder here)
        // and takes the slot over.
        $next = $child->memoryRecall($this->app([new UserMessage('deploy to staging')]));
        self::assertNotSame($primed, $next);
        self::assertSame('keyword', $next->mode());
        self::assertSame($next, $child->memoryRecall($this->app([new UserMessage('deploy to staging')])));
    }

    public function testAnAppWithoutAStoreRecallsNothing(): void
    {
        $app = App::new(new ScriptedProvider([]), 'm')->withMessages([new UserMessage('deploy')]);

        self::assertSame('', $this->runtime()->memoryRecall($app)->render());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function runtime(): Runtime
    {
        return new Runtime(new ScriptedProvider([]), new HookManager(new HookRegistry()));
    }

    /** @param list<mixed> $messages */
    private function app(array $messages): App
    {
        return App::new(new ScriptedProvider([]), 'm')
            ->withRoot($this->dir . '/repo')
            ->withMemoryStore($this->store)
            ->withSessionId('s1')
            ->withMessages($messages);
    }
}
