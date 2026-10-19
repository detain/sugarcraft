<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `-c`/`--continue` and `--resume [<id|name>]`: which session a TUI launch
 * opens. Without either, every launch opens a new one.
 *
 * Clears {@see Bootstrap::useSessionLaunch()} in setUp() AND tearDown(), per
 * the clear-what-you-set contract on {@see Bootstrap::useModel()}.
 */
final class SessionLaunchFlagsTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmpHome;

    protected function setUp(): void
    {
        Bootstrap::useSessionLaunch(false);
        $this->tmpHome = sys_get_temp_dir() . '/crush-session-launch-' . bin2hex(random_bytes(6));
        mkdir($this->tmpHome . '/.candy-code', 0700, true);
        $this->useHomeSandbox($this->tmpHome);
    }

    protected function tearDown(): void
    {
        Bootstrap::useSessionLaunch(false);
        $this->restoreHomeSandbox();
        foreach (glob($this->tmpHome . '/.candy-code/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpHome . '/.candy-code');
        @rmdir($this->tmpHome);
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->tmpHome . '/.candy-code/session.db');
    }

    /** @return iterable<string, array{list<string>, bool, bool, ?string}> */
    public static function spellings(): iterable
    {
        yield 'none' => [['candy-code'], false, false, null];
        yield '--continue' => [['candy-code', '--continue'], true, false, null];
        yield '-c' => [['candy-code', '-c'], true, false, null];
        yield '--resume <id>' => [['candy-code', '--resume', 'abc123'], false, true, 'abc123'];
        yield '--resume=<id>' => [['candy-code', '--resume=abc123'], false, true, 'abc123'];
        yield 'bare --resume' => [['candy-code', '--resume'], false, true, null];
        yield '--resume= (empty)' => [['candy-code', '--resume='], false, true, null];
        yield '--resume before a flag' => [['candy-code', '--resume', '--model', 'x'], false, true, null];
        yield 'root, then --resume' => [['candy-code', './app', '--resume'], false, true, null];
    }

    /** @param list<string> $argv */
    #[DataProvider('spellings')]
    public function testEverySpellingParses(array $argv, bool $continue, bool $resume, ?string $target): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertSame([], $args->unknownFlags);
        $this->assertNull($args->usageError);
        $this->assertSame($continue, $args->continueSession);
        $this->assertSame($resume, $args->resumeRequested);
        $this->assertSame($target, $args->resumeSession);
    }

    public function testTheRootStillParsesBesideTheFlag(): void
    {
        $this->assertSame('./app', ArgvParser::parse(['candy-code', './app', '--resume'])->root);
        $this->assertSame('x', ArgvParser::parse(['candy-code', '--resume', '--model', 'x'])->model);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function refusals(): iterable
    {
        yield 'both flags' => [['candy-code', '-c', '--resume', 'x'], '--continue and --resume'];
        yield 'bare --resume with -p' => [['candy-code', '-p', 'hi', '--resume'], 'a bare --resume opens the interactive session picker'];
        yield '--resume with run' => [['candy-code', 'run', 'hi', '--resume'], 'a bare --resume opens the interactive session picker'];
    }

    /**
     * AUT-2: session flags beside a prompt went from refused to supported —
     * NonInteractive::run() reuses the TUI's openSession funnel, seeds the
     * stored transcript, and saves the answer back. Only a BARE --resume (no
     * target to resume, picker unshowable in a one-shot) stays a usage error;
     * the two-flags collision above stays too.
     */
    public function testSessionFlagsBesideAPromptParseAsOneShotContinuations(): void
    {
        $continue = ArgvParser::parse(['candy-code', '-c', '-p', 'hi']);
        $this->assertNull($continue->usageError);
        $this->assertTrue($continue->promptRequested);
        $this->assertTrue($continue->continueSession);

        $resume = ArgvParser::parse(['candy-code', 'run', 'hi', '--resume', 'abc']);
        $this->assertNull($resume->usageError);
        $this->assertTrue($resume->promptRequested);
        $this->assertSame('abc', $resume->resumeSession);

        $attached = ArgvParser::parse(['candy-code', '-p', 'hi', '--resume=abc']);
        $this->assertNull($attached->usageError);
        $this->assertSame('abc', $attached->resumeSession);
    }

    public function testTheBareResumeHintNamesBothRemedies(): void
    {
        $args = ArgvParser::parse(['candy-code', '-p', 'hi', '--resume']);

        $this->assertStringContainsString('session list', (string) $args->usageHint);
        $this->assertStringContainsString('drop -p', (string) $args->usageHint);
    }

    /** @param list<string> $argv */
    #[DataProvider('refusals')]
    public function testIncompatibleCombinationsAreUsageErrors(array $argv, string $needle): void
    {
        $args = ArgvParser::parse($argv);

        $this->assertNotNull($args->usageError);
        $this->assertStringContainsString($needle, (string) $args->usageError);
    }

    public function testADefaultLaunchOpensANewSessionEvenWhenOthersExist(): void
    {
        $store = $this->store();
        $store->createSession('older', 'p', 'm');

        $opened = Bootstrap::openSession($store);

        $this->assertNotSame('older', $opened['id']);
        $this->assertSame([], $opened['history']);
        $this->assertFalse($opened['picker']);
        // ISS-102: the launch hands back a minter instead of a row. An
        // abandoned launch writes nothing; the first prompt calls the mint.
        $this->assertNull($store->getSession($opened['id']), 'a launch must not write an empty session row');
        $this->assertNotNull($opened['mint'] ?? null, 'the new-session path carries a minter');
        ($opened['mint'])();
        $this->assertNotNull($store->getSession($opened['id']), 'minting writes the row the id names');
    }

    /**
     * A fresh row records where it was opened (audit B1): the picker's Ctrl+B
     * filter compares against `git_branch`, which nothing used to write.
     */
    public function testAFreshSessionRecordsItsWorkingDirectoryAndBranch(): void
    {
        $repo = $this->tmpHome . '/repo';
        mkdir($repo . '/.git', 0700, true);
        file_put_contents($repo . '/.git/HEAD', "ref: refs/heads/feature-b1\n");
        $store = $this->store();
        $expectedCwd = realpath($repo);

        $previous = getcwd();
        chdir($repo);
        try {
            $opened = Bootstrap::openSession($store);
            // ISS-102: the origin record lands with the lazy mint, not the
            // launch; the closure captures the cwd/branch seen at open.
            ($opened['mint'])();
            [$seeded] = Bootstrap::seedSession(new EnhancedSessionStore($this->tmpHome . '/.candy-code/seed.db'));
        } finally {
            chdir((string) $previous);
            @unlink($repo . '/.git/HEAD');
            @rmdir($repo . '/.git');
            @rmdir($repo);
        }

        $row = $store->getSession($opened['id']);
        $this->assertSame($expectedCwd, $row['cwd']);
        $this->assertSame('feature-b1', $row['git_branch']);

        $seedRow = (new EnhancedSessionStore($this->tmpHome . '/.candy-code/seed.db'))->getSession($seeded);
        $this->assertSame('feature-b1', $seedRow['git_branch']);
    }

    public function testContinueReopensTheMostRecentSessionWithItsTranscript(): void
    {
        $store = $this->store();
        $store->createSession('older', 'p', 'm');
        $store->createSession('recent', 'p', 'm', null, 'Recent work');
        $store->saveTranscript('recent', [Message::user('where were we'), Message::assistant('the parser')]);
        Bootstrap::useSessionLaunch(true);

        $opened = Bootstrap::openSession($store);

        $this->assertSame('recent', $opened['id']);
        $this->assertSame('Recent work', $opened['name']);
        $this->assertSame(
            ['where were we', 'the parser'],
            array_map(static fn(Message $m): string => $m->content, $opened['history']),
        );
    }

    /**
     * Measured live: a launch quit without typing leaves a row newer than the
     * conversation before it, and `--continue` reopened that empty row.
     */
    public function testContinueSkipsANewerSessionThatNeverHeldAConversation(): void
    {
        $store = $this->store();
        $store->createSession('worked', 'p', 'm');
        $store->saveTranscript('worked', [Message::user('the real work')]);
        $store->createSession('quit-straight-away', 'p', 'm');
        Bootstrap::useSessionLaunch(true);

        $opened = Bootstrap::openSession($store);

        $this->assertSame('worked', $opened['id']);
        $this->assertSame(['the real work'], array_map(static fn(Message $m): string => $m->content, $opened['history']));
    }

    /**
     * ISS-026: a conversation from another checkout used to hijack every
     * `--continue` launched elsewhere. This checkout's newest conversation
     * wins even when it is older than the other project's.
     */
    public function testContinuePrefersThisCheckoutOverANewerSessionElsewhere(): void
    {
        $here = realpath($this->tmpHome) . '/here';
        $there = realpath($this->tmpHome) . '/there';
        mkdir($here, 0700, true);
        mkdir($there, 0700, true);
        $store = $this->store();
        $store->createSession('here', 'p', 'm', null, 'Local work', $here);
        $store->saveTranscript('here', [Message::user('this project')]);
        // Created last, so the global newest is the OTHER project's row: the
        // pick below can only come from the cwd scoping, not from ordering.
        $store->createSession('there', 'p', 'm', null, 'Elsewhere work', $there);
        $store->saveTranscript('there', [Message::user('not your project')]);
        Bootstrap::useSessionLaunch(true);

        $previous = getcwd();
        chdir($here);
        try {
            $opened = Bootstrap::openSession($store);
        } finally {
            chdir((string) $previous);
        }

        $this->assertSame('here', $opened['id'], '--continue resumed another project\'s session while this one had its own.');
        $this->assertSame(['this project'], array_map(static fn(Message $m): string => $m->content, $opened['history']));
    }

    /**
     * ISS-026's lenient rung: nothing local has content, so the other
     * project's conversation comes back — the cross-project stderr notice
     * rides this path (stderr is not captured by PHPUnit; the crossed flag
     * behind it is pinned in LatestResumableSessionCwdTest).
     */
    public function testContinueResumesAnotherProjectWhenNothingHereHasContent(): void
    {
        $here = realpath($this->tmpHome) . '/here';
        $there = realpath($this->tmpHome) . '/there';
        mkdir($here, 0700, true);
        mkdir($there, 0700, true);
        $store = $this->store();
        $store->createSession('quit-straight-away', 'p', 'm', null, null, $here);
        $store->createSession('there', 'p', 'm', null, 'Elsewhere work', $there);
        $store->saveTranscript('there', [Message::user('salvage me')]);
        Bootstrap::useSessionLaunch(true);

        $previous = getcwd();
        chdir($here);
        try {
            $opened = Bootstrap::openSession($store);
        } finally {
            chdir((string) $previous);
        }

        $this->assertSame('there', $opened['id']);
    }

    public function testContinueWithNothingToContinueFallsBackToTheNewestRow(): void
    {
        $store = $this->store();
        $store->createSession('only', 'p', 'm');
        Bootstrap::useSessionLaunch(true);

        $this->assertSame('only', Bootstrap::openSession($store)['id']);
    }

    public function testResumeFindsASessionByIdNameOrUniquePrefix(): void
    {
        $store = $this->store();
        $store->createSession('aaaa1111', 'p', 'm', null, 'Parser fix');
        $store->createSession('bbbb2222', 'p', 'm');
        $store->createSession('bbbb3333', 'p', 'm');

        foreach (['aaaa1111' => 'aaaa1111', 'Parser fix' => 'aaaa1111', 'aaa' => 'aaaa1111', 'bbbb3' => 'bbbb3333'] as $target => $id) {
            Bootstrap::useSessionLaunch(false, true, $target);
            $this->assertSame($id, Bootstrap::openSession($store)['id'], "--resume {$target}");
        }
    }

    public function testBareResumeOpensANewSessionWithThePickerUp(): void
    {
        $store = $this->store();
        $store->createSession('older', 'p', 'm');
        Bootstrap::useSessionLaunch(false, true);

        $opened = Bootstrap::openSession($store);

        $this->assertNotSame('older', $opened['id']);
        $this->assertTrue($opened['picker']);
    }

    public function testAResumeTargetNobodyHasIsReportedBeforeLaunch(): void
    {
        $store = $this->store();
        $store->createSession('bbbb2222', 'p', 'm');
        $store->createSession('bbbb3333', 'p', 'm');

        Bootstrap::useSessionLaunch(false, true, 'nope');
        $this->assertStringContainsString('no stored session has the id or name "nope"', (string) Bootstrap::sessionLaunchError());

        // An ambiguous prefix is not a match either.
        Bootstrap::useSessionLaunch(false, true, 'bbbb');
        $this->assertNotNull(Bootstrap::sessionLaunchError());

        Bootstrap::useSessionLaunch(false, true, 'bbbb2');
        $this->assertNull(Bootstrap::sessionLaunchError());

        Bootstrap::useSessionLaunch(true);
        $this->assertNull(Bootstrap::sessionLaunchError(), 'only a named --resume target can be missing');
    }

    public function testAFreshLaunchSweepsOldEmptySessionsButKeepsItsOwn(): void
    {
        $store = $this->store();
        $store->createSession('abandoned', 'p', 'm');
        (new \PDO('sqlite:' . $this->tmpHome . '/.candy-code/session.db'))
            ->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00'");

        $opened = Bootstrap::openSession($store);

        $this->assertNull($store->getSession('abandoned'));
        // ISS-102: its own row does not exist yet — there is nothing for a
        // later sweep to exempt, and no empty record if the run quits now.
        $this->assertNull($store->getSession($opened['id']), 'the launch itself writes no row');
        ($opened['mint'])();
        $this->assertNotNull($store->getSession($opened['id']));
    }

    public function testChatOpensThePickerForABareResume(): void
    {
        $this->store()->createSession('older', 'p', 'm');
        Bootstrap::useSessionLaunch(false, true);

        $chat = Bootstrap::chat($this->tmpHome);

        $this->assertNotNull($chat->sessionPicker());
    }

    public function testThePromptHistoryLivesUnderTheConfigDirectoryUnlessPinned(): void
    {
        Bootstrap::pinPromptHistoryPath(null);
        try {
            $this->assertSame(
                $this->tmpHome . '/.candy-code/prompt_history.jsonl',
                Bootstrap::promptHistory()->path(),
            );
        } finally {
            Bootstrap::pinPromptHistoryPath($GLOBALS['__candyCodePromptHistoryPin'] ?? null);
        }
    }

    public function testHelpDocumentsBothFlags(): void
    {
        $help = Help::screen();

        $this->assertStringContainsString('-c, --continue', $help);
        $this->assertStringContainsString('--resume [<id|name>]', $help);
    }
}
