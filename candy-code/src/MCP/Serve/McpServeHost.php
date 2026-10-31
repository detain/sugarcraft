<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP\Serve;

use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Permissions\DenialKind;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\McpClient\McpMessage;

/**
 * The stdio JSON-RPC host behind `mcp serve` (C-MCP-5.a).
 *
 * candy-code normally CONSUMES MCP servers; this class is the mirror image —
 * a server an external MCP client (Claude Desktop, an IDE, another agent)
 * drives over its own stdin/stdout, one JSON object per line (NDJSON), every
 * frame framed through {@see McpMessage} so the wire shape matches what this
 * repo's client half already parses.
 *
 * The scope is the plan §6 ruling, verbatim: STDIO ONLY, a READ-ONLY roster
 * ({@see McpServeRoster}), an Ask verdict that REFUSES (there is nobody to
 * ask — the whole point of the serve path is that no interactive approver is
 * attached), and NO session/prompt/memory exposure: there is no `session/*`
 * surface here at all, unlike the ACP server. `initialize` advertises the
 * `tools` capability and nothing else.
 *
 * Every `tools/call` runs through the launch's PreToolUse chain — the same
 * {@see HookManager} a live session gates on, built by the caller with
 * {@see \SugarCraft\Code\Cli\Bootstrap::hooks()} so `ProtectFilesHook`,
 * `ConfirmRemoveHook`, `AuditHook` and the permission gate (installed as the
 * LAST layer behind {@see \SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook})
 * all judge the external caller's call exactly as they judge the model's.
 * The host does not re-implement any of that: an unanswered Ask and a deny
 * both fail closed through {@see HookResult::permitsExecution()} and are
 * rendered with the house {@see DenialKind} prefixes, so a refusal on this
 * path reads the same as one in a transcript.
 *
 * The write closure is the ONLY stdout surface: this class never prints a
 * human-readable line, so a launch that logs cannot corrupt the frame
 * stream. Tool-level failures answer as `isError:true` RESULTS (the MCP
 * convention — the call was understood, the work failed), while protocol
 * misuse answers as JSON-RPC errors (`-32601`, `-32602`, parse errors).
 * Structurally mirroring {@see \SugarCraft\Code\Acp\AcpServer}'s
 * line-in/frame-out skeleton, minus every session-shaped method.
 */
final class McpServeHost
{
    /** The MCP protocol revision answered by `initialize` — the same constant the client half speaks. */
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * The one session id reported on the hook chain for serve calls.
     *
     * Not a real session — nothing is persisted under it — but the audit
     * hook keys its rows on the id's presence, and an empty string would read
     * as "no session" in the trail. A constant makes every serve-path audit
     * row attributable to this surface at a glance.
     */
    public const SESSION_ID = 'mcp-serve';

    /** @var array<string,Tool> keyed by wire name, built from the roster list. */
    private array $tools;

    /**
     * @param list<Tool>                $tools  the roster from {@see McpServeRoster::build()}
     * @param \Closure(string):void     $write  emits exactly one NDJSON line per call, WITHOUT a trailing newline
     * @param string                    $root   the jailed workspace root the roster was built for
     */
    private function __construct(
        array $tools,
        private readonly HookManager $hooks,
        private readonly string $root,
        private readonly \Closure $write,
    ) {
        $this->tools = [];
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @param list<Tool>            $tools
     * @param \Closure(string):void $write
     */
    public static function new(array $tools, HookManager $hooks, string $root, \Closure $write): self
    {
        return new self($tools, $hooks, $root, $write);
    }

    /**
     * Drive the loop over real streams until stdin reaches EOF, then return 0
     * — the ONLY normal exit. Nothing is ever written to stderr from this
     * path: every failure answer rides the frame stream as a JSON-RPC error
     * or an isError result, so the host has no diagnostics channel to open
     * (and no new emitter site for the stderr census).
     *
     * This method is the stream wiring itself: it builds a host whose write
     * closure appends one frame line to $stdout per answer, over the roster +
     * hook chain the caller supplies (normally
     * {@see \SugarCraft\Code\Cli\Bootstrap::hooks()} over a headless-surface
     * gate), so the argv/verb handling that mounts it (C-MCP-5.b) stays a
     * thin shell.
     *
     * @param list<Tool> $tools
     *
     * @param resource $stdin
     * @param resource $stdout
     */
    public static function run(array $tools, HookManager $hooks, string $root, mixed $stdin = STDIN, mixed $stdout = STDOUT): int
    {
        $host = new self(
            $tools,
            $hooks,
            $root,
            static function (string $line) use ($stdout): void {
                fwrite($stdout, $line . "\n");
            },
        );

        while (($line = fgets($stdin)) !== false) {
            $host->receive($line);
            fflush($stdout);
        }

        return 0;
    }

    /**
     * Answer exactly one request line. Public for tests and for any future
     * transport that frames differently but dispatches the same.
     */
    public function receive(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $message = McpMessage::parsePreservingId($line);
        if ($message === null) {
            // Unparseable or non-2.0: JSON-RPC requires the "id could not be
            // read" form — null id, ParseError when the bytes are not JSON at
            // all, InvalidRequest when they are JSON but not a message.
            $this->emitRaw(null, [
                'code' => (json_validate($line) ? ErrorCode::InvalidRequest : ErrorCode::ParseError)->value,
                'message' => 'Could not parse the request as a JSON-RPC 2.0 message.',
            ]);

            return;
        }

        // A response from the client, or a notification (initialized,
        // cancelled, any future one): nothing to answer. Answering a
        // response is the echo loop the AcpServer docblock warns about.
        if ($message->isResponse() || $message->isNotification()) {
            return;
        }
        if (!$message->isRequest()) {
            $this->emitRaw($message->wireId, [
                'code' => ErrorCode::InvalidRequest->value,
                'message' => 'The message is neither a request nor a notification.',
            ]);

            return;
        }

        $id = $message->wireId;
        $method = $message->method ?? '';
        $params = $message->params ?? [];

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping' => new \stdClass(),
                'tools/list' => $this->toolsList(),
                'tools/call' => $this->toolsCall($params),
                default => throw RpcError::of(ErrorCode::MethodNotFound, 'Unknown method: ' . $method),
            };
        } catch (RpcError $error) {
            $this->emitRaw($id, $error->toArray());

            return;
        } catch (\Throwable $error) {
            // A host bug must not kill the stream: answer -32603 and keep
            // serving (same posture as the ACP server's internal-error arm).
            $this->emitRaw($id, ['code' => -32603, 'message' => $error->getMessage()]);

            return;
        }

