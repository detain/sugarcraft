<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Commands\ReviewCommand;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\ReviewHostCommand;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Workspace\GitRunner;

/**
 * C-WEB-2.g (GAP-GIT-10): `/review` and `/security-review` on a served
 * session. Both spellings carry the one body; the body validates a named ref
 * through the C-AGT-2 door before anything is billed, and the turn it starts
 * closes on the `@diff` mention so the host's own submit route attaches the
 * change set — the command never builds a diff itself.
 *
 * The served half runs on a real SessionHost over a real git repo: an
 * over-refused command must bill nothing, and an admitted one must land the
 * attachment on the model-visible user row exactly like the TUI's route.
 */
final class ReviewHostCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-reviewhost-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        self::wipeTree($this->dir);
    }

    private static function wipeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::wipeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    // ------------------------------------------------------------------
    // fixtures — uniquely named so no helper drifts against a twin file

    /** @return (\Closure(string): void) runner bound to one repo directory */
    private static function gitIn(string $dir): \Closure
    {
        return static function (string ...$args) use ($dir): void {
            $command = 'git -c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false'
                . ' -c init.defaultBranch=main -C ' . escapeshellarg($dir);
            foreach ($args as $arg) {
                $command .= ' ' . escapeshellarg($arg);
            }
            exec($command . ' 2>&1', $output, $status);
            self::assertSame(0, $status, 'git ' . implode(' ', $args) . ': ' . implode("\n", $output));
        };
    }

    private function requireGitServe(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not on PATH');
        }
    }

    /** One commit, then an UNCOMMITTED change: bare `@diff` carries the working diff. */
    private function serveRepoWithWorkingChange(): string
    {
        $this->requireGitServe();
        $root = $this->dir . '/work-' . bin2hex(random_bytes(4));
        mkdir($root, 0o777, true);
        $git = self::gitIn($root);
        $git('init', '-q');
        file_put_contents($root . '/f.txt', "first\n");
        $git('add', 'f.txt');
        $git('commit', '-qm', 'first');
        file_put_contents($root . '/f.txt', "first\nuncommitted\n");

        return $root;
    }

    /** `base` parked at the first commit, `main` one ahead: `base..HEAD` is a non-empty range. */
    private function serveRepoWithBaseBranch(): string
    {
        $this->requireGitServe();
        $root = $this->dir . '/range-' . bin2hex(random_bytes(4));
        mkdir($root, 0o777, true);
        $git = self::gitIn($root);
        $git('init', '-q');
        file_put_contents($root . '/f.txt', "first\n");
        $git('add', 'f.txt');
        $git('commit', '-qm', 'first');
        $git('branch', 'base');
        file_put_contents($root . '/f.txt', "first\nsecond\n");
        $git('commit', '-qam', 'second');

        return $root;
    }

    /** One commit only: every named ref that is not HEAD/refs must refuse. */
    private function serveRepoSingleCommit(): string
    {
        $this->requireGitServe();
        $root = $this->dir . '/solo-' . bin2hex(random_bytes(4));
        mkdir($root, 0o777, true);
        $git = self::gitIn($root);
        $git('init', '-q');
        file_put_contents($root . '/f.txt', "only\n");
        $git('add', 'f.txt');
        $git('commit', '-qm', 'only');

        return $root;
    }

    private function serveHost(string $root): SessionHost
    {
        $store = new EnhancedSessionStore($root . '/.serve-host.db');
        $store->createSession('s', 'p', 'm');

        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $store, backend: new EchoBackend(), root: $root),
            history: [],
            customCommands: [],
        );
    }

    private function drainServeHost(SessionHost $host): void
    {
        $loop = \React\EventLoop\Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(30.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertFalse($host->isBusy(), 'the served review turn settled');
    }

    /** @return list<Message> the model-visible user rows (mentions resolved, not UI echoes) */
    private static function servedUserRows(SessionHost $host): array
    {
        return array_values(array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->role === Role::User && !$m->uiOnly,
        ));
    }

    // ------------------------------------------------------------------

    public function testBothSpellingsCarryTheSharedBody(): void
    {
        foreach (['review', 'security-review'] as $spelling) {
            $spec = BuiltInCommands::forSpelling($spelling);
            self::assertNotNull($spec, "/{$spelling} is a built-in spelling");
            self::assertSame(
                ReviewHostCommand::class,
                $spec->hostCommand,
                "/{$spelling} must carry the review body on the server",
            );
        }
    }

    public function testTheEchoRowRidesBeforeThePromptEffect(): void
    {
        $result = (new ReviewHostCommand())->run(CommandContext::new(history: []), '/review');

        self::assertFalse($result->isRefused());
        self::assertCount(1, $result->rows, 'the command posts only its own echo row');
        self::assertSame('/review', $result->rows[0]->content);

        $effect = $result->effect(CommandEffectKind::SubmitPrompt);
        self::assertNotNull($effect, 'the canned prompt rides the submit effect');
        self::assertSame(
            ReviewCommand::prompt(),
            $effect->promptText(),
            'the served prompt is the same text the terminal handler submits',
        );
        self::assertStringEndsWith(ReviewCommand::DIFF_MENTION, $effect->promptText());
    }

    public function testTheSecuritySpellingSendsTheThreatBody(): void
    {
        $result = (new ReviewHostCommand())->run(CommandContext::new(history: []), '/security-review HEAD');

        $effect = $result->effect(CommandEffectKind::SubmitPrompt);
        self::assertNotNull($effect);
        self::assertSame(ReviewCommand::prompt('HEAD', true), $effect->promptText());
        self::assertStringContainsString('security review', $effect->promptText());
        self::assertStringEndsWith('@diff:HEAD', $effect->promptText());
    }

    public function testTheColonSpellingReachesTheSameBodyWithACleanArgument(): void
    {
        // CommandText's law: the colon separator is consumed, so the ref the
        // validator sees is `HEAD`, never `:HEAD` (which could never resolve).
        $result = (new ReviewHostCommand())->run(CommandContext::new(history: []), '/review:HEAD');

        self::assertFalse($result->isRefused());
        $effect = $result->effect(CommandEffectKind::SubmitPrompt);
        self::assertNotNull($effect);
        self::assertSame(ReviewCommand::prompt('HEAD'), $effect->promptText());
    }

    public function testABadRefRefusesAndBillsNothing(): void
    {
        $root = $this->serveRepoSingleCommit();
        $host = $this->serveHost($root);

        $result = $host->runCommand('review', 'nope-not-a-ref');

        self::assertTrue($result->isRefused(), 'a ref that cannot attach must not start a turn');
        self::assertStringContainsString('is not a git revision', (string) $result->error);
        self::assertFalse($host->isBusy(), 'no turn was billed');
        self::assertSame(
            [],
            self::servedUserRows($host),
            'nothing reached the model — the refusal is a command answer, not a turn',
        );
    }

    public function testARangeWithAnUnresolvableSideRefusesOnTheServePath(): void
    {
        $root = $this->serveRepoWithBaseBranch();
        $host = $this->serveHost($root);

        $result = $host->runCommand('review', 'missing..HEAD');

        self::assertTrue($result->isRefused(), 'refError pre-resolves EACH side before the turn');
        self::assertFalse($host->isBusy());
    }

    public function testAServedReviewTurnCarriesTheDiffAttachment(): void
    {
        $root = $this->serveRepoWithWorkingChange();
        $host = $this->serveHost($root);

        $host->runCommand('review');
        $this->drainServeHost($host);

        $echoes = array_values(array_filter(
            $host->history(),
            static fn (Message $m): bool => $m->uiOnly && $m->content === '/review',
        ));
        self::assertCount(1, $echoes, 'the draft echoes before the turn starts');

        $attached = array_values(array_filter(
            self::servedUserRows($host),
            static fn (Message $m): bool => $m->attachments !== [],
        ));
        self::assertCount(1, $attached, 'the submitted review prompt carries the mention attachment');
        $attachment = $attached[0]->attachments[0];
        self::assertSame(ReviewCommand::DIFF_MENTION, $attachment->path);
        self::assertStringContainsString('+uncommitted', (string) $attachment->data);
        self::assertStringContainsString(
            ReviewCommand::DIFF_MENTION,
            $attached[0]->content,
            'the prompt closed on the mention the scanner resolved',
        );
    }

    public function testAServedSecurityReviewTurnSendsTheThreatBody(): void
    {
        $root = $this->serveRepoWithWorkingChange();
        $host = $this->serveHost($root);

        $host->runCommand('security-review');
        $this->drainServeHost($host);

        $attached = array_values(array_filter(
            self::servedUserRows($host),
            static fn (Message $m): bool => $m->attachments !== [],
        ));
        self::assertCount(1, $attached);
        self::assertStringContainsString('security review', $attached[0]->content);
        self::assertStringContainsString('+uncommitted', (string) $attached[0]->attachments[0]->data);
    }

    public function testARangeFormAttachesANonEmptyDiff(): void
    {
        // C-AGT-2/C-AGT-6.b on the serve path: the range passes the
        // per-side door and the scanner attaches the COMMITTED diff of the
        // range — not the (empty) working tree.
        $root = $this->serveRepoWithBaseBranch();
        $host = $this->serveHost($root);

        $host->runCommand('review', 'base..HEAD');
        $this->drainServeHost($host);

        $attached = array_values(array_filter(
            self::servedUserRows($host),
            static fn (Message $m): bool => $m->attachments !== [],
        ));
        self::assertCount(1, $attached);
        self::assertSame('@diff:base..HEAD', $attached[0]->attachments[0]->path);
        self::assertStringContainsString('+second', (string) $attached[0]->attachments[0]->data);
    }

    public function testAReadOnlySessionRefusesBothSpellings(): void
    {
        $context = CommandContext::new(history: [], readOnly: true);

        $review = (new ReviewHostCommand())->run($context, '/review');
        self::assertTrue($review->isRefused());
        self::assertStringContainsString('/review was not run', (string) $review->error);

        $security = (new ReviewHostCommand())->run($context, '/security-review');
        self::assertTrue($security->isRefused());
        self::assertStringContainsString('/security-review was not run', (string) $security->error,
            'the refusal names the spelling that was typed, not a hard-coded one');
        self::assertNull($security->effect(CommandEffectKind::SubmitPrompt), 'the refusal starts no turn');
    }
}
