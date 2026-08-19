<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * A slash command that exits non-zero must report IN the transcript, and must
 * not hand the program a Cmd that returns something other than a Msg.
 *
 * USER-REPORTED CRASH, and this file is the regression pin for it. `/share`,
 * `/websearch` and `/agents` each ended their failure branch with
 *
 *     return [$this, static fn() => print $output];
 *
 * `print` is an EXPRESSION evaluating to `int 1`, so that closure is a Cmd
 * returning an int. `Program::scheduleCmd()` dispatches whatever non-null a Cmd
 * returns and `Program::dispatch()` requires a `Msg`, so the app died with
 * "Argument #1 ($msg) must be of type SugarCraft\Core\Msg, int given" — a fatal,
 * from a bare `/websearch`, reported by a user daily-driving the binary.
 *
 * NOTHING CAUGHT IT, and the reason is worth recording: the suite covered these
 * three commands only on their SUCCESS paths, where the returned Cmd is null.
 * The failure branch was the one line of each handler no test entered, and it is
 * the line that runs when a user gets the argument wrong — which is the ordinary
 * case, not the exotic one.
 */
final class CommandFailureTranscriptTest extends TestCase
{
    private function chat(string $draft, ?AgentManager $agents = null): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            agentManager: $agents,
        ))->withSize(100, 30);
    }

    /**
     * Submit $draft and return [added messages, the Cmd].
     *
     * @return array{0: list<Message>, 1: ?\Closure}
     */
    private function submit(string $draft, ?AgentManager $agents = null): array
    {
        [$next, $cmd] = $this->chat($draft, $agents)->update(new KeyMsg(KeyType::Enter));

        return [array_slice($next->history, 2), $cmd];
    }

    /**
     * The exact reported invocation. `/websearch` with no query prints its usage
     * line and exits non-zero, which is what reached the `print`.
     */
    public function testWebSearchWithNoQueryReportsInTheTranscriptAndReturnsNoCmd(): void
    {
        [$added, $cmd] = $this->submit('/websearch');

        // The Cmd is the crash: any non-null closure here is dispatched, and the
        // old one evaluated to int 1.
        $this->assertNull($cmd, 'a failing command must not hand the program a Cmd');
        $this->assertCount(2, $added);
        $this->assertSame(Role::User, $added[0]->role);
        $this->assertSame('/websearch', $added[0]->content);
        $this->assertSame(Role::System, $added[1]->role);
        $this->assertStringContainsString('Usage: /websearch', $added[1]->content);
    }

    public function testShareWithNoArgumentsReportsInTheTranscriptAndReturnsNoCmd(): void
    {
        [$added, $cmd] = $this->submit('/share');

        $this->assertNull($cmd);
        $this->assertCount(2, $added);
        $this->assertSame(Role::System, $added[1]->role);
        $this->assertStringContainsString('not yet implemented', $added[1]->content);
    }

    public function testShareWithAnInvalidFormatReportsInTheTranscriptAndReturnsNoCmd(): void
    {
        [$added, $cmd] = $this->submit('/share bogusformat');

        $this->assertNull($cmd);
        $this->assertSame(Role::System, $added[1]->role);
        $this->assertStringContainsString('bogusformat', $added[1]->content);
    }

    /**
     * `/agents` needs a configured AgentManager to reach its failing branch at
     * all: with none, `handleAgentsCommand()` short-circuits to a "not
     * configured" reply that exits ZERO. So a test that simply submits
     * `/agents bogus` on a bare Chat passes without ever entering the branch
     * this file exists to cover — measured, it takes the success path.
     */
    public function testAgentsWithAnUnknownNameReportsInTheTranscriptAndReturnsNoCmd(): void
    {
        // An EMPTY manager is enough and is the smaller fixture: the failing
        // branch is `get()` returning null, which an empty roster satisfies. What
        // it must not be is null — that is the short-circuit described above.
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());

        [$added, $cmd] = $this->submit('/agents nosuchagent', $manager);

        $this->assertNull($cmd);
        $this->assertCount(2, $added);
        $this->assertSame(Role::System, $added[1]->role);
        $this->assertStringContainsString('Unknown agent: nosuchagent', $added[1]->content);
    }

    /**
     * The notice is `Role::System`, not `Role::Assistant`, and that is a
     * correctness property rather than a cosmetic one: history is replayed to
     * the provider, so an app-generated failure notice filed as an assistant
     * turn becomes something the model believes it said.
     */
    public function testTheFailureNoticeIsNeverAnAssistantTurn(): void
    {
        foreach (['/websearch', '/share', '/share bogusformat'] as $draft) {
            [$added] = $this->submit($draft);
            // Counted BEFORE indexing, and that is the point rather than
            // defensiveness: against the unfixed code these handlers appended
            // NOTHING, so `$added[1]` was an undefined key, `assertNotSame`
            // compared Role::Assistant against null, and this test passed
            // vacuously with a warning. It was pinning the absence of a role on a
            // message that did not exist.
            $this->assertCount(2, $added, "{$draft} must echo the command and report the failure");
            $this->assertNotSame(
                Role::Assistant,
                $added[1]->role,
                "{$draft}'s failure notice must not be filed as a model reply",
            );
        }
    }

    /**
     * A command that fails while printing nothing still has to say so.
     *
     * Driven through the private helper by reflection, deliberately: no command
     * in the tree currently exits non-zero with empty output, so this branch has
     * no behavioural route today. Asserting it here keeps the fallback from being
     * a decorative `sprintf` that has never once been evaluated — the shape of
     * dormant code this project's directive says to wire rather than drop.
     */
    public function testASilentFailureStillProducesANotice(): void
    {
        $chat = $this->chat('');
        $method = new \ReflectionMethod($chat, 'commandFailureResponse');
        /** @var array{0: Chat, 1: ?\Closure} $result */
        $result = $method->invoke($chat, '/somecmd', "   \n  ", 3);

        $this->assertNull($result[1]);
        $added = array_slice($result[0]->history, 2);
        $this->assertSame(Role::System, $added[1]->role);
        $this->assertStringContainsString('exit code 3', $added[1]->content);
    }

    /**
     * `Chat` must contain no `print` and no `echo` AT ALL.
     *
     * A SOURCE-SHAPE assertion, named as one rather than dressed up as a
     * behavioural test — but a stronger invariant than the defect requires, and
     * deliberately so. The bug was an idiom: `fn() => print $x` is a Cmd whose
     * value is `int 1`, which `Program::dispatch()` rejects. Guarding only "print
     * inside a closure" would need to parse what a closure body is, and the first
     * attempt here matched this very docblock quoting the bad line.
     *
     * The wider rule is the one that actually holds: `Chat` is a TEA model, its
     * side effects belong in a `Cmd`, and the screen belongs to candy-core's frame
     * renderer — so writing to stdout from this file is wrong whether or not it
     * also crashes. Measured at the time of writing: zero `T_PRINT`/`T_ECHO`
     * tokens, so the assertion starts satisfied rather than grandfathering
     * anything in.
     *
     * Tokenised rather than grepped, because a regex cannot tell code from the
     * comment describing it — which is the mistake this test made first.
     */
    public function testChatNeverWritesToStdoutDirectly(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Chat.php');
        $this->assertIsString($source);

        $offenders = [];
        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_PRINT, \T_ECHO], true)) {
                $offenders[] = 'line ' . $token[2] . ': ' . $token[1];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Chat must not write to stdout: a Cmd closure whose body is print/echo evaluates to int, "
            . "and Program::dispatch() requires a Msg. Route the text into the transcript instead.",
        );
    }
}
