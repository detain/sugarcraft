<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\TurnInterrupted;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.7-2 (Zed's sub-agent context guard): a delegated run whose sent
 * request reaches its wrap-up share of the window is told once to wrap up or
 * hand off; one that is still past the stop share after that is stopped as a
 * failure carrying its transcript, which is how the delegating Task returns
 * its partial output and resume id. On for completeTranscript() (the
 * delegated runs' entry), off for the session's own turns.
 */
final class SubAgentWrapUpNudgeTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testARunStillGrowingAfterTheNudgeIsStoppedWithItsTranscript(): void
    {
        $provider = self::provider(static fn (int $step): ?string => null);

        try {
            $this->engine($provider)->withWrapUpAt(20)->completeTranscript([new UserMessage('map everything')]);
            $this->fail('the run should have been stopped');
        } catch (TurnInterrupted $stopped) {
            $this->assertMatchesRegularExpression(
                '/^it is nearing the end of its context window \(\d+% used\) and was stopped; resume it to have it wrap up or hand off its work$/',
                $stopped->getMessage(),
            );
            $this->assertSame(1, self::nudges($stopped->transcript), 'told once, and the transcript a resume continues from carries it');
        }

        // The nudge went out on the request after the one that crossed 20%.
        $nudged = array_values(array_filter($provider->requests, static fn (CompleteRequest $r): bool => self::nudges($r->messages) === 1));
        $this->assertNotEmpty($nudged);
        $this->assertStringEndsWith('At 30% the run is stopped.', self::nudgeText($nudged[0]->messages));
        $last = $provider->requests[array_key_last($provider->requests)];
        $this->assertSame(1, self::nudges($last->messages), 'the stop came after a request sent with the nudge');
    }

    public function testARunThatWrapsUpWhenToldEndsWithItsReport(): void
    {
        $provider = self::provider(static fn (int $step, bool $nudged): ?string => $nudged ? 'REPORT: done what fit.' : null);

        $turn = $this->engine($provider)->withWrapUpAt(20)->completeTranscript([new UserMessage('map everything')]);

        $this->assertSame('REPORT: done what fit.', $turn->reply->content);
        $this->assertSame(1, self::nudges($turn->transcript));
    }

    public function testDelegatedRunsAreGuardedByDefaultAndSessionTurnsAreNot(): void
    {
        $percents = [];
        $observe = static function (object $event) use (&$percents): void {
            if ($event instanceof StepStarted && $event->pressure !== null) {
                $percents[] = $event->pressure->percentOfWindow();
            }
        };

        // A run whose relief cannot help (a protected tool's outputs, too
        // little sent to summarise) crosses 80% of a small window, then 90%:
        // completeTranscript() stops it.
        $provider = self::provider(static fn (): ?string => null, window: 12_000);
        try {
            $this->engine($provider)->completeTranscript([new UserMessage('map everything')], onStep: $observe);
            $this->fail('a delegated run past 90% should have been stopped');
        } catch (TurnInterrupted $stopped) {
            $this->assertStringContainsString('nearing the end of its context window', $stopped->getMessage());
            $this->assertGreaterThanOrEqual(EngineBackend::SUB_AGENT_WRAP_UP_PERCENT + EngineBackend::WRAP_UP_STOP_MARGIN, max($percents));
        }

        // The same run as a session turn is not guarded: it runs to its step ceiling.
        $session = self::provider(static fn (): ?string => null, window: 12_000, maxSteps: 12);
        $reply = $this->engine($session)->complete([Message::user('map everything')]);
        $this->assertSame(0, self::nudges(array_merge(...array_map(static fn (CompleteRequest $r): array => $r->messages, $session->requests))));
        $this->assertNotSame('', $reply->content);

        // And 0 turns the delegated guard off.
        $off = self::provider(static fn (): ?string => null, window: 12_000, maxSteps: 12);
        $turn = $this->engine($off)->withWrapUpAt(0)->completeTranscript([new UserMessage('map everything')]);
        $this->assertSame(0, self::nudges($turn->transcript));
    }

    /**
     * A model that keeps calling `Skill` (each result ~1,500 tokens; a tool
     * the prune protects, so relief cannot shrink the run) until $answer
     * gives it something to say, or its $maxSteps-th request.
     *
     * @param \Closure(int, bool): ?string $answer
     */
    private static function provider(\Closure $answer, int $window = 40_000, int $maxSteps = 40): ScriptedProvider
    {
        $step = 0;

        return new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step, $answer, $maxSteps): CompleteResponse {
                $step++;
                $said = $answer($step, self::nudges($request->messages) > 0);
                if ($said !== null || $step >= $maxSteps) {
                    return new CompleteResponse(content: $said ?? 'out of steps');
                }

                return new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", 'Skill', ['n' => $step])]);
            },
        ], contextWindow: $window);
    }

    /** @param list<mixed> $messages */
    private static function nudges(array $messages): int
    {
        $count = 0;
        foreach ($messages as $message) {
            if ($message instanceof UserMessage && str_starts_with($message->content(), 'This run has used about ')) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<mixed> $messages */
    private static function nudgeText(array $messages): string
    {
        foreach ($messages as $message) {
            if ($message instanceof UserMessage && str_starts_with($message->content(), 'This run has used about ')) {
                return $message->content();
            }
        }

        return '';
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-wrapup-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root)->withTools([self::probe()]);
    }

    private static function probe(): Tool
    {
        return new class () implements Tool {
            public function name(): string
            {
                return 'Skill';
            }

            public function description(): string
            {
                return 'Reads a lot.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: str_repeat('lorem ipsum ', 500) . ($args['n'] ?? ''));
            }
        };
    }
}
