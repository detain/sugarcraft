<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\ApprovalVerdict;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\BuiltIn\AskUserTool;
use SugarCraft\Crush\Tools\BuiltIn\PlanExitTool;
use SugarCraft\Crush\Tools\BuiltIn\Todo;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 5.7-2, the headless rule: a `-p` run asks nobody anything. The
 * model's `AskUser` fails closed with a "no interactive user" result and its
 * `PlanExit` is refused — even when the run carries an approver that would
 * have granted — so a one-shot run neither stalls on a question nor leaves
 * plan mode unapproved.
 */
final class NonInteractiveAskUserTest extends TestCase
{
    public function testAPrintRunAnswersTheModelsQuestionWithNoInteractiveUser(): void
    {
        $asked = 0;
        $grant = static function () use (&$asked): ApprovalVerdict {
            $asked++;

            return ApprovalVerdict::once();
        };
        $provider = $this->providerCalling([
            new ToolCall('c1', AskUserTool::NAME, ['question' => 'Postgres or SQLite?', 'options' => ['SQLite', 'Postgres']]),
            new ToolCall('c2', PlanExitTool::NAME, ['plan_path' => '.sugar-crush/plans/x.md']),
        ]);
        $backend = EngineBackend::new($provider, 'scripted')
            ->withTools([
                AskUserTool::new()->withPermissionApprover($grant),
                PlanExitTool::new(sys_get_temp_dir())->withPermissionApprover($grant),
            ])
            ->withPermissionApprover($grant);

        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['sugarcrush', '-p', 'set up the store']), $backend, NonInteractive::FORMAT_JSON);
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code, $stdout);
        self::assertSame(0, $asked, 'a -p run put a question to an approver');
        self::assertSame(2, $provider->calls, 'the turn went on after the refused question');

        $sent = $provider->toolResults;
        self::assertCount(2, $sent);
        self::assertStringContainsString(NonInteractive::NO_INTERACTIVE_USER, $sent[0]);
        self::assertStringContainsString('state the assumption', $sent[0]);
        self::assertStringContainsString(NonInteractive::NO_INTERACTIVE_USER, $sent[1]);
        self::assertStringContainsString('Plan mode stays on', $sent[1]);

        $document = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('done', $document['result']);
    }

    public function testOnlyTheQuestionToolsAreClosedAndOtherBackendsPassThrough(): void
    {
        $plain = EngineBackend::new(new EchoProvider(), 'echo')->withTools([new Todo()]);
        self::assertSame($plain, NonInteractive::withoutInteractiveUser($plain), 'nothing to close, nothing rebuilt');

        $other = new class implements Backend {
            public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant('');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?\SugarCraft\Crush\Backend\CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
            {
                return \React\Promise\resolve(Message::assistant(''));
            }
        };
        self::assertSame($other, NonInteractive::withoutInteractiveUser($other));

        $closed = NonInteractive::withoutInteractiveUser(
            EngineBackend::new(new EchoProvider(), 'echo')->withTools(['todo' => new Todo(), 'ask' => AskUserTool::new()->withPermissionApprover(static fn (): bool => true)]),
        );
        self::assertInstanceOf(EngineBackend::class, $closed);
        $tools = $closed->tools();
        self::assertSame(['todo', 'ask'], array_keys($tools));
        self::assertInstanceOf(Todo::class, $tools['todo']);
        $result = $tools['ask']->execute(['question' => 'Go?']);
        self::assertTrue($result->isError());
        self::assertStringContainsString(NonInteractive::NO_INTERACTIVE_USER, $result->content());
    }

    /**
     * Round one asks for every call in `$calls`; round two records the tool
     * results it was sent and answers `done`.
     *
     * @param list<ToolCall> $calls
     */
    private function providerCalling(array $calls): ProviderInterface
    {
        return new class ($calls) implements ProviderInterface {
            public int $calls = 0;

            /** @var list<string> */
            public array $toolResults = [];

            /** @param list<ToolCall> $toolCalls */
            public function __construct(private readonly array $toolCalls) {}

            public function name(): string { return 'ask-tc'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100000; }
            public function costPer1kTokens(string $m, string $d): float { return 0.0; }

            public function complete(CompleteRequest $r): CompleteResponse
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return new CompleteResponse(content: 'asking', toolCalls: $this->toolCalls);
                }

                foreach ($r->messages as $message) {
                    if ($message instanceof \SugarCraft\Crush\Messages\ToolResultMessage) {
                        $this->toolResults[] = $message->content();
                    }
                }

                return new CompleteResponse(content: 'done');
            }

            public function completeStream(CompleteRequest $r): \Generator { yield new CompleteResponse(content: ''); }
            public function embeddings(EmbeddingsRequest $r): EmbeddingsResponse { return new EmbeddingsResponse([]); }
        };
    }
}
