<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\MCP\AnswersServerRequests;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpServer;
use SugarCraft\Code\MCP\McpServerRequests;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Permissions\ApprovalVerdict;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Tools\BuiltIn\AskUserTool;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-MCP-3.b: the turn's ask channel and live tail reach MCP calls.
 *
 * A stdio server may ask `elicitation/create` and push
 * `notifications/progress` mid-call. C-MCP-3.a landed the answer desk
 * ({@see McpServerRequests}); this bundle wires the desk INTO the tool call:
 * a bridge that was handed the turn's permission approver answers an
 * elicitation as an `AskUser`-shaped ask (the same ToolCall + HookResult::ask
 * the TUI modal and the web permission card already render), and a bridge
 * that was handed the turn's progress emitter turns each pacing
 * notification into a {@see ToolProgress} tail frame. Without either binding
 * the call stays on the spawn-time default desk — byte-identical to the
 * pre-C-MCP-3.b wire.
 *
 * NO REAL MCP SERVER IS INVOLVED: the fake below implements the transport's
 * own {@see AnswersServerRequests} seam and drives the desk it is handed,
 * the same reflection-injection route {@see McpToolBridgeTest} uses.
 */
final class McpToolProgressTest extends TestCase
{
    // =========================================================================
    // Progress: notifications/progress -> ToolProgress tails
    // =========================================================================

