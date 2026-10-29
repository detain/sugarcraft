<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Commands\CommandLoader;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-WEB-2.b: the VIS-5 slash rewrite runs on SERVED sessions too. A typed
 * `/name args` whose name a user-invocable skill claims reaches the transcript
 * as the `$name args` mention row over `session.send`, exactly as it does in
 * the TUI, because `Host\SessionHost::admit()` calls the shared
 * {@see \SugarCraft\Code\Skills\SkillSlashInvocation::draft()} gate at the
 * same funnel position `Chat::submit()` holds it: after the built-in and
 * file-command branches answered, before the prompt is admitted.
 *
 * Driven through the real `submit()` per this family's convention
 * (`ServedProjectCommandTrustTest`): the precedence is a property of the
 * whole funnel, and a test of the shared function alone could stay green if
 * the serve call site slid above `resolveCustomCommand()`.
 */
final class ServedSkillSlashTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $project = '';

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-served-skill-' . bin2hex(random_bytes(6));
        $this->project = $this->sandbox . '/project';
        mkdir($this->project . '/.candy-code/commands', 0o700, true);
        mkdir($this->project . '/.candy-code/skills', 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->store = new EnhancedSessionStore($this->sandbox . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /**
     * A registry over real SKILL.md files (the mention channel re-reads the
     * body from disk at resolve time, so the snapshot a row carries must
     * exist); `$off` registers a name and then disables it.
     *
     * @param array<string, string> $bodies name => body
     * @param list<string>          $off
     */
    private function diskRegistry(array $bodies, array $off = []): SkillRegistry
    {
        $found = [];
        foreach ($bodies as $name => $body) {
            $dir = $this->project . '/.candy-code/skills/' . $name;
            mkdir($dir, 0o700, true);
            file_put_contents($dir . '/SKILL.md', "---\nname: {$name}\ndescription: the {$name} skill\n---\n" . $body);
            $found[] = Skill::fromFile($dir . '/SKILL.md');
        }

        $registry = new SkillRegistry();
        $registry->register($found);
        $registry->disableMultiple($off);

        return $registry;
    }

    private function hostWith(?SkillRegistry $skills, ?CommandLoader $loader = null): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                root: $this->project,
                sessionStore: $this->store,
                skills: $skills,
                commandLoader: $loader ?? new CommandLoader(),
                backend: new EchoBackend(),
            ),
            history: [Message::user('hello'), Message::assistant('hi')],
        );
    }

    /** The messages the send appended to the two-message fixture history. */
    private function appended(SessionHost $host): array
    {
        return array_slice($host->history(), 2);
    }

    private function settleTurn(SessionHost $host): void
    {
        if ($host->isBusy()) {
            $loop = Loop::get();
            $poll = $loop->addPeriodicTimer(0.005, static function () use ($host, $loop): void {
                if (!$host->isBusy()) {
                    $loop->stop();
                }
            });
            $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($poll);
            $loop->cancelTimer($guard);
        }

        self::assertFalse($host->isBusy(), 'the served turn settled');
    }

    public function testAServedSlashNameArrivesAsTheMentionRowTheTuiWouldSend(): void
    {
        $host = $this->hostWith($this->diskRegistry(['shipit' => 'Review $ARGUMENTS please']));
        $ticket = $host->submit('/shipit review the diff');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'the slash spelling starts the turn the mention spelling would');
        $added = $this->appended($host);
        self::assertSame('$shipit review the diff', $added[0]->content, 'the user row carries the rewritten mention draft');
        self::assertCount(1, $added[0]->attachments);
        self::assertSame('shipit', $added[0]->attachments[0]->skill);
        self::assertSame('Review review the diff please', $added[0]->attachments[0]->data, 'the body snapshot rides with $ARGUMENTS filled');

        $this->settleTurn($host);
        $host->release();
    }

    public function testTheCheckpointRewindKeepsTheTypedSlashDraftNotTheRewrite(): void
    {
        // Parity with the TUI's E681 rule — the rewind box holds what the
        // user typed, not what was dispatched — preserved across the lift by
        // admit() passing the typed draft to admitPrompt as before.
        $host = $this->hostWith($this->diskRegistry(['shipit' => 'x']));
        $host->submit('/shipit review the diff');
        $this->settleTurn($host);

        $state = null;
        foreach ([1, 2, 0] as $index) {
            $state = $this->store->checkpoint('s', $index);
            if ($state !== null) {
                break;
            }
        }

        self::assertIsArray($state, 'the turn checkpointed');
        self::assertSame('/shipit review the diff', $state['inputBuf'], 'the rewind draft survives the rewrite');
        $host->release();
    }

    public function testASendThatDeclinesMentionsIsNotRewritten(): void
    {
        // resolveMentions:false is text the user did not type (the web
        // inject leg), and the mention channel would not attach it anyway;
        // the gate stays off that door.
        $host = $this->hostWith($this->diskRegistry(['shipit' => 'x']));
        $ticket = $host->submit('/shipit review', SubmitOptions::new()->withResolveMentions(false));

        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        self::assertSame('/shipit review', $this->appended($host)[0]->content, 'the draft was sent exactly as provided');

        $this->settleTurn($host);
        $host->release();
    }

    public function testASessionWithoutASkillRegistrySendsTheDraftUntouched(): void
    {
        $host = $this->hostWith(null);
        $host->submit('/shipit review');

        self::assertSame('/shipit review', $this->appended($host)[0]->content, 'no channel, nothing rewrites');

        $this->settleTurn($host);
        $host->release();
    }

    public function testADisabledSkillNameIsProseNotARefusalOnTheServedRoute(): void
    {
        $host = $this->hostWith($this->diskRegistry(['off' => 'x'], off: ['off']));
        $ticket = $host->submit('/off please');

        // The gate answers null (registry gate, same as the mention door), so
        // the served route falls through to the prompt exactly as an unclaimed
        // name does — the TUI's refusal notice is the terminal's own surface.
        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        self::assertSame('/off please', $this->appended($host)[0]->content);
        self::assertSame([], $this->appended($host)[0]->attachments);

        $this->settleTurn($host);
        $host->release();
    }

    public function testABuiltInSpellingStillRunsTheBuiltInServedUnderAShadowingSkill(): void
    {
        // /keys is TUI-surface-only, so the served route's built-in branch
        // REFUSES it — what matters is that the branch still owns the name:
        // the shadow skill must not smuggle `/keys` into a mention turn.
        $host = $this->hostWith($this->diskRegistry(['keys' => 'x']));
        $ticket = $host->submit('/keys');

        self::assertSame(TurnTicket::REFUSED, $ticket->admitted, 'the command branch answers first, as in the TUI');
        self::assertSame([], $this->appended($host), 'the shadow never reaches the mention channel — no turn was admitted');

        $this->settleTurn($host);
        $host->release();
    }

    public function testAProjectCommandFileBeatsTheSkillOnTheServedRoute(): void
    {
        file_put_contents($this->project . '/.candy-code/commands/notes.md', 'Note the diff.');
        $loader = new CommandLoader();
        $host = $this->hostWith($this->diskRegistry(['notes' => 'x']), $loader);

        self::assertSame([], $loader->refusedCommands(), 'a plain project row needs no grant');

        $ticket = $host->submit('/notes');

        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        self::assertSame('Note the diff.', $this->appended($host)[0]->content, 'the file command materialized, the skill rewrite never saw the name');
        self::assertSame([], $this->appended($host)[0]->attachments);

        $this->settleTurn($host);
        $host->release();
    }
}
