<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * C-EXT-2.e through the real `Chat::submit()` custom-command branch: a file
 * command's `model:` pins exactly the one turn its expansion is sent as — the
 * dispatched request carries that model — while the session's backend object is
 * never mutated, so the next turn answers on the session's model with nothing
 * to restore. A command whose model the provider cannot serve goes out on the
 * session's model WITH a notice row said before the prompt (the Task rule,
 * plan §6 R13), and a `subtask: true` command's prompt row is the fenced
 * delegation directive itself, inline-pinned never.
 *
 * The launch is inspected the way `MidTurnSpendCapTranscriptTest` inspects it:
 * `TurnRunner::start()` returns a lazy closure and the backend is already
 * decided when it is built, so digging the captured `backend` out of the
 * command never starts a thread and never dials a provider.
 */
final class CustomCommandModelOverrideTest extends TestCase
{
    /** @param array<string, CommandSpec> $commands */
    private function chat(array $commands, \SugarCraft\Code\Backend $backend): Chat
    {
        return new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: $backend,
            projectRoot: \sys_get_temp_dir(),
            customCommands: $commands,
        );
    }

    private function spec(?string $model = null, bool $subtask = false, ?string $agent = null): CommandSpec
    {
        return CommandSpec::new(
            name: 'audit',
            description: 'x',
            category: 'project',
            template: 'Review the changelog.',
            model: $model,
            subtask: $subtask,
            agent: $agent,
        );
    }

    private function typeAndSubmit(Chat $chat, string $draft): array
    {
        foreach (mb_str_split($draft) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }

        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    /** The dispatched-turn backend a submit command captured, without running it. */
    private function launchedBackend(\Closure $cmd): ?EngineBackend
    {
        foreach ((new \ReflectionFunction($cmd))->getStaticVariables() as $key => $value) {
            if ($key === 'backend' && $value instanceof EngineBackend) {
                return $value;
            }
            if ($key === 'factory' && $value instanceof \Closure) {
                $found = $this->launchedBackend($value);
                if ($found !== null) {
                    return $found;
                }
            }
            if ($key === 'filtered' && \is_array($value)) {
                foreach ($value as $inner) {
                    if ($inner instanceof \Closure) {
                        $found = $this->launchedBackend($inner);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    public function testAPinnableModelGoesOutOnTheCommandTurnAndTheSessionIsUntouched(): void
    {
        $backend = EngineBackend::new(new ScriptedProvider([]), 'session-model');
        [, $cmd] = $this->typeAndSubmit($this->chat(['audit' => $this->spec(model: 'other/one')], $backend), '/audit');

        $this->assertInstanceOf(\Closure::class, $cmd, 'fixture: the command turn dispatches');
        $launched = $this->launchedBackend($cmd);
        $this->assertInstanceOf(EngineBackend::class, $launched);
        $this->assertNotSame($backend, $launched, 'the pinned turn rides a clone, never the shared backend');
        $this->assertSame('other/one', $launched->model());
        $this->assertSame('session-model', $backend->model(), 'the session object was never withModel()-ed');

        // The turn after the pinned one answers on the session's model with no
        // restore step anywhere: structural restoration is what makes this safe.
        $plain = new Chat(
            history: [Message::user('hello'), Message::assistant('hi'), Message::user('Review the changelog.')],
            backend: $backend,
            projectRoot: \sys_get_temp_dir(),
            customCommands: [],
        );
        [, $cmd2] = $this->typeAndSubmit($plain, 'thanks');
        $this->assertSame($backend, $this->launchedBackend($cmd2), 'the next turn launches the shared session backend');
    }

    public function testAnUnservableModelLaunchesTheSharedBackendAndSaysSoBeforeThePrompt(): void
    {
        $backend = EngineBackend::new(new ScriptedProvider([]), 'session-model');
        [$next, $cmd] = $this->typeAndSubmit($this->chat(['audit' => $this->spec(model: 'two words')], $backend), '/audit');

        $this->assertSame($backend, $this->launchedBackend($cmd), 'a fallback is no pin at all');
        $added = \array_slice($next->history, 2);
        $this->assertStringContainsString(
            'asked for model `two words`, which is not a model id',
            $added[0]->content,
            'the notice rides the transcript',
        );
        $this->assertSame('Review the changelog.', $added[1]->content, 'said BEFORE the prompt, like every rewrite report');
    }

    public function testASubtaskCommandRowIsTheDelegationDirectiveAndNeverInlinePins(): void
    {
        $backend = EngineBackend::new(new ScriptedProvider([]), 'session-model');
        [$next, $cmd] = $this->typeAndSubmit(
            $this->chat(['audit' => $this->spec(model: 'big-model', subtask: true)], $backend),
            '/audit',
        );

        $added = \array_slice($next->history, 2);
        $this->assertSame($backend, $this->launchedBackend($cmd), 'a delegation command never pins the session turn');
        $this->assertStringStartsWith(
            'The command `/audit` declares `subtask: true`: this prompt is a task to delegate',
            $added[0]->content,
        );
        $this->assertStringContainsString('Review the changelog.', $added[0]->content);
        $this->assertStringContainsString("as its prompt verbatim, and answer from what that run returns.\n", $added[0]->content, 'the directive introduces the fence immediately before the body');
    }

    public function testAModelCommandOnANonEngineBackendGoesOutSilently(): void
    {
        // EchoBackend has no withModel(); the pin is inert there BY THE DOC —
        // no notice row, no crash, the expansion rides as ever.
        [$next, $cmd] = $this->typeAndSubmit($this->chat(['audit' => $this->spec(model: 'anything')], new EchoBackend()), '/audit');

        $added = \array_slice($next->history, 2);
        $this->assertCount(1, $added, 'nothing is said because nothing was decided');
        $this->assertSame('Review the changelog.', $added[0]->content);
        $this->assertNotNull($cmd, 'EchoBackend still answers the turn');
    }

    /**
     * The 85% tier's PARKED route announces the model fall-back too
     * (C-EXT-2.e round 1, MINOR-1). `submit()` returns
     * {@see Chat::scheduleParkedCompaction()}'s pair ahead of its own notice
     * tail, so before the thread the parked prompt swapped to the session
     * model in total silence — the pin case already spoke (the
     * `command_model_parked` truth row), the fall-back case did not, and
     * silence is the disclosure law this row exists to keep. Driven, not
     * described: the park is proved by the returned Cmd and `inFlight`, so a
     * fixture that quietly took the serve route would not pass this test.
     */
    public function testAParkedCommandTurnAnnouncesTheModelFallBackAheadOfItsPrompt(): void
    {
        $history = self::commandParkWeightedHistory();
        $chat = new Chat(
            history: $history,
            backend: EngineBackend::new(new ScriptedProvider([], false, 88_000), 'session-model'),
            // Lazy: the park builds the summarization Cmd and never runs it
            // here. Non-null is what commits the route to the model ask.
            summaryBackend: new EchoBackend(),
            projectRoot: \sys_get_temp_dir(),
            customCommands: ['audit' => $this->spec(model: 'two words')],
        );

        [$parked, $cmd] = $this->typeAndSubmit($chat, '/audit');

        $this->assertNotNull($cmd, 'fixture: the turn parked behind the summarization');
        $this->assertTrue($parked->inFlight, 'fixture: the park holds inFlight, so submit() returned at the tier');

        $added = \array_slice($parked->history, \count($history));
        $fallbackRows = array_keys(array_filter(
            $added,
            static fn(Message $m): bool => str_contains((string) $m->content, 'asked for model `two words`, which is not a model id'),
        ));
        $this->assertCount(1, $fallbackRows, 'the fall-back sentence rides the parked transcript');

        $promptAt = array_key_last(array_filter(
            $added,
            static fn(Message $m): bool => $m->role === Role::User && $m->content === 'Review the changelog.',
        ));
        $this->assertIsInt($promptAt, 'fixture: the expanded prompt is echoed on the park');
        $this->assertLessThan($promptAt, $fallbackRows[0], 'said BEFORE the prompt it speaks for');
        $this->assertSame(0, \count(array_filter(
            $added,
            static fn(Message $m): bool => str_contains((string) $m->content, 'parked behind summarization'),
        )), 'the pin-case truth row stays for the pin case only — a fall-back pins nothing');
    }

    /**
     * ~78,280 estimated tokens against the 88,000 window the fixture's
     * provider reports: over the 85% tier, under the 95% blocking tier. The
     * weight shape is {@see \SugarCraft\Code\Tests\Chat\AutomaticCompactionModelSummaryTest}'s,
     * re-named rather than copied (DuplicatedTestHelperDrift).
     *
     * @return list<Message>
     */
    private static function commandParkWeightedHistory(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 52_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 52_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }
}
