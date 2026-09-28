<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\Help;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

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
        mkdir($this->tmpHome . '/.sugar-crush', 0700, true);
        $this->useHomeSandbox($this->tmpHome);
    }

    protected function tearDown(): void
    {
        Bootstrap::useSessionLaunch(false);
        $this->restoreHomeSandbox();
        foreach (glob($this->tmpHome . '/.sugar-crush/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpHome . '/.sugar-crush');
        @rmdir($this->tmpHome);
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->tmpHome . '/.sugar-crush/session.db');
    }

    /** @return iterable<string, array{list<string>, bool, bool, ?string}> */
    public static function spellings(): iterable
    {
        yield 'none' => [['sugarcrush'], false, false, null];
        yield '--continue' => [['sugarcrush', '--continue'], true, false, null];
        yield '-c' => [['sugarcrush', '-c'], true, false, null];
        yield '--resume <id>' => [['sugarcrush', '--resume', 'abc123'], false, true, 'abc123'];
        yield '--resume=<id>' => [['sugarcrush', '--resume=abc123'], false, true, 'abc123'];
        yield 'bare --resume' => [['sugarcrush', '--resume'], false, true, null];
        yield '--resume= (empty)' => [['sugarcrush', '--resume='], false, true, null];
        yield '--resume before a flag' => [['sugarcrush', '--resume', '--model', 'x'], false, true, null];
        yield 'root, then --resume' => [['sugarcrush', './app', '--resume'], false, true, null];
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
        $this->assertSame('./app', ArgvParser::parse(['sugarcrush', './app', '--resume'])->root);
        $this->assertSame('x', ArgvParser::parse(['sugarcrush', '--resume', '--model', 'x'])->model);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function refusals(): iterable
    {
        yield 'both flags' => [['sugarcrush', '-c', '--resume', 'x'], '--continue and --resume'];
        yield '-c with -p' => [['sugarcrush', '-c', '-p', 'hi'], '--continue reopens an interactive session'];
        yield '--resume with run' => [['sugarcrush', 'run', 'hi', '--resume'], '--resume reopens an interactive session'];
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
        $this->assertNotNull($store->getSession($opened['id']));
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
        (new \PDO('sqlite:' . $this->tmpHome . '/.sugar-crush/session.db'))
            ->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00'");

        $opened = Bootstrap::openSession($store);

        $this->assertNull($store->getSession('abandoned'));
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
                $this->tmpHome . '/.sugar-crush/prompt_history.jsonl',
                Bootstrap::promptHistory()->path(),
            );
        } finally {
            Bootstrap::pinPromptHistoryPath($GLOBALS['__sugarcrushPromptHistoryPin'] ?? null);
        }
    }

    public function testHelpDocumentsBothFlags(): void
    {
        $help = Help::screen();

        $this->assertStringContainsString('-c, --continue', $help);
        $this->assertStringContainsString('--resume [<id|name>]', $help);
    }
}
