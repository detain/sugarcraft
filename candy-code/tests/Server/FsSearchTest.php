<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Message;
use SugarCraft\Code\Protocol\Methods\FsMethods;
use SugarCraft\Code\Protocol\Schema\MethodSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Workspace\GitRunner;

/**
 * `fs.search` (plan_comparison WEB-5b): the file-name listing behind the web
 * composer's `@`-mention popup. Two things are pinned here: the popup's PARITY
 * with the TUI index (a git-backed workspace answers through the very
 * MentionIndex the terminal ranks with), and the walk's JAIL (a non-git
 * workspace is walked, never escaped — every answer is root-relative, dot
 * internals and links stay out, and the caps cut the list, not the server).
 * The third pin is C-WEB-1.c's serve half: the git listing the popup answers
 * from refreshes when a turn ENDS, with no test-side memo reset doing it.
 */
final class FsSearchTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        MentionIndex::forget();
    }

    protected function tearDown(): void
    {
        MentionIndex::forget();
        $this->fixture->tearDown();
    }

    public function testAnEmptyQueryListsTheFrontOfAPlainWorkspaceInSortedOrder(): void
    {
        $this->plant(['src/Widget.php', 'src/chat.php', 'README.md', 'docs/guide.md']);

        $answer = $this->search([]);

        self::assertSame($this->fixture->root, $answer['root']);
        self::assertSame(['README.md', 'docs/guide.md', 'src/Widget.php', 'src/chat.php'], $answer['matches']);
        self::assertFalse($answer['truncated']);
        self::assertSame([], ProtocolSchema::errors($answer, MethodSchemas::result('fs.search'), 'fs.search'));
    }

    public function testTheQueryRanksTheBasenameHitAboveADeepPathHit(): void
    {
        $this->plant(['index.php', 'lib/vendor/index-thing.css', 'deep/nested/index/notes.md']);

        $answer = $this->search(['query' => 'index']);

        self::assertSame('index.php', $answer['matches'][0], 'the shallower basename belongs first, as in the terminal popup');
        self::assertContains('deep/nested/index/notes.md', $answer['matches']);
    }

    public function testTheWalkSkipsDotEntriesAndNeverLeavesTheWorkspace(): void
    {
        $outside = $this->fixture->dir . '/elsewhere';
        \mkdir($outside, 0o700, true);
        \file_put_contents($outside . '/secret.txt', 'not yours');
        $this->plant(['app.php', '.env', '.git/HEAD', 'keep/me.txt']);
        \symlink($outside . '/secret.txt', $this->fixture->root . '/link-out');
        \symlink($outside, $this->fixture->root . '/dir-out');

        $answer = $this->search([]);

        self::assertSame(['app.php', 'keep/me.txt'], $answer['matches']);
        foreach ($answer['matches'] as $path) {
            self::assertEscapeStaysJailed($path);
        }
    }

    public function testShowHiddenAdmitsDotFilesButNeverTheLinkEscape(): void
    {
        $this->plant(['app.php', '.env']);
        \symlink('/etc/passwd', $this->fixture->root . '.hidden-link');

        self::assertSame(['.env', 'app.php'], $this->search(['showHidden' => true])['matches']);
        self::assertSame(['app.php'], $this->search([])['matches']);
    }

    public function testTheLimitCutsAndSaysSo(): void
    {
        $this->plant(\array_map(static fn (int $i): string => \sprintf('f%02d.txt', $i), \range(1, 30)));

        $answer = $this->search(['limit' => 5]);

        self::assertCount(5, $answer['matches']);
        self::assertTrue($answer['truncated']);
        self::assertCount(FsMethods::SEARCH_DEFAULT_LIMIT, $this->search([])['matches']);
    }

    public function testTheLimitIsBoundedBeforeTheMethodRuns(): void
    {
        $this->plant(['a.txt']);

        foreach ([0, FsMethods::SEARCH_MAX_LIMIT + 1] as $limit) {
            $refused = $this->fixture->client()->request('fs.search', ['limit' => $limit]);
            self::assertSame(-32602, $refused['error']['code'] ?? null, "limit {$limit} must be refused by the params schema");
        }
    }

    public function testAGitBackedWorkspaceAnswersFromTheTerminalIndexItself(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not available');
        }
        // Ignored AND untracked-but-not-ignored files ride the same listing
        // the TUI popup shows: only what git considers present is searchable.
        $this->plant(['src/Chat.php', 'search_bar.rs', '.env']);
        \file_put_contents($this->fixture->root . '/.gitignore', ".env\n");
        \exec('git init --quiet ' . \escapeshellarg($this->fixture->root) . ' 2>&1');

        $answer = $this->search(['query' => 'chbar']);

        // Subsequence ranking (the TUI's matcher), NOT the walk's substring
        // screen: `chbar` is no substring of search_bar.rs yet the matcher
        // floats it — proof the git-backed root answers from the popup's own
        // index rather than a re-walk. .env stays out: git ignores it.
        self::assertSame(['search_bar.rs'], $answer['matches']);
    }

    /**
     * C-WEB-1.c: a file written while a turn is open is absent from the git
     * listing, and the turn ENDING is what re-lists it — this body never
     * touches {@see MentionIndex::forget()}; the only resets in the file are
     * setUp/tearDown hygiene, so nothing but {@see TurnRunner::complete()}'s
     * first statement can be doing the refresh.
     */
    public function testAGitWorkspaceReListsWhenATurnCompletes(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not available');
        }
        $this->plant(['first-note.md']);
        \exec('git init --quiet ' . \escapeshellarg($this->fixture->root) . ' 2>&1');

        // The first answer primes the memo, exactly like a session's first `@`.
        self::assertSame(['first-note.md'], $this->search(['query' => 'first'])['matches']);

        $runner = TurnRunner::new();
        $cancellation = new CancellationToken();
        $backend = self::backendWithOneTurn();
        $run = $runner->start(
            backend: $backend,
            history: [Message::user('create a note')],
            inbox: new \ArrayObject(),
            generation: 1,
            cancellation: $cancellation,
        );
        $run();

        // Mid-turn the write exists on disk but not in the memoized listing.
        $this->plant(['second-note.md']);
        self::assertSame([], $this->search(['query' => 'second'])['matches'], 'the memo holds while the turn is open');

        $backend->answer(Message::assistant('both notes exist now'));

        self::assertSame(['second-note.md'], $this->search(['query' => 'second'])['matches'], 'closing the turn re-listed the root for the next popup');
    }

    /**
     * A backend whose one turn the test answers by hand, so the runner's real
     * settle (and through it, its refresh) runs without a socket or a model.
     */
    private static function backendWithOneTurn(): Backend
    {
        return new class () implements Backend {
            public ?Deferred $deferred = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->deferred = new Deferred();

                return $this->deferred->promise();
            }

            public function answer(Message $reply): void
            {
                $this->deferred?->resolve($reply);
            }
        };
    }

    /**
     * @param list<string> $relativePaths
     */
    private function plant(array $relativePaths): void
    {
        foreach ($relativePaths as $relative) {
            $absolute = $this->fixture->root . '/' . $relative;
            if (!\is_dir(\dirname($absolute))) {
                \mkdir(\dirname($absolute), 0o700, true);
            }
            \file_put_contents($absolute, $relative);
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function search(array $params): array
    {
        return $this->fixture->client()->call('fs.search', $params);
    }

    private static function assertEscapeStaysJailed(string $path): void
    {
        self::assertStringStartsNotWith('/', $path);
        self::assertStringNotContainsString('..', $path);
        self::assertMatchesRegularExpression('#^(?!\.git/).*$#', $path);
    }
}
