<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Attachments\ContextMentions;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\ReviewCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
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

        // GIT-1: the mandate's other half — the class itself must document
        // the injection threat the mandate answers, so a reader of the
        // command sees the trust posture without visiting docs/CI.md.
        $doc = (string) (new \ReflectionClass(ReviewCommand::class))->getDocComment();
        self::assertStringContainsString('UNTRUSTED BY CONSTRUCTION', $doc, 'the class docblock lost its trust-boundary sentence — reviewing an adversarial repo feeds the LLM untrusted diff text and the file must say so (GIT-1)');
        self::assertStringContainsString('prompt-injection section of docs/CI.md', $doc, 'the trust sentence no longer routes to the doctrine page (GIT-2)');
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
}