        $this->emit($id, $result);
    }

    /** @return array<string,mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            // `tools` ONLY. The §6 ruling is that serve exposes no prompts
            // and no resources; advertising them (even empty) would claim a
            // surface this host refuses to grow.
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => 'candy-code', 'version' => Help::version()],
        ];
    }

    /** @return array{tools: list<array<string,mixed>>} */
    private function toolsList(): array
    {
        $rows = [];
        foreach ($this->tools as $tool) {
            $rows[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return ['tools' => $rows];
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array{content: list<array<string,string>>, isError: bool}
     */
    private function toolsCall(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw RpcError::of(ErrorCode::InvalidParams, 'tools/call requires a string `name`.');
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw RpcError::of(ErrorCode::InvalidParams, 'tools/call `arguments` must be a JSON object.');
        }

        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            // An unknown name is a tool ERROR, not a protocol error: the
            // request was well-formed, the capability does not exist. This is
            // the arm that answers "Bash is unknown" — the roster is the
            // whole callable surface, and a client asking for anything else
            // is told so through the result channel, not by losing the call.
            return $this->content('Error: unknown tool: ' . $name, true);
        }

        $context = new HookContext(
            sessionId: self::SESSION_ID,
            toolName: $name,
            toolArgs: $arguments,
            toolInput: json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            toolOutput: '',
            model: '',
            provider: '',
            projectRoot: $this->root,
        );

        $verdict = $this->hooks->preToolUse($context);
        if (!$verdict->permitsExecution()) {
            // An unanswered ASK and a DENY share this arm on purpose (fail
            // closed) but RENDER differently, with the house prefixes:
            // "Permission required:" for a question with nobody to answer it,
            // "Hook denied:" for a guard that said no.
            $kind = $verdict->isAsk() ? DenialKind::Unanswered : DenialKind::Hook;
            $detail = $verdict->message !== ''
                ? $verdict->message
                : 'The ' . $name . ' call was refused by the permission chain.';

            return $this->content($kind->reason($detail), true);
        }
        if ($verdict->isModified()) {
            $rewritten = $verdict->rewrittenArgs();
            if ($rewritten !== null) {
                $arguments = $rewritten;
            }
        }

        try {
            $result = $tool->execute($arguments);
        } catch (\Throwable $error) {
            return $this->content('Error: ' . $error->getMessage(), true);
        }

        return $this->content($result->content(), $result->isError());
    }

    /** @return array{content: list<array<string,string>>, isError: bool} */
    private function content(string $text, bool $isError): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    private function emit(string|int|null $id, mixed $result): void
    {
        $this->out(McpMessage::success((string) ($id ?? ''), $result)->withWireId($id));
    }

    /** @param array<string,mixed> $error */
    private function emitRaw(string|int|null $id, array $error): void
    {
        $code = is_int($error['code'] ?? null) ? $error['code'] : -32603;
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'Internal error.';
        $this->out(McpMessage::error((string) ($id ?? ''), $code, $message, $error['data'] ?? null)->withWireId($id));
    }

    private function out(McpMessage $frame): void
    {
        ($this->write)($frame->toJson());
    }
}
