<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Context\MemoryRecallBlock;
use SugarCraft\Code\Context\SessionPromptMemo;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

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

    public function testARepoNoteGitDoesNotTrackIsBadgedAsRecordedHere(): void
    {
        $repoRoot = $this->dir . '/tracked-repo';
        mkdir($repoRoot . '/.candy-code/memory', 0700, true);
        exec('git -C ' . escapeshellarg($repoRoot) . ' init -q 2>&1', $initOut, $initCode);
        self::assertSame(0, $initCode, implode("\n", $initOut));

        $repo = MemoryStore::forRepository($repoRoot . '/.candy-code/memory');
        $shipped = $repo->add('Release tags are signed.', MemoryScope::Project);
        $identity = '-c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false';
        exec("git {$identity} -C " . escapeshellarg($repoRoot) . ' add -A 2>&1', $addOut, $addCode);
        self::assertSame(0, $addCode, implode("\n", $addOut));
        exec("git {$identity} -C " . escapeshellarg($repoRoot) . ' commit -q -m base 2>&1', $commitOut, $commitCode);
        self::assertSame(0, $commitCode, implode("\n", $commitOut));
        $recorded = $repo->add('Deploy tokens rotate weekly.', MemoryScope::Project);

        $rendered = MemoryRecallBlock::capture($this->store, $repo, 'sign the release tags; rotate deploy tokens')->render();

        self::assertStringContainsString("{$shipped} (shipped in this repository):", $rendered);
        self::assertStringContainsString("{$recorded} (recorded in this checkout, not committed):", $rendered,
            'an untracked note was saved by this checkout — the shipped badge misdescribes it (ISS-019)');
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

    // ── VIS-6: the per-turn recall row ────────────────────────────────

    /**
     * The priming door announces: one structured row per NEW non-empty
     * ranking, and nothing else. Since C-CTX-6.e the row is a frame
     * carrying the count plus the notes' TITLES — titles are first lines,
     * and note BODIES never travel (the row remains a transcript cue, not
     * a second copy of the prompt payload); ids stay out too. The visible
     * sentence is rebuilt at the pumps, so the frame itself holds none.
     * Re-priming the same set stays silent even under a different query,
     * an empty recall records no row at all, and the same set returning
     * after an empty turn speaks again.
     */
    public function testThePrimingDoorRecordsOneStructuredRowPerNewRanking(): void
    {
        $first = $this->store->add('Deploys go through staging.', MemoryScope::Project);
        $second = $this->store->add("Deploys happen on Tuesdays.\nThe on-call freeze lifts Fridays.", MemoryScope::Project);
        $runtime = $this->runtime()->withSessionPromptMemo(SessionPromptMemo::new());

        RuntimeNoticeSink::arm(false);
        try {
            RuntimeNoticeSink::drain();

            $runtime->primeMemoryRecall($this->app([Message::user('deploy it')]), 'deploy it', null);
            $rows = RuntimeNoticeSink::drain();
            self::assertCount(1, $rows, 'a non-empty ranking records exactly one row');
            self::assertTrue(RuntimeNoticeSink::isStructuredFrame($rows[0]), 'the row is a structured frame (C-CTX-6.e)');
            $frame = RuntimeNoticeSink::decodeStructured($rows[0]);
            self::assertNotNull($frame);
            self::assertSame(2, $frame['count']);
            self::assertContains('Deploys go through staging.', $frame['titles'], 'titles (first lines) travel — the row expands to them');
            self::assertContains('Deploys happen on Tuesdays.', $frame['titles'], 'a note title is its FIRST line');
            self::assertStringNotContainsString('freeze', $rows[0], 'bodies never travel: the second line stays in the fence');
            self::assertStringNotContainsString('Recalled', $rows[0], 'the sentence is built at the pump, not smuggled in the frame');
            self::assertStringNotContainsString($first, $rows[0]);
            self::assertStringNotContainsString($second, $rows[0]);

            $runtime->primeMemoryRecall($this->app([Message::user('deploy it')]), 'deploy it', null);
            self::assertSame([], RuntimeNoticeSink::drain(), 'the same set re-primed stays silent');

            $runtime->primeMemoryRecall($this->app([Message::user('anything at all qq')]), 'anything at all qq', null);
            self::assertSame([], RuntimeNoticeSink::drain(), 'an empty recall records no row');

            $runtime->primeMemoryRecall($this->app([Message::user('deploy it')]), 'deploy it', null);
            $again = RuntimeNoticeSink::drain();
            self::assertCount(1, $again, 'the set returning after an empty turn speaks again');
            self::assertSame(2, RuntimeNoticeSink::decodeStructured($again[0])['count']);
        } finally {
            RuntimeNoticeSink::reset();
        }
    }

    public function testASingleRecalledNoteReadsSingular(): void
    {
        $this->store->add('Deploys go through staging.', MemoryScope::Project);
        $runtime = $this->runtime()->withSessionPromptMemo(SessionPromptMemo::new());

        RuntimeNoticeSink::arm(false);
        try {
            RuntimeNoticeSink::drain();
            $runtime->primeMemoryRecall($this->app([Message::user('deploy it')]), 'deploy it', null);
            $rows = RuntimeNoticeSink::drain();
            self::assertCount(1, $rows);
            $frame = RuntimeNoticeSink::decodeStructured($rows[0]);
            self::assertNotNull($frame);
            self::assertSame(1, $frame['count']);
            self::assertStringContainsString('Recalled 1 note for this turn', RuntimeNoticeSink::recallRowText($frame['count']), 'the pump-side sentence keeps the .one grammar');
        } finally {
            RuntimeNoticeSink::reset();
        }
    }

    /**
     * VIS-6-P1: the row's number grammar is a CHOICE OF KEY, not an English
     * word smuggled into the sentence as a parameter — a `{notes}`-style
     * carrier would force every locale to ship English plurals inside the
     * translated string. The catalog therefore holds exactly `.one` and
     * `.many`, each carrying the count as a figure, and the bare key is gone
     * (its presence would be a second door for the row to speak through).
     */
    public function testTheRecallRowChoosesOneOrManyKeysAndInjectsNoEnglishPlural(): void
    {
        $catalog = require \dirname(__DIR__, 2) . '/lang/en.php';
        self::assertIsArray($catalog);

        self::assertArrayNotHasKey('chat.memory.recalled_notice', $catalog, 'the plural-injected spelling must not come back');
        foreach (['one', 'many'] as $form) {
            $line = $catalog['chat.memory.recalled_notice.' . $form] ?? null;
            self::assertIsString($line, 'the recall row needs a chat.memory.recalled_notice.' . $form . ' key');
            self::assertStringContainsString('{count}', $line, 'the count rides as a figure, never as a spelled word choice');
            self::assertStringNotContainsString('{notes}', $line, 'no English plural parameter may enter the translated sentence');
        }

        // And the call site picks between them: the shipped row for two
        // notes pluralises through the .many key (byte-identical English).
        self::assertSame(
            'Recalled 2 notes for this turn',
            Lang::t('chat.memory.recalled_notice.many', ['count' => 2]),
        );
        self::assertSame(
            'Recalled 1 note for this turn',
            Lang::t('chat.memory.recalled_notice.one', ['count' => 1]),
        );
    }

    /**
     * The cold-warmup half of VIS-6's silence law: the keyword-only re-prime
     * INSIDE {@see \SugarCraft\Code\Runtime::memoryRecall()} stores its
     * ranking exactly like the priming door but never announces — otherwise
     * every sessionless render path (background work, sub-agents, `-p`)
     * would stamp its own recall row into a turn the user never primed.
     */
    public function testTheSilentReprimeInsideMemoryRecallRecordsNothing(): void
    {
        $this->store->add('Deploys go through staging.', MemoryScope::Project);
        $runtime = $this->runtime()->withSessionPromptMemo(SessionPromptMemo::new());

        RuntimeNoticeSink::arm(false);
        try {
            RuntimeNoticeSink::drain();
            $block = $runtime->memoryRecall($this->app([Message::user('deploy it')]));
            self::assertNotSame('', $block->render(), 'the fallback still ranks');
            self::assertSame([], RuntimeNoticeSink::drain(), 'but the silent door does not announce');
        } finally {
            RuntimeNoticeSink::reset();
        }
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
