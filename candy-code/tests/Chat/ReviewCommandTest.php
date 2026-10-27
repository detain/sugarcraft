<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Attachments\ContextMentions;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\ReviewCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Role;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workspace\GitRunner;

/**
 * Roadmap GIT-1: `/review [ref]` and `/security-review [ref]` send canned
 * review prompts as turns, closing on the `@diff` mention so the scanner —
 * not the command — attaches the change set. C-AGT-2 (GIT-1-P1): a named
 * ref must survive the scanner's own trim round trip AND resolve to a
 * tree-ish, or the command refuses it before a paid turn is billed.
 */
final class ReviewCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-review-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    private function submit(string $draft): Chat
    {
        return $this->submitAtRoot($draft, null);
    }

    /** @param string|null $root the project root the review resolves against */
    private function submitAtRoot(string $draft, ?string $root): Chat
    {
        $chat = (new Chat(inputBuf: $draft, backend: new EchoBackend(), projectRoot: $root))->withSize(100, 30);
        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** The submitted model-visible user row — it carries the attached diff. */
    private function submittedRow(Chat $chat): Message
    {
        foreach (array_reverse($chat->history) as $row) {
            if ($row->role === Role::User && $row->attachments !== []) {
                return $row;
            }
        }

        self::fail('the accepted review turn carries no attachment');
    }

    /**
     * A repo whose reflog has two positions, so `HEAD@{1}` names the first
     * commit and the working diff against it shows `+second`.
     */
    private function reviewRepoWithTwoCommits(): string
    {
        $this->requireGit();
        $root = $this->sandbox . '/repo-' . bin2hex(random_bytes(4));
        mkdir($root, 0o777, true);
        $this->runReviewGit($root, 'init', '-q');
        file_put_contents($root . '/f.txt', "first\n");
        $this->runReviewGit($root, 'add', 'f.txt');
        $this->runReviewGit($root, 'commit', '-qm', 'first');
        file_put_contents($root . '/f.txt', "first\nsecond\n");
        $this->runReviewGit($root, 'commit', '-qam', 'second');

        return $root;
    }

    /** One commit, no remote — `@{u}` cannot resolve here. */
    private function reviewRepoWithOneCommitNoRemote(): string
    {
        $this->requireGit();
        $root = $this->sandbox . '/solo-' . bin2hex(random_bytes(4));
        mkdir($root, 0o777, true);
        $this->runReviewGit($root, 'init', '-q');
        file_put_contents($root . '/f.txt', "only\n");
        $this->runReviewGit($root, 'add', 'f.txt');
        $this->runReviewGit($root, 'commit', '-qm', 'only');

        return $root;
    }

    /**
     * A work repo tracking a local bare remote: `@{u}` resolves, and a
     * second local commit keeps the upstream diff non-empty.
     */
    private function reviewRepoWithUpstream(): string
    {
        $this->requireGit();
        $base = $this->sandbox . '/up-' . bin2hex(random_bytes(4));
        mkdir($base . '/remote', 0o777, true);
        mkdir($base . '/work', 0o777, true);
        $this->runReviewGit($base . '/remote', 'init', '-q', '--bare');
        $root = $base . '/work';
        $this->runReviewGit($root, 'init', '-q');
        file_put_contents($root . '/f.txt', "pushed\n");
        $this->runReviewGit($root, 'add', 'f.txt');
        $this->runReviewGit($root, 'commit', '-qm', 'pushed');
        $this->runReviewGit($root, 'remote', 'add', 'origin', $base . '/remote');
        $this->runReviewGit($root, 'push', '-q', '-u', 'origin', 'main');
        file_put_contents($root . '/f.txt', "pushed\nahead\n");
        $this->runReviewGit($root, 'commit', '-qam', 'ahead');

        return $root;
    }

    private function runReviewGit(string $dir, string ...$args): void
    {
        $command = 'git -c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false'
            . ' -c init.defaultBranch=main -C ' . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $status);
        self::assertSame(0, $status, 'git ' . implode(' ', $args) . ": " . implode("\n", $output));
    }

    private function requireGit(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not on PATH');
        }
    }

    /** @return list<string> */
    private static function userPrompts(Chat $chat): array
    {
        return array_values(array_map(
            static fn(Message $m): string => $m->content,
            array_filter($chat->history, static fn(Message $m): bool => $m->role === Role::User),
        ));
    }

    public function testReviewStartsATurnClosingOnTheDiffMention(): void
    {
        $next = $this->submit('/review');

        self::assertTrue($next->inFlight, '/review starts a turn like /init');
        $prompts = self::userPrompts($next);
        self::assertSame([ReviewCommand::prompt()], $prompts);
        self::assertStringEndsWith(ReviewCommand::DIFF_MENTION, $prompts[0]);
        self::assertSame('', $next->inputBuf);
        // C-AGT-2.b: the clamp is gate-only — a session with no gate (every
        // embedder, most tests) submits the review exactly as before.
        self::assertNull($next->currentPermissionMode(), 'a gateless chat clamps nothing');
    }

    public function testSecurityReviewSendsTheThreatBody(): void
    {
        $next = $this->submit('/security-review');

        self::assertTrue($next->inFlight);
        $prompts = self::userPrompts($next);
        self::assertSame([ReviewCommand::prompt('', true)], $prompts);
        self::assertStringContainsString('security review', $prompts[0]);
        self::assertStringEndsWith(ReviewCommand::DIFF_MENTION, $prompts[0]);
    }

    public function testARangeRefStartsNoTurnAndAnswersWithTheBadRefRow(): void
    {
        // C-AGT-2 (GIT-1-P1): `main..HEAD` is not a tree-ish — `diff-index`
        // takes ONE, so the mention never attached and the review still
        // billed. Until C-AGT-6.b re-admits ranges properly, the command
        // refuses the form before submitting.
        $next = $this->submit('/review main..HEAD');

        self::assertFalse($next->inFlight, 'a range must not begin a turn');
        $last = $next->history[count($next->history) - 1];
        self::assertSame(Role::Assistant, $last->role);
        self::assertStringContainsString('is not a git revision', $last->content);
        // The command echo is itself a user row; what must be absent is the
        // review PROMPT row — the turn that would have been billed.
        self::assertNotContains(
            ReviewCommand::prompt('main..HEAD'),
            self::userPrompts($next),
            'no review prompt was submitted',
        );
    }

    public function testAReflogAddressScopesTheClosingMentionAndAttaches(): void
    {
        // C-AGT-2: `HEAD@{1}` ends in a brace. The trim used to eat it, the
        // scanner mangled the ref, and the turn billed against nothing; the
        // scanner now keeps the closing brace and the diff rides for real.
        $root = $this->reviewRepoWithTwoCommits();
        $next = $this->submitAtRoot('/review HEAD@{1}', $root);

        self::assertTrue($next->inFlight);
        $prompts = self::userPrompts($next);
        self::assertCount(1, $prompts);
        self::assertStringEndsWith('@diff:HEAD@{1}', $prompts[0]);
        self::assertSame('@diff:HEAD@{1}', $this->submittedRow($next)->attachments[0]->path);
        self::assertStringContainsString('+second', (string) $this->submittedRow($next)->attachments[0]->data);
    }

    public function testTheUpstreamRefAttachesInsideARepoThatHasAnUpstream(): void
    {
        // `@{u}` is the tracked-branch spelling — same brace, same law.
        $root = $this->reviewRepoWithUpstream();
        $next = $this->submitAtRoot('/review @{u}', $root);

        self::assertTrue($next->inFlight);
        $prompts = self::userPrompts($next);
        self::assertStringEndsWith('@diff:@{u}', $prompts[0]);
        self::assertSame('@diff:@{u}', $this->submittedRow($next)->attachments[0]->path);
    }

    public function testTheUpstreamSpellingWithoutAnUpstreamIsRefusedBeforeTheTurn(): void
    {
        // C-AGT-2's second gate: the spelling is valid but the revision is
        // not there — `ReviewCommand::refError()` pre-resolves it, so this
        // answers loudly instead of billing a turn whose diff never attaches.
        $root = $this->reviewRepoWithOneCommitNoRemote();
        $next = $this->submitAtRoot('/review @{u}', $root);

        self::assertFalse($next->inFlight, 'an unresolvable ref must not begin a turn');
        $last = $next->history[count($next->history) - 1];
        self::assertSame(Role::Assistant, $last->role);
        self::assertStringContainsString('is not a git revision', $last->content);
    }

    public function testARefTheScannerWouldRefuseStartsNoTurn(): void
    {
        // Leading dash: the scanner's pattern refuses it so a ref can never
        // read as an option; the command refuses with the same pattern, one
        // layer earlier, and answers loudly instead of reviewing nothing.
        $next = $this->submit('/review --uploadpack');

        self::assertFalse($next->inFlight, 'a bad ref must not begin a turn');
        $last = $next->history[count($next->history) - 1];
        self::assertSame(Role::Assistant, $last->role);
        self::assertStringContainsString('is not a git revision', $last->content);
    }

    public function testBothPromptsMandateReportNotEdit(): void
    {
        // The mandate is the product law of the command, not decoration:
        // it rides in the constants so every prompt carries it.
        self::assertStringContainsString('Report, do not edit', ReviewCommand::REVIEW_PROMPT);
        self::assertStringContainsString('Report, do not edit', ReviewCommand::SECURITY_PROMPT);
        self::assertStringEndsWith('%s', rtrim(ReviewCommand::REVIEW_PROMPT));
        self::assertStringEndsWith('%s', rtrim(ReviewCommand::SECURITY_PROMPT));

        // C-AGT-2.b retired this pin's docblock half: the mandate's other
        // half is now enforced ACTION-side, so the proof moved from prose
        // to the behavioral trio below (a bypass-mode `/review` whose tool
        // call the plan clamp physically refuses).
    }

    public function testIsDiffRefAgreesWithTheScannerRoundTrip(): void
    {
        self::assertTrue(ContextMentions::isDiffRef('HEAD'));
        self::assertTrue(ContextMentions::isDiffRef('0a9b8c7'));
        // C-AGT-2: the check is the scanner's OWN round trip now, so a ref
        // whose closing brace the trim would eat — and then give back — is
        // spellable exactly as typed, and anything the trim WOULD change is
        // refused instead of billed-then-noticed.
        self::assertTrue(ContextMentions::isDiffRef('HEAD@{1}'), 'the trim keeps a reflog brace');
        self::assertTrue(ContextMentions::isDiffRef('@{u}'), 'the upstream spelling is one token');
        self::assertFalse(ContextMentions::isDiffRef('HEAD,'), 'a comma would be trimmed away');
        self::assertFalse(ContextMentions::isDiffRef('HEAD@{1'), 'an unclosed brace is not a ref');
        self::assertFalse(ContextMentions::isDiffRef('main..HEAD'), 'diff-index takes one tree-ish; ranges wait for C-AGT-6.b');
        self::assertFalse(ContextMentions::isDiffRef(''), 'an empty ref never reaches this call, but the pattern rejects it anyway');
        self::assertFalse(ContextMentions::isDiffRef('-x'), 'a leading dash could read as an option');
        self::assertFalse(ContextMentions::isDiffRef('two words'), 'a spaced ref is not one revision');
    }

    // =====================================================================
    // C-AGT-2.b: the plan clamp is action-side law, not prose
    // =====================================================================

    public function testAReviewTurnInBypassModeRefusesTheWritesToolCallUnderTheClamp(): void
    {
        $this->requireFork();
        $chat = new Chat(
            inputBuf: '/review',
            backend: self::gatedEngine(self::writeCallScript(), PermissionMode::BypassPermissions),
            liveToolEvents: new \ArrayObject(),
            projectRoot: $this->sandbox,
        );

        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertSame(PermissionMode::Plan, $running->currentPermissionMode(), 'the review turn steps the gate down to plan');
        self::assertSame(PermissionMode::BypassPermissions, self::restoreField($running), 'the clamp remembers what to give back');
        self::assertNotEmpty(array_filter(self::transcriptTexts($running), static fn (string $t): bool => str_starts_with($t, 'Reviewing read-only')), 'the clamp announces itself in the transcript');

        $box = $this->start($cmd);
        $this->runUntil(static fn (): bool => $box->msg !== null);
        self::assertNotNull($box->msg, 'the clamped review turn never settled');
        $done = $this->apply($running, $box->msg);

        // The refusal is the hook DENY landing as the call's error row;
        // structural matching (a row BEGINNING with the hook's own framing)
        // keeps the pin honest even while the turn-context echoes this
        // file's own diff into a user row.
        $refused = array_filter(
            self::transcriptTexts($done),
            static fn (string $t): bool => str_starts_with($t, 'Tool error: Hook denied: Permission mode') && str_contains($t, "does not allow Write"),
        );
        self::assertNotEmpty($refused, 'the reviewing model physically could not Write');
        self::assertSame('', self::toolRowsText($done), 'the refused call never executed');
        self::assertContains('done', self::transcriptTexts($done), 'the review answer still landed');
        self::assertFalse($done->inFlight);
        self::assertSame(PermissionMode::BypassPermissions, $done->currentPermissionMode(), 'the settle gives the prior mode back');
        self::assertNull(self::restoreField($done), 'the restore disarms');
    }

    public function testTheNextOrdinaryTurnRunsAgainUnderTheRestoredBypassMode(): void
    {
        $this->requireFork();
        $chat = new Chat(
            inputBuf: '/review',
            backend: self::gatedEngine(self::writeCallScript(), PermissionMode::BypassPermissions),
            liveToolEvents: new \ArrayObject(),
            projectRoot: $this->sandbox,
        );
        [$running, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $first = $this->start($cmd);
        $this->runUntil(static fn (): bool => $first->msg !== null);
        self::assertNotNull($first->msg);
        $settled = $this->apply($running, $first->msg);
        $mark = count($settled->history);

        foreach (mb_str_split('again') as $char) {
            [$settled] = $settled->update(new KeyMsg(KeyType::Char, $char));
        }
        [$second, $cmd2] = $settled->update(new KeyMsg(KeyType::Enter, ''));
        self::assertSame(PermissionMode::BypassPermissions, $second->currentPermissionMode(), 'an ordinary turn clamps nothing');

        $next = $this->start($cmd2);
        $this->runUntil(static fn (): bool => $next->msg !== null);
        self::assertNotNull($next->msg, 'the turn after the review never settled');
        $done = $this->apply($second, $next->msg);

        $rows = self::toolRowsText($done, $mark);
        self::assertStringContainsString('ran', $rows, 'the restored bypass mode runs the call the review turn was refused');
        $refusals = array_filter(
            self::transcriptTexts($done, $mark),
            static fn (string $t): bool => str_starts_with($t, 'Tool error: Hook denied: Permission mode'),
        );
        self::assertSame([], $refusals, 'the ordinary turn was never refused');
        self::assertSame(PermissionMode::BypassPermissions, $done->currentPermissionMode());
    }

    public function testACancelledReviewTurnGivesTheModeBackToo(): void
    {
        // The cancel arm of the Escape handler releases the turn through
        // Chat::mutate() like every other settle, and the release guard in
        // mutate() is where the clamp restores — no fork is started here on
        // purpose: the child-kill machinery is pinned by ChatCancelKeepsLoopLiveTest.
        $chat = new Chat(
            inputBuf: '/review',
            backend: self::gatedEngine(self::writeCallScript(), PermissionMode::BypassPermissions),
            liveToolEvents: new \ArrayObject(),
            projectRoot: $this->sandbox,
        );
        [$running] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertSame(PermissionMode::Plan, $running->currentPermissionMode(), 'fixture: the clamp stepped down');

        [$armed] = $running->update(new KeyMsg(KeyType::Escape));
        self::assertTrue($armed->inFlight, 'the first Escape only arms the cancel');
        [$cancelled] = $armed->update(new KeyMsg(KeyType::Escape));

        self::assertFalse($cancelled->inFlight);
        self::assertStringContainsString('_Request cancelled._', implode("\n", self::transcriptTexts($cancelled)));
        self::assertSame(PermissionMode::BypassPermissions, $cancelled->currentPermissionMode(), 'the cancel gives the mode back — a stranded plan clamp would cage the session');
        self::assertNull(self::restoreField($cancelled), 'the restore disarms');
    }

    public function testAnAlreadyPlanSessionIsLeftExactlyAsItWas(): void
    {
        $chat = new Chat(
            inputBuf: '/review',
            backend: self::gatedEngine(self::writeCallScript(), PermissionMode::Plan),
            liveToolEvents: new \ArrayObject(),
            projectRoot: $this->sandbox,
        );
        [$running] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        self::assertSame(PermissionMode::Plan, $running->currentPermissionMode());
        self::assertNull(self::restoreField($running), 'nothing to restore: the session already read-only');
        self::assertSame([], array_filter(self::transcriptTexts($running), static fn (string $t): bool => str_starts_with($t, 'Reviewing read-only') || str_starts_with($t, 'The permission mode changed')), 'no announce, no mode rows');
    }

    public function testADontAskSessionIsNotTradedIntoPlan(): void
    {
        // Plan-vs-dont-ask is a strictness trade (one cages writes but asks
        // nothing, the other answers every question with a refusal); the
        // clamp must not silently make it.
        $chat = new Chat(
            inputBuf: '/review',
            backend: self::gatedEngine(self::writeCallScript(), PermissionMode::DontAsk),
            liveToolEvents: new \ArrayObject(),
            projectRoot: $this->sandbox,
        );
        [$running] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        self::assertSame(PermissionMode::DontAsk, $running->currentPermissionMode(), 'dont-ask stays dont-ask');
        self::assertNull(self::restoreField($running));
        self::assertSame([], array_filter(self::transcriptTexts($running), static fn (string $t): bool => str_starts_with($t, 'Reviewing read-only') || str_starts_with($t, 'The permission mode changed')), 'the strictness trade is never silently made');
    }

    /** @return list<CompleteResponse> two turns, each a Write call then the answer */
    private static function writeCallScript(): array
    {
        $call = new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'Write', ['file_path' => 'a.txt', 'content' => 'x'])]);
        $answer = new CompleteResponse(content: 'done');

        return [$call, $answer, $call, $answer];
    }

    private static function gatedEngine(array $script, PermissionMode $mode): EngineBackend
    {
        return EngineBackend::new(new ScriptedProvider($script), 'm')
            ->withTools([self::writeToolRan()])
            ->withoutHooks()
            ->withPermissionGate(new PermissionGate($mode));
    }

    private static function writeToolRan(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'Write'; }
            public function description(): string { return 'says it ran'; }
            public function inputSchema(): array { return []; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call', content: 'ran');
            }
        };
    }

    /** @return list<string> the content of every row, from $forward (offset or negative tail) */
    private static function transcriptTexts(Chat $chat, int $forward = 0): array
    {
        return array_map(static fn (Message $m): string => $m->content, array_slice($chat->history, $forward));
    }

    /** @return string every tool-result row's text, from $forward */
    private static function toolRowsText(Chat $chat, int $forward = 0): string
    {
        $rows = [];
        foreach (array_slice($chat->history, $forward) as $message) {
            foreach ($message->toolResults as $result) {
                $rows[] = (string) $result->result;
            }
        }

        return implode("\n", $rows);
    }

    /** The pending `/review` restore — private state, read structurally. */
    private static function restoreField(Chat $chat): ?PermissionMode
    {
        return (new \ReflectionProperty(Chat::class, 'reviewModeRestore'))->getValue($chat);
    }

    private function requireFork(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('an engine turn asks over completeAsync()\'s fork, which needs ext-pcntl.');
        }
    }

    /**
     * Start the turn's Cmd and capture the Msg its promise settles to.
     */
    private function start(?\Closure $cmd): \stdClass
    {
        self::assertNotNull($cmd);
        $box = new \stdClass();
        $box->msg = null;
        $pending = [$cmd];
        $found = false;
        while (($next = array_shift($pending)) !== null) {
            $out = $next();
            if ($out instanceof BatchMsg) {
                array_push($pending, ...$out->cmds);
            } elseif ($out instanceof AsyncCmd) {
                $found = true;
                $out->promise->then(static function (mixed $msg) use ($box): void {
                    $box->msg = $msg;
                });
            }
        }
        self::assertTrue($found, 'submitting started no async turn');

        return $box;
    }

    private function runUntil(\Closure $done, float $seconds = 15.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$done() && microtime(true) < $deadline) {
            $this->tick();
        }
    }

    private function tick(): void
    {
        $loop = Loop::get();
        $timer = $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($timer);
    }

    /**
     * Fold a settled turn's Msg the way Program::runCmd() does.
     */
    private function apply(Chat $chat, Msg $msg): Chat
    {
        $next = $msg;
        for ($i = 0; $i < 32 && $next !== null; $i++) {
            [$chat, $cmd] = $chat->update($next);
            $next = null;
            if ($cmd !== null) {
                $produced = $cmd();
                if ($produced instanceof Msg && !$produced instanceof AsyncCmd && !$produced instanceof BatchMsg) {
                    $next = $produced;
                }
            }
        }

        return $chat;
    }
}