    public function testTheServersProgressMessageBecomesATailFrameOnTheTurnsChannel(): void
    {
        $marker = 'tmcpprog' . uniqid();
        $frames = [];
        $bridge = $this->bridgingDesk(function (\Closure $handler) use ($marker): array {
            $handler('notifications/progress', ['progressToken' => 'pt', 'progress' => 30, 'total' => 100, 'message' => $marker . ' indexing'], true);

            return ['content' => [['type' => 'text', 'text' => 'done']]];
        });
        $bridge = $bridge->withToolProgressEmitter(static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        $result = $bridge->execute(['id' => 'call-7', 'note' => 'x']);

        self::assertFalse($result->isError());
        self::assertCount(1, $frames);
        self::assertSame('call-7', $frames[0]->toolCallId);
        self::assertSame($bridge->name(), $frames[0]->toolName);
        self::assertStringContainsString($marker, $frames[0]->tail);
        self::assertSame(1, $frames[0]->seq);
        self::assertFalse($frames[0]->truncated);
        self::assertSame(McpToolBridge::PROGRESS_TAIL_BUDGET, $frames[0]->tailBudget);
    }

    public function testAProgressWithoutAMessageCarriesItsOwnPacing(): void
    {
        $frames = [];
        $bridge = $this->bridgingDesk(static function (\Closure $handler): array {
            $handler('notifications/progress', ['progress' => 50, 'total' => 200], true);
            $handler('notifications/progress', ['progress' => 75, 'total' => 200], true);

            return ['content' => [['type' => 'text', 'text' => 'ok']]];
        })->withToolProgressEmitter(static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        $bridge->execute(['id' => 'call-9']);

        // No English is minted here: the pacing legs are numbers the server
        // itself wrote, so a relabelled format below would be a translation
        // the server never asked for.
        self::assertSame(['25%', '37.5%'], array_map(static fn (ToolProgress $f): string => $f->tail, $frames));
        self::assertSame([1, 2], array_map(static fn (ToolProgress $f): int => $f->seq, $frames));
    }

    public function testAProgressFrameNeverGrowsPastItsTailBudget(): void
    {
        $blob = str_repeat('z', McpToolBridge::PROGRESS_TAIL_BUDGET + 400);
        $frames = [];
        $bridge = $this->bridgingDesk(static function (\Closure $handler) use ($blob): array {
            $handler('notifications/progress', ['progress' => 1, 'message' => $blob], true);

            return ['content' => [['type' => 'text', 'text' => 'ok']]];
        })->withToolProgressEmitter(static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        $bridge->execute(['id' => 'call-big']);

        self::assertCount(1, $frames);
        self::assertLessThanOrEqual(McpToolBridge::PROGRESS_TAIL_BUDGET, strlen($frames[0]->tail));
        self::assertTrue($frames[0]->truncated, 'a clipped tail must announce itself');
        self::assertStringEndsWith(substr($blob, -8), $frames[0]->tail, 'the tail keeps the LAST bytes');
    }

    public function testProgressIsSilentWithoutACallId(): void
    {
        $frames = [];
        $bridge = $this->bridgingDesk(static function (\Closure $handler): array {
            $handler('notifications/progress', ['progress' => 1, 'message' => 'orphan'], true);

            return ['content' => [['type' => 'text', 'text' => 'ok']]];
        })->withToolProgressEmitter(static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        $result = $bridge->execute([]);

        self::assertFalse($result->isError());
        self::assertSame([], $frames, 'a frame with no row key would render nowhere');
    }

    // =========================================================================
    // Elicitation: the server's question rides the turn's ask channel
    // =========================================================================

    public function testTheElicitationIsPutToTheApproverInTheAskUserShape(): void
    {
        $questionText = 'tmcpsay' . uniqid();
        $askedWith = [];
        $bridge = $this->bridgingDesk(function (\Closure $handler) use ($questionText): array {
            $reply = $handler('elicitation/create', [
                'requestedSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => $questionText]], 'required' => ['path']],
            ], false);

            return ['content' => [['type' => 'text', 'text' => json_encode($reply['result'] ?? $reply['error'], JSON_THROW_ON_ERROR)]]];
        })->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$askedWith): ApprovalVerdict {
            $askedWith[] = [$call, $ask];

            return ApprovalVerdict::rejectedByUser('/tmp/repo/x.txt');
        });

        $result = $bridge->execute(['id' => 'call-el', 'note' => 'x']);

        self::assertCount(1, $askedWith);
        [$call, $ask] = $askedWith[0];
        // The modal and the web card key on the AskUser shape — a rename here
        // renders the server's question nowhere.
        self::assertSame(AskUserTool::NAME, $call->name());
        self::assertSame('call-el', $call->id());
        self::assertSame(HookResult::ASK, $ask->action);
        $question = (string) $call->arguments()['question'];
        self::assertStringContainsString('MCP server `srv` asks:', $question);
        self::assertStringContainsString($questionText, $question);
        // The modal's prompt text carries the server's question too.
        self::assertStringContainsString($questionText, $ask->message);
        // The answer rode back as the field the server asked for. The fake
        // echoes the desk's protocol reply as json_encode'd text, and the
        // slash escapes — so the decoded content is the pinned layer.
        $decoded = json_decode($result->content(), true);
        self::assertIsArray($decoded);
        self::assertSame('accept', $decoded['action'] ?? null);
        self::assertSame('/tmp/repo/x.txt', $decoded['content']['path'] ?? null);
    }

    public function testAnEnumFieldIsAskedAsAChoiceAndCoercesToItsValue(): void
    {
        $picked = null;
        $bridge = $this->bridgingDesk(static function (\Closure $handler): array {
            $reply = $handler('elicitation/create', [
                'requestedSchema' => ['type' => 'object', 'properties' => ['mode' => ['type' => 'string', 'enum' => ['fast', 'slow']]], 'required' => ['mode']],
            ], false);

            return ['content' => [['type' => 'text', 'text' => json_encode($reply['result'] ?? $reply['error'], JSON_THROW_ON_ERROR)]]];
        })->withPermissionApprover(static function (ToolCall $call) use (&$picked): ApprovalVerdict {
            $picked = $call->arguments()['options'] ?? null;

            return ApprovalVerdict::rejectedByUser('2');
        });

        $result = $bridge->execute(['id' => 'call-enum']);

        self::assertSame(['fast', 'slow'], $picked);
        self::assertStringContainsString('"action":"accept"', $result->content());
        self::assertStringContainsString('"mode":"slow"', $result->content());
    }

    public function testANumericAndABooleanFieldCoerceAndAMultiFieldFormAsksEachQuestion(): void
    {
        $asked = 0;
        $bridge = $this->bridgingDesk(static function (\Closure $handler): array {
            $reply = $handler('elicitation/create', [
                'requestedSchema' => ['type' => 'object', 'properties' => [
                    'count' => ['type' => 'integer', 'description' => 'how many'],
                    'loud' => ['type' => 'boolean', 'description' => 'shout it'],
                ], 'required' => ['count', 'loud']],
            ], false);

            return ['content' => [['type' => 'text', 'text' => json_encode($reply['result'] ?? $reply['error'], JSON_THROW_ON_ERROR)]]];
        })->withPermissionApprover(static function (ToolCall $call) use (&$asked): ApprovalVerdict {
            $asked++;

            return $asked === 1
                ? ApprovalVerdict::rejectedByUser('42')
                : ApprovalVerdict::rejectedByUser('y');
        });

        $result = $bridge->execute(['id' => 'call-multi']);

        self::assertSame(2, $asked, 'a two-field form is a short series of questions');
        self::assertStringContainsString('"count":42', $result->content());
        self::assertStringContainsString('"loud":true', $result->content());
    }

    public function testADeclineAnswersDeclineAndAnUnansweredQuestionCancels(): void
    {
        $declined = $this->bridgingDesk($this->singleStringElicitation())
            ->withPermissionApprover(static fn (): ApprovalVerdict => ApprovalVerdict::rejectedByUser(''));
        self::assertStringContainsString('"action":"decline"', $declined->execute(['id' => 'd1'])->content());

        $silence = $this->bridgingDesk($this->singleStringElicitation())
            ->withPermissionApprover(static fn (): ApprovalVerdict => ApprovalVerdict::unanswered('turn ended'));
        self::assertStringContainsString('"action":"cancel"', $silence->execute(['id' => 'c1'])->content());

        // A harness-written reason (no channel of its own) cancels too — the
        // question was never put, so the server must not read a refusal.
        $noChannel = $this->bridgingDesk($this->singleStringElicitation())
            ->withPermissionApprover(static fn (): ApprovalVerdict => ApprovalVerdict::reject('parallel member has no ask channel'));
        self::assertStringContainsString('"action":"cancel"', $noChannel->execute(['id' => 'c2'])->content());
    }

    public function testARequiredFieldNobodyAnsweredCancelsRatherThanInventingOne(): void
    {
        // The approver "permits" without typing words: y on a free-input
        // question is not an answer.
        $bridge = $this->bridgingDesk($this->singleStringElicitation())
            ->withPermissionApprover(static fn (): ApprovalVerdict => ApprovalVerdict::once());

        self::assertStringContainsString('"action":"cancel"', $bridge->execute(['id' => 'r1'])->content());
    }

    public function testAnApproverThatThrewCancelsTheElicitation(): void
    {
        $bridge = $this->bridgingDesk($this->singleStringElicitation())
            ->withPermissionApprover(static function (): ApprovalVerdict {
                throw new \RuntimeException('the ask channel died');
            });

        self::assertStringContainsString('"action":"cancel"', $bridge->execute(['id' => 't1'])->content());
    }

    public function testTheClosedBridgeCancelsEvenWhenAnApproverArrivesLater(): void
    {
        $asked = 0;
        $server = null;
        $bridge = $this->bridgingDesk($this->singleStringElicitation(), $server)
            ->withoutInteractiveUser('this is a non-interactive run')
            ->withPermissionApprover(static function () use (&$asked): ApprovalVerdict {
                $asked++;

                return ApprovalVerdict::rejectedByUser('anything');
            });

        $bridge->execute(['id' => 'h1']);

        // The closed bridge installs NO per-call desk, so the question lands
        // on the SPAWN-time desk — the one built with approver null, which
        // McpElicitation answers `cancel` by construction (its own cancel arm
        // is pinned by the C-MCP-3.a suite). The lane's law here is that a
        // closed bridge never routes through the per-call answering seam and
        // never puts the question to the late approver.
        self::assertSame('plain', $server->route, 'a closed bridge installed a per-call desk');
        self::assertSame(0, $asked, 'a closed -p bridge must never put a question');
    }

    public function testUnboundBridgesKeepTheSpawnTimeDefaultDesk(): void
    {
        // A bridge with neither binding installed passes NO per-call desk, so
        // McpClient takes the plain transport call and the spawn-time default
        // desk (the one the client installs per server at start) stays in
        // charge — the wire is byte-identical to the pre-C-MCP-3.b shape.
        $server = null;
        $unbound = $this->bridgingDesk(static function (\Closure $handler): array {
            self::fail('an unbound bridge must not install a per-call desk');
        }, $server);

        self::assertFalse($unbound->execute(['id' => 'p1'])->isError());
        self::assertSame('plain', $server->route);

        // A bound bridge over a server that cannot answer requests still
        // rides the plain call — the desk is only installed when the
        // transport speaks for it (AnswersServerRequests gate, landed C-MCP-3.a).
        $plain = $this->bridgingPlainOnly(static fn (): array => ['content' => [['type' => 'text', 'text' => 'plain path']]]);
        self::assertStringContainsString('plain path', $plain->execute(['id' => 'p2'])->content());
    }

    public function testThePerCallDeskAdvertisesTheWorkingDirectoryAsItsRoot(): void
    {
        $roots = null;
        $bridge = $this->bridgingDesk(static function (\Closure $handler) use (&$roots): array {
            $reply = $handler('roots/list', null, false);
            $roots = $reply['result']['roots'] ?? null;

            return ['content' => [['type' => 'text', 'text' => 'ok']]];
        })->withToolProgressEmitter(static function (ToolProgress $frame): void {
        });

        $bridge->execute(['id' => 'root-1']);

        self::assertIsArray($roots);
        self::assertCount(1, $roots);
        self::assertSame('file://' . rtrim((string) getcwd(), '/'), $roots[0]['uri']);
    }

    // =========================================================================
    // The turn itself: turnTools() binds its approver and its event channel
    // =========================================================================

    public function testTheTurnBindsItsApproverAndEventChannelToTheMcpBridge(): void
    {
        // C-MCP-3.b's EngineBackend half: a bridge handed to the launch is
        // given the turn's permission approver AND progress channel by
        // `turnTools()` — no per-tool `withPermissionApprover` call anywhere
        // in the caller. The reflection shape follows the established
        // turnTools pins ({@see DelegationLoaderViewTest}).
        $questionText = 'tmcpturn' . uniqid();
        $askedWith = [];
        $bridge = $this->bridgingDesk(static function (\Closure $handler) use ($questionText): array {
            $reply = $handler('elicitation/create', [
                'requestedSchema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'description' => $questionText]], 'required' => ['note']],
            ], false);
            $handler('notifications/progress', ['progress' => 7, 'message' => 'mid-flight'], true);

            return ['content' => [['type' => 'text', 'text' => json_encode($reply['result'] ?? $reply['error'], JSON_THROW_ON_ERROR)]]];
        });
        $engine = EngineBackend::new(new EchoProvider(), 'turn-binding')
            ->withTools([$bridge->name() => $bridge])
            ->withPermissionApprover(static function (ToolCall $call, HookResult $ask) use (&$askedWith): ApprovalVerdict {
                $askedWith[] = $call;

                return ApprovalVerdict::rejectedByUser('answered by the turn');
            });
        $frames = [];
        $onEvent = static function (ToolProgress $frame) use (&$frames): void {
            $frames[] = $frame;
        };

        $turn = (new \ReflectionMethod($engine, 'turnTools'))->invoke($engine, null, null, $onEvent);
        $result = $this->turnTool($turn, $bridge->name())->execute(['id' => 'turn-1']);

        self::assertCount(1, $askedWith, 'the turn must put the server question through its own approver');
        self::assertSame(AskUserTool::NAME, $askedWith[0]->name());
        self::assertStringContainsString($questionText, (string) $askedWith[0]->arguments()['question']);
        self::assertStringContainsString('answered by the turn', $result->content());
        self::assertCount(1, $frames);
        self::assertSame($bridge->name(), $frames[0]->toolName);
    }

    public function testTheTurnPlainToolArmBindsItsApproverToo(): void
    {
        // The plain (no-progress-binding) arm of turnTools gains the same
        // approver relay so a RelaysPermissionAsks tool that never learns
        // about progress still asks — the McpCallTool/AskUser family shape.
        $reached = 0;
        $tool = new class implements \SugarCraft\Code\Tools\Tool, \SugarCraft\Code\Tools\RelaysPermissionAsks {
            public ?\Closure $approver = null;

            public function name(): string
            {
                return 'plain_relay_probe';
            }

            public function description(): string
            {
                return 'probe';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): \SugarCraft\Code\Tools\ToolResult
            {
                return new \SugarCraft\Code\Tools\ToolResult('probe', 'ok');
            }

            public function withPermissionApprover(\Closure $approver): \SugarCraft\Code\Tools\Tool
            {
                $copy = clone $this;
                $copy->approver = $approver;

                return $copy;
            }
        };
        $engine = EngineBackend::new(new EchoProvider(), 'plain-arm')
            ->withTools([$tool->name() => $tool])
            ->withPermissionApprover(static function () use (&$reached): ApprovalVerdict {
                $reached++;

                return ApprovalVerdict::once();
            });

        $turn = (new \ReflectionMethod($engine, 'turnTools'))->invoke($engine, null, null, null);
        $bound = $this->turnTool($turn, $tool->name());
        self::assertNotNull($bound->approver ?? null, 'the plain arm left the relay tool unbound');
        $bound->approver->__invoke(new ToolCall('x', 'plain_relay_probe', []), HookResult::ask('probe'));
        self::assertSame(1, $reached);
    }

    /**
     * `turnTools()` answers a LIST (the tool's own name, not the roster key,
     * is the lookup fact) — find one by name.
     *
     * @param list<\SugarCraft\Code\Tools\Tool> $turn
     */
    private function turnTool(array $turn, string $name): \SugarCraft\Code\Tools\Tool
    {
        foreach ($turn as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        self::fail("turnTools() did not carry '$name'");
    }

    // =========================================================================
    // Harness — fake answering servers on the real McpClient route
    // =========================================================================

    /**
     * A bridge over a fake stdio-capable server whose one tool call runs
     * $drive through the desk it is handed. The fake records which transport
     * route the call took ($seenServer->route), which is how the desk-presence
     * laws below are pinned.
     *
     * @param \Closure(\Closure(string, ?array<string,mixed>, bool): ?array): array<mixed> $drive
     */
    private function bridgingDesk(\Closure $drive, ?McpServer &$seenServer = null): McpToolBridge
    {
        $descriptor = new McpTool(name: 'ping', description: 'drive me', inputSchema: [], serverName: 'srv');
        $server = new class ($descriptor, $drive) implements McpServer, AnswersServerRequests {
            public string $route = 'untouched';

            /** @param \Closure(\Closure(string, ?array<string,mixed>, bool): ?array): array<mixed> $drive */
            public function __construct(private readonly McpTool $tool, private readonly \Closure $drive)
            {
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                $this->route = 'plain';

                return ['content' => [['type' => 'text', 'text' => 'no desk arrived']]];
            }

            public function callToolAnsweringRequests(string $toolName, array $args, ?\Closure $onWait = null, ?McpServerRequests $serverRequests = null): array
            {
                $this->route = 'answering';
                $handler = $serverRequests?->handler();
                \PHPUnit\Framework\Assert::assertNotNull($handler, 'a desk-bound test call must arrive with its answer desk');

                return ($this->drive)($handler);
            }
        };
        $seenServer = $server;

        return new McpToolBridge($this->clientHolding($server, $descriptor), $descriptor);
    }

    /**
     * The same fake WITHOUT the answering seam — proof an unbound bridge
     * rides the plain call and the spawn-time default desk stays in charge.
     *
     * @param \Closure(): array<mixed> $reply
     */
    private function bridgingPlainOnly(\Closure $reply): McpToolBridge
    {
        $descriptor = new McpTool(name: 'ping', description: 'drive me', inputSchema: [], serverName: 'srv');
        $server = new class ($descriptor, $reply) implements McpServer {
            /** @param \Closure(): array<mixed> $reply */
            public function __construct(private readonly McpTool $tool, private readonly \Closure $reply)
            {
            }

            public function start(): void
            {
            }

            public function stop(): void
            {
            }

            public function listTools(): array
            {
                return [$this->tool];
            }

            public function callTool(string $toolName, array $args): array
            {
                return ($this->reply)();
            }
        };

        return new McpToolBridge($this->clientHolding($server, $descriptor), $descriptor);
    }

    /**
     * @param McpServer&AnswersServerRequests $server
     */
    private function clientHolding(McpServer $server, McpTool $descriptor): McpClient
    {
        $client = new McpClient('/nonexistent/mcp-progress/.mcp.json', unrestricted: true);
        $servers = new \ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $servers->setValue($client, [$descriptor->serverName => $server]);

        return $client;
    }

    /** @return \Closure(\Closure(string, ?array<string,mixed>, bool): ?array): array<mixed> */
    private function singleStringElicitation(): \Closure
    {
        return static function (\Closure $handler): array {
            $reply = $handler('elicitation/create', [
                'requestedSchema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string', 'description' => 'say something']], 'required' => ['note']],
            ], false);

            return ['content' => [['type' => 'text', 'text' => json_encode($reply['result'] ?? $reply['error'], JSON_THROW_ON_ERROR)]]];
        };
    }
}
