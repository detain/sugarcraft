<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

use SugarCraft\Code\Context\McpOutputFence;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpElicitation;
use SugarCraft\Code\MCP\McpServerRequests;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Permissions\ApprovalVerdict;
use SugarCraft\Code\Tools\BuiltIn\AskUserTool;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;

/**
 * One MCP server-side tool, presented to the model as an ordinary {@see Tool}.
 *
 * THIS IS THE WHOLE OF "MCP TOOLS ARE REACHABLE BY THE MODEL". {@see McpClient}
 * could already start servers, list their tools and call them; nothing turned a
 * listed {@see McpTool} descriptor into something {@see \SugarCraft\Code\Runtime}
 * would dispatch, so a configured server's tools were invisible to every real
 * run. {@see \SugarCraft\Code\Cli\Bootstrap::tools()} appends one of these per
 * discovered tool.
 *
 * NOT NAMED `McpTool`, and the name is load-bearing: {@see McpTool} is the DTO
 * this class wraps, in the same subsystem. Two types called `McpTool` one `use`
 * apart is the collision crush_code.md Phase 2 item 1 spent a bundle undoing, and
 * this class lives in `src/Tools/` — where `Tool` implementations live — rather
 * than beside the DTO so a future reader is not invited to re-introduce it.
 *
 * WHY IT IS ORDINARY, i.e. why there is no permission check in here: every tool
 * call the engine dispatches goes through {@see \SugarCraft\Code\Runtime::gate()}
 * -> `HookManager::preToolUse()`, and
 * {@see \SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook} rides that chain with
 * a `.*` matcher. So arriving as a plain `Tool` is what puts these calls on the
 * SAME GATING CHAIN `Bash` is on — measured end to end in
 * {@see \SugarCraft\Code\Tests\Integration\McpToolWiringTest}. A second gate in
 * here would be a second policy to keep in step with the first.
 *
 * THE CHAIN IS SHARED; THE DECISION IS NOT ALWAYS THE SAME ONE, and an earlier
 * revision of this note said "the same gating `Bash` gets", which reads as the
 * decision and is false in one mode. Measured on a real
 * {@see \SugarCraft\Code\Cli\Bootstrap::chat()} chain, all six
 * {@see \SugarCraft\Code\Permissions\PermissionMode} values, one `mcp__*` name
 * against `Bash`:
 *
 *     mode                 an mcp__* name      Bash
 *     default              ask                 ask
 *     accept-edits         ask                 ask
 *     plan                 DENIED              ALLOWED   <- diverges
 *     auto                 ASKS                allowed   <- diverges
 *     dont-ask             DENIED              DENIED
 *     bypass-permissions   allowed             allowed
 *
 * Four of six coincide. `auto` diverges because audit F-P3(b) made
 * {@see \SugarCraft\Code\Permissions\PermissionGate::evaluateAuto()} ask
 * before every `mcp__*` call — a server-defined capability gives the safety
 * classifier nothing to read, so it used to run unjudged — while a `Bash`
 * call is classified by its command (`Bash` here is the bare name, which the
 * classifier passes). `plan` diverges because
 * {@see \SugarCraft\Code\Permissions\PermissionGate::evaluatePlan()} allows
 * `Bash` for EXPLORATION — its own doc-block says so — while
 * {@see \SugarCraft\Code\Permissions\PermissionGate::isWriteTool()} treats every
 * `mcp__` name as a write, and a server-side tool's effects are unknowable from
 * here. So both divergences run in the CONSERVATIVE direction: the `mcp__*`
 * name is the more restricted of the two, never the less. It is not a hole, and it is
 * written down because the sentence it replaces was the load-bearing half of a
 * safety argument and `plan` is the mode the differential test drives.
 *
 * THE TABLE IS FOR A TOOL WITH NO `readOnlyHint`. A tool its server
 * declares read-only ({@see readOnly()}: `readOnlyHint: true` and not
 * `openWorldHint: true`) is classified as a READ by
 * {@see \SugarCraft\Code\Permissions\PermissionGate::isReadOnlyTool()}
 * (roadmap 5.11-1), so it runs unasked wherever `Read` does — `default`,
 * `accept-edits`, `plan`, `auto` and `dont-ask` alike. That is the only
 * direction the hint moves, and only for a server the user trusted enough
 * to start (below).
 *
 * `dont-ask` DENIES EVERY UNHINTED MCP TOOL OUTRIGHT — the row above, not an inference.
 * Whether the servers were STARTED is a separate decision made in a separate
 * place: on a root the user has listed under `trustedProjectMcp` they start in
 * every mode including this one, and on a root they have not they start in none.
 * Permission mode is not, and never was, the control over launching; see
 * {@see \SugarCraft\Code\Cli\Bootstrap::mcpClient()}.
 *
 * DELIBERATELY NOT {@see ParallelSafe}: for the reason the `plan` row above
 * turns on — a server-side tool's effects are server-defined and unknowable here
 * — so it is a barrier, which is the same conservative default
 * {@see \SugarCraft\Code\Permissions\PermissionGate::isWriteTool()} applies to
 * the `mcp__*` name.
 */
final class McpToolBridge implements Tool, AcceptsHeartbeat, RelaysPermissionAsks, AcceptsToolProgress
{
    use TruncatesOutput;

    /**
     * The model-facing name prefix. `mcp__<server>__<tool>` is the convention
     * {@see \SugarCraft\Code\Permissions\PermissionRule},
     * {@see \SugarCraft\Code\Permissions\PermissionGate::isWriteTool()} and
     * {@see \SugarCraft\Code\Runtime::runsConcurrently()} were all written
     * against while nothing produced such a name. This class is what makes those
     * three docblocks describe reachable code.
     */
    public const NAME_PREFIX = 'mcp__';

    /**
     * Inline excerpt budget for one embedded resource's text (ISS-044).
     *
     * An eighth of the 64 KiB per-tool default above: a resource part is one
     * piece of one answer, and several may ride the same result — the outer
     * {@see DEFAULT_MAX_OUTPUT_BYTES} clip still bounds the whole render, but
     * a single 60 KiB file should not silently evict the rest of the answer
     * to a marker before that outer clip announces anything.
     */
    public const MAX_RESOURCE_TEXT_BYTES = 8192;

    /**
     * Byte budget for one live progress tail (C-MCP-3.b). The server's own
     * `message` is untrusted text squeezed into a single TUI row / web card
     * line; the frame announces truncation rather than silently cutting.
     */
    public const PROGRESS_TAIL_BUDGET = 4096;

    /**
     * $permissionApprover and $toolProgressEmitter are the TURN's ask channel
     * and live-tail channel, handed in by
     * {@see \SugarCraft\Code\Backend\EngineBackend::turnTools()} (C-MCP-3.b)
     * exactly as they arrive for `Bash`. With neither bound the call rides
     * the spawn-time default desk byte-identically to the pre-C-MCP-3.b wire;
     * with the approver bound a `elicitation/create` is put to the human in
     * the AskUser modal shape, and with the emitter bound each
     * `notifications/progress` becomes a {@see ToolProgress} frame.
     * $elicitationsClosed is the `-p` latch: once
     * {@see \SugarCraft\Code\Cli\NonInteractive::withoutInteractiveUser()}
     * has closed the run, no later binding may re-open the ask channel.
     *
     * $maxOutputBytes bounds what one server answer can push into the context
     * window (item 0.5): the same 64 KiB default the built-in open-ended
     * tools take, clipped with the trait's announced marker. Before it, an
     * MCP result was the one tool output nothing capped — a server returning
     * a whole database dump went into every later request of the turn
     * verbatim. Zero or negative disables the cap.
     */
    public function __construct(
        private McpClient $client,
        private McpTool $descriptor,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
        private ?\Closure $permissionApprover = null,
        private ?\Closure $toolProgressEmitter = null,
        private bool $elicitationsClosed = false,
    ) {
        self::recordReadOnlyHint($this->name(), $this->readOnly());
    }

    /**
     * Seed the read-only table for a wire name WITHOUT constructing a bridge.
     *
     * WHY IT EARNS ITS PLACE: the MCP-7 dispatcher reaches tools that never
     * construct a bridge, so the constructor cannot be the only writer — an
     * unseeded dispatched tool would read as "false for a name no bridge was
     * built under", degrading hint-driven Auto allows to asks and silently
     * widening plan refusals. Same AND-merge, same conservatism: re-recording
     * only ever narrows, never widens.
     */
    public static function recordReadOnlyHint(string $wireName, bool $readOnly): void
    {
        self::$readOnlyByName[$wireName] = (self::$readOnlyByName[$wireName] ?? true) && $readOnly;
    }

    /**
     * The wire spelling of one (server, tool) pair — the exact `name()` of the
     * bridge that pair would get. One funnel so permission parity between the
     * bridge spelling and the `mcp_call` args spelling cannot drift: the
     * dispatcher's synthesized name and a real bridge's name are computed by
     * the same lines.
     */
    public static function wireNameFor(string $server, string $tool): string
    {
        return self::NAME_PREFIX . self::sanitize($server) . '__' . self::sanitize($tool);
    }

    /**
     * The bridge wire name a `mcp_call` argument pair addresses, or null when
     * the pair is not a well-formed dispatch (missing/non-string/empty half).
     *
     * The one dialect {@see \SugarCraft\Code\Permissions\PermissionRule} and
     * {@see \SugarCraft\Code\Permissions\PermissionGate} consult so a rule
     * naming `mcp__server__tool` sees through both spellings of the call.
     *
     * @param array<string, mixed> $arguments
     */
    public static function dispatchedWireName(array $arguments): ?string
    {
        $server = $arguments['server'] ?? null;
        $tool = $arguments['tool'] ?? null;
        if (!is_string($server) || $server === '' || !is_string($tool) || $tool === '') {
            return null;
        }

        return self::wireNameFor($server, $tool);
    }

    /**
     * Wire name => whether EVERY bridge built under that name declared itself
     * read-only ({@see readOnly()}), for
     * {@see \SugarCraft\Code\Permissions\PermissionGate}, which judges a
     * {@see \SugarCraft\Code\ToolCall} — a name and arguments — and never
     * sees the bridge that will run it (roadmap 5.11-1).
     *
     * PROCESS-WIDE AND RECORDED AT CONSTRUCTION, because the gate is built in
     * more places than the tool list is (the TUI, each backend, the agent
     * manager's per-preset factory) and every one of them must agree; a
     * forked turn child inherits the map with the bridges it was built beside.
     *
     * AND-ED, NEVER OVERWRITTEN: two bridges can share a wire name (one server
     * key in two roots' `.mcp.json`, a test fixture), and a later
     * non-read-only one must not be outvoted by an earlier read-only one —
     * once any bridge under a name has not declared itself read-only, the
     * name stays a write for the rest of the process. The conservative
     * direction is the only one a server-asserted hint may move in.
     *
     * @var array<string, bool>
     */
    private static array $readOnlyByName = [];

    /**
     * Does this bridge's server declare the tool read-only, in the sense the
     * permission gate means by it — "safe to run unasked"?
     *
     * `annotations.readOnlyHint === true` ({@see McpTool::readOnlyHint()}),
     * UNLESS the same server also declared `openWorldHint: true`: a tool that
     * says it reaches an open world (a fetch, a search) is the outbound-request
     * shape that took `WebFetch` out of the read-only class (audit F-P6),
     * whatever it says about writing. A server that is silent on
     * `openWorldHint` is taken at its `readOnlyHint` word.
     *
     * HONOURED BECAUSE ONLY A TRUSTED SERVER IS EVER STARTED: a project's
     * `.mcp.json` launches nothing until the user lists the root under
     * `trustedProjectMcp` (and each server's fingerprint is pinned, MCP-5),
     * and an operator-tier server is the user's own. The hint is therefore
     * the claim of a server the user already chose to run, which is the same
     * trust the launch itself spent.
     */
    public function readOnly(): bool
    {
        return $this->descriptor->readOnlyHint() && $this->descriptor->openWorldHint() !== true;
    }

    /**
     * Whether every bridge built in this process under `$wireName` declared
     * itself read-only — false for a name no bridge was built under, which is
     * what an `mcp__*` name the model made up gets. See {@see $readOnlyByName}.
     */
    public static function declaredReadOnly(string $wireName): bool
    {
        return self::$readOnlyByName[$wireName] ?? false;
    }

    /**
     * The descriptor this bridge speaks for, so a caller inspecting
     * {@see \SugarCraft\Code\Cli\Bootstrap::tools()} can tell which server a
     * bridge belongs to without re-deriving it from the name.
     */
    public function descriptor(): McpTool
    {
        return $this->descriptor;
    }

    /**
     * The model-facing name: `mcp__<server>__<tool>`, both segments passed
     * through {@see sanitize()}.
     *
     * THE JOIN IS INJECTIVE, which is what the escape in {@see sanitize()} buys.
     * `__` is the separator, and it used to be a legal character in both segments
     * too, so server `a__b` + tool `c` and server `a` + tool `b__c` composed one
     * name for two different tools with nothing rewritten, and the
     * second-registered one was unaddressable through
     * {@see \SugarCraft\Code\Runtime}'s first-match `findTool()`. Sanitising an
     * underscore to `_5F` means no segment can contain `__` — an underscore on the
     * wire is only ever an escape lead-in followed by two hex digits — so the
     * separator cannot be imitated and no two distinct pairs share a name
     * (E42(a), landed pre-1.0 as its own change, which is the only reason the
     * spelling churn is acceptable here).
     *
     * WHAT A PERMISSION RULE MATCHES is this spelling, not the `.mcp.json` key as
     * typed: see {@see sanitize()} for the worked example. The surfacing half of
     * E42(b) now lives at the CLI: `crush mcp` names every server whose key is
     * rewritten, via {@see wireServerPrefix()} (E665); the in-TUI `/mcp` panel
     * still shows display names only (its renderer is outside this class).
     * The collision half that lived here is fixed.
     *
     * NAMING HAS NEVER BEEN THE ROUTING KEY: {@see execute()} addresses
     * `$this->descriptor->serverName` and the unsanitized server-side tool name,
     * so a bridge that IS reached always reaches the tool it speaks for. The
     * failure the old ambiguity could cause was "unreachable", never "reached the
     * wrong server".
     */
    public function name(): string
    {
        return self::wireNameFor($this->descriptor->serverName, $this->descriptor->name);
    }

    /**
     * One segment of the wire name rewritten so every provider accepts it AND so
     * it cannot imitate the separator.
     *
     * Provider tool names are constrained (`^[a-zA-Z0-9_-]+$` for the OpenAI-shaped
     * APIs every provider here speaks), and an `.mcp.json` server key is free text
     * — `my server`, `github.com/foo` are both plausible. Every byte outside
     * `[A-Za-z0-9-]` becomes `_` plus that byte's two uppercase hex digits:
     * space → `_20`, `.` → `_2E`, `/` → `_2F`, and the underscore ITSELF → `_5F`.
     *
     * WHY THE UNDERSCORE IS ESCAPED, which is the whole point rather than a
     * side-effect. This rule used to map every disallowed character to a bare `_`
     * and leave literal underscores alone, and that had TWO collision sources:
     * `a.b` aliased with `a_b` because a substitution and a literal were the same
     * byte, and `a__b`/`c` aliased with `a`/`b__c` because `__` was BOTH the
     * segment separator of {@see name()} and a legal character in both segments —
     * the latter needing no substitution at all to fire (E42(a)). With an
     * underscore that can only ever appear as an escape lead-in followed by two
     * hex digits, no segment can contain `__` at all, the separator is unique
     * inside the whole name, and the mapping is injective byte-for-byte on both
     * segments — two distinct (server, tool) pairs cannot share one wire name.
     * The cost is churn: a key or tool that spells `_` now reads `_5F` on the
     * wire. That price is paid here rather than carried as a migration because
     * nothing named by this class has shipped to a user's permission rules yet
     * (pre-1.0), which is exactly the condition E42's step set for landing the
     * escape.
     *
     * THE HYPHEN IS IN THE KEPT SET DELIBERATELY, and it is load-bearing rather
     * than tidy: hyphens are ubiquitous in real server keys
     * (`sequential-thinking`, `brave-search`, `aws-kb-retrieval`), the providers
     * accept them, and dropping them from this class would rewrite every such
     * name to an underscore — silently, since nothing about the resulting name
     * looks wrong.
     *
     * A USER-WRITTEN PERMISSION RULE MUST USE THE SANITISED NAME, and that is the
     * part of this escape a reader is most likely to get wrong. A `.mcp.json` key
     * of `github.com/foo` is `github_2Ecom_2Ffoo` inside the wire name to
     * {@see \SugarCraft\Code\Permissions\PermissionRule}; an `allow` written
     * against the key as spelled — `mcp__github.com/foo__*` — matches nothing, and
     * the tool sits behind an Ask forever with the rule looking correct. The name
     * IS discoverable at the moment of decision: the permission prompt names the
     * tool it is asking about, in exactly this spelling — and unprompted via
     * `crush mcp`, which prints this rewrite for every key that needs one (E665,
     * closing the surfacing half of E42 for the CLI arm; docs/SETTINGS.md carries
     * the format).
     *
     * PUBLIC because a consumer that must predict or explain the wire name — the
     * `crush mcp` listing, a settings doc — needs the SAME function the router
     * used, not a paraphrase of it; one spelling rule, one implementation.
     */
    public static function sanitize(string $segment): string
    {
        return (string) preg_replace_callback(
            '/[^A-Za-z0-9-]/',
            static fn(array $match): string => '_' . strtoupper(bin2hex($match[0])),
            $segment,
        );
    }

    /**
     * The wire-name prefix every tool of one server carries: `mcp__<sanitized>__`.
     *
     * WHAT IS SAID: callers that surface the wire spelling — the `crush mcp`
     * notice for a rewritten key — need the server segment with its separators,
     * not a guess at the full tool name (the tool segment is per-tool).
     *
     * TRUE NOW: composes exactly the leading part of {@see name()}, so a rule
     * written as `prefix*` matches what the router will look up.
     *
     * WHY IT EARNS ITS PLACE: duplicating the `NAME_PREFIX . sanitize() . '__'`
     * join in a CLI printer is a drift risk for the one property that matters —
     * that the surfaced spelling equals the matched spelling.
     */
    public static function wireServerPrefix(string $server): string
    {
        return self::NAME_PREFIX . self::sanitize($server) . '__';
    }

    /**
     * The server's own description, with the server named in front of it.
     *
     * Prefixed rather than passed through because the model sees a flat tool list:
     * two servers exposing `search` with the same one-line description give it no
     * way to choose. A server that supplied no description at all still gets a
     * non-empty one, which the `Tool` contract's consumers assume.
     */
    public function description(): string
    {
        $own = trim($this->descriptor->description);

        return $own === ''
            ? sprintf('MCP tool "%s" on server "%s".', $this->descriptor->name, $this->descriptor->serverName)
            : sprintf('[MCP %s] %s', $this->descriptor->serverName, $own);
    }

    /**
     * The server's `inputSchema`, with the ROOT normalised to the three keys
     * every consumer of this interface reads.
     *
     * THE ROOT, AND ONLY THE ROOT — the scope is the whole of what this method
     * claims, and an earlier version of this note claimed more ("a bridge is a
     * valid schema on its own"), which was true only of a schema with no nested
     * objects in it. An MCP server may omit `required` (or `properties`, for a
     * no-argument tool), and PHP's `[]` encodes as the JSON array `[]` where JSON
     * Schema requires an object for `properties` — which SGLang rejects for the
     * WHOLE `chat/completions` request, not just the offending tool (see
     * {@see \SugarCraft\Code\Tests\Providers\ToolSchemaEncodingTest}). A
     * NESTED no-argument object (`properties: {opts: {type: object, properties:
     * []}}`, a routine MCP shape) carries the same defect one level down, and
     * fixing that is NOT done here.
     *
     * WHERE IT IS DONE, and why not in both places:
     * {@see \SugarCraft\Code\Providers\Concerns\ToolSchema::normalizeToolSchema()}
     * walks the schema recursively, and it is the right and only home for that
     * walk because the defect is an ENCODING defect — it exists at the
     * `json_encode()` boundary, which is that trait's job and not this class's.
     * Two recursive walks over the same shape in two packages is two things to
     * keep in step; every provider that puts a tool on the wire already routes
     * through the trait. What stays here is the part that is about the `Tool`
     * CONTRACT rather than the wire: a caller reading `inputSchema()['properties']`
     * or `['required']` directly gets the documented shape without having to
     * defend against a server that omitted either — or, for `required`, sent it
     * in a shape the contract does not allow, which is {@see requiredList()}'s
     * whole subject and was a silent discard until it existed.
     *
     * `properties` CANNOT ARRIVE AS A `stdClass`, so nothing tests for one:
     * {@see \SugarCraft\Code\MCP\McpTool::inputSchema} is filled from
     * `json_decode($line, true)` in {@see \SugarCraft\Code\MCP\StdioMcpServer}
     * and from the equally associative Guzzle decode in
     * {@see \SugarCraft\Code\MCP\HttpMcpServer}, so every nested value is an
     * array. A defensive `instanceof \stdClass` clause here was unreachable and
     * is gone.
     *
     * Any other key the server sent is preserved: `$defs`, `additionalProperties`
     * and friends are the server's contract with its own tool, and dropping them
     * would silently loosen it.
     */
    public function inputSchema(): array
    {
        $schema = $this->descriptor->inputSchema;

        $schema['type'] = 'object';

        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            $properties = [];
        }
        $schema['properties'] = $properties === [] ? new \stdClass() : $properties;

        $schema['required'] = self::requiredList($schema['required'] ?? null);

        return $schema;
    }

    /**
     * A server's `required` coerced to the list of property names the `Tool`
     * contract promises.
     *
     * WHY IT IS NOT JUST `is_array() ? : []`, which is what this was: a
     * `"required": "note"` — a single required argument written as a bare string,
     * which is the mistake a hand-written config makes — became `[]`, and the
     * model was then free to omit an argument the server genuinely requires. The
     * doc-block above says dropping a key "would silently loosen it"; that
     * discarded the whole constraint.
     *
     * THE RULE, and every row of it is tested in
     * {@see \SugarCraft\Code\Tests\Tools\McpToolBridgeTest}:
     *
     *     "note"            -> ['note']    one required argument, written flat
     *     ['a', 'b']        -> ['a', 'b']  passed through
     *     ['a', 7]          -> ['a', '7']  a JSON object key is always a string
     *     ['a', true, null] -> ['a']       no property-name reading exists
     *     absent, null, 7, {} -> []        no constraint expressible
     *
     * A NUMBER BECOMES ITS DECIMAL SPELLING rather than being dropped, because
     * `{"required":[1]}` can only ever have meant the property named `"1"` —
     * object keys in JSON are strings, so the tightening is exact rather than
     * invented.
     *
     * A BOOLEAN, A NULL OR A NESTED STRUCTURE IS DROPPED, AND THAT IS A KNOWN
     * LOOSENING, said plainly because the alternative to saying it is the defect
     * one level down: the model may then omit whatever the server meant. The two
     * alternatives are worse. Keeping the entry as-is puts a non-string in a JSON
     * Schema `required`, which is invalid and 400s the request that carries it
     * (see {@see \SugarCraft\Code\Providers\Concerns\ToolSchema} for how total
     * that failure is); coercing it invents a property name that cannot be
     * derived. Losing one constraint on one tool is the only outcome that leaves
     * the tool usable.
     *
     * THE RESULT IS ACCUMULATED, NOT FILTERED IN PLACE, and that is the encoding
     * half of the same defect `properties` has above: `unset()`ing index 1 of a
     * three-element list leaves the keys `[0, 2]`, which `json_encode()` renders
     * as the OBJECT `{"0":…,"2":…}` where JSON Schema requires an array. Appending
     * to a fresh array cannot produce a gap, so there is nothing to re-index and
     * no `array_values()` here to imply that there was.
     *
     * KEYS ARE DISCARDED, ORDER IS KEPT: a `required` that arrived as a JSON
     * OBJECT rather than an array is malformed either way, and reading its values
     * applies the same per-entry rule instead of adding a fourth branch for a
     * shape no server should send.
     *
     * @return list<string>
     */
    private static function requiredList(mixed $required): array
    {
        if (is_string($required)) {
            return [$required];
        }

        if (!is_array($required)) {
            return [];
        }

        $names = [];
        foreach ($required as $entry) {
            if (is_string($entry)) {
                $names[] = $entry;
            } elseif (is_int($entry) || is_float($entry)) {
                $names[] = (string) $entry;
            }
        }

        return $names;
    }

    /**
     * Call the tool on its server and turn whatever comes back into a
     * {@see ToolResult}.
     *
     * ADDRESSED BY SERVER, NOT BY NAME. {@see McpClient::callToolByName()} matches
     * on the TOOL NAME ALONE and returns the first server that advertises it, and
     * this bridge holds `$this->descriptor->serverName` — so calling by name threw
     * away the one piece of information that says where the call belongs. Two
     * servers each advertising `search` is utterly ordinary (a wiki server and a
     * ticket server, differently credentialed), both get distinct and perfectly
     * valid wire names here, and both resolve; the calls for the second one all
     * landed on the first. MEASURED before this changed:
     *
     *     mcp__alpha__search  server=alpha  -> ANSWERED-BY:ALPHA
     *     mcp__beta__search   server=beta   -> ANSWERED-BY:ALPHA
     *
     * No sanitisation collision is involved — see {@see sanitize()} for the
     * separate and much smaller cost that one has.
     * {@see McpClient::callTool()} takes the server explicitly and is permitted
     * under the same `unrestricted` posture, so this is a strictly narrower
     * request with no policy change.
     *
     * EVERY FAILURE BECOMES AN ERROR RESULT, never an exception out of here, and
     * that is a decision rather than defensiveness: {@see McpClient::callTool()}
     * throws `RuntimeException` for a server it does not hold and for a server the
     * routing policy denies, and a transport can throw
     * anything at all (Guzzle for `type: http`), while the thing on the other end
     * of this call is third-party code reached over a pipe or a socket. Runtime's
     * own `try` around the tool body would already stop such a throw from costing
     * the turn, but it degrades to a generic annotation; catching here is what
     * lets the model be told WHICH MCP tool failed and how, in a result it can act
     * on. `\Throwable` rather than `\RuntimeException` for the same reason — a
     * `TypeError` from a server that answered with a shape nobody expected is not
     * a reason to lose the turn's other results.
     */
    public function execute(array $args): ToolResult
    {
        return $this->call($args, null);
    }

    /**
     * {@see execute()} with the turn's liveness beat handed to the transport
     * (item 0.4-b). A stdio `tools/call` is unbounded by design (E646), and an
     * MCP tool always runs alone — it is a barrier, never {@see ParallelSafe}
     * — so without the beat any call longer than the turn's 120 s idle
     * ceiling cost the whole turn rather than just the wait.
     */
    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        return $this->call($args, $heartbeat);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function call(array $args, ?\Closure $heartbeat): ToolResult
    {
        $id = is_string($args['id'] ?? null) ? $args['id'] : '';
        $start = hrtime(true);

        try {
            $raw = $this->client->callTool(
                $this->descriptor->serverName,
                $this->descriptor->name,
                $args,
                $heartbeat,
                $this->perCallDesk($id),
            );
        } catch (\Throwable $e) {
            return new ToolResult(
                toolCallId: $id,
                content: sprintf('Error: MCP tool %s failed: %s', $this->name(), $e->getMessage()),
                isError: true,
                durationMs: self::elapsedMs($start),
            );
        }

        return self::replyResult(
            $raw,
            $id,
            $start,
            $this->descriptor->serverName,
            $this->descriptor->name,
            fn (string $text): string => $this->truncateOutput($text, $this->maxOutputBytes),
        );
    }

    /**
     * {@see RelaysPermissionAsks}: the turn's ask channel, relayed into the
     * per-call answer desk so `elicitation/create` reaches the human (the
     * modal shows the AskUser shape, C-MCP-3.b). Once
     * {@see withoutInteractiveUser()} has closed the run, a later binding is
     * IGNORED — the `-p` decision is made once at launch, the same sticky law
     * {@see \SugarCraft\Code\Cli\NonInteractive::withoutInteractiveUser()}
     * enforces for `AskUserTool`.
     */
    public function withPermissionApprover(\Closure $approver): Tool
    {
        if ($this->elicitationsClosed) {
            return $this;
        }

        return new self(...array_replace(get_object_vars($this), ['permissionApprover' => $approver]));
    }

    /**
     * {@see AcceptsToolProgress}: the turn's live-tail channel, fed by
     * `notifications/progress` frames the server pushes mid-call. Progress
     * is NOT an ask — it survives even a closed `-p` run, where nobody is
     * queried and the frames only ever stream into the running row.
     */
    public function withToolProgressEmitter(\Closure $emit): self
    {
        return new self(...array_replace(get_object_vars($this), ['toolProgressEmitter' => $emit]));
    }

    /**
     * The `-p` closure: elicitations cancel and no later approver re-opens
     * the channel. $why rides the same funnel argument the `AskUser`/
     * `PlanExit` closures pass; the bridge stores no copy because a canceled
     * elicitation is announced to the server by its ACTION, and the model
     * learns the silence from the server's own follow-up, not from prose we
     * would invent here.
     */
    public function withoutInteractiveUser(string $why): self
    {
        return new self(...array_replace(get_object_vars($this), [
            'permissionApprover' => null,
            'elicitationsClosed' => true,
        ]));
    }

    /**
     * The per-call answer desk, or null to leave the call on the spawn-time
     * default desk (byte-identical to the pre-C-MCP-3.b wire).
     *
     * ROOT NOTE (disclosed deviation): the desk is built with an empty root,
     * so a mid-call `roots/list` answers the PROCESS working directory —
     * {@see McpClient::stdioSpawnCwd()} is private and the client file is
     * owned by a concurrent lane (C-MCP-4.b), so the bridge cannot see the
     * tier's spawn root. The install-time desk (C-MCP-3.a) still answers
     * `roots/list` with the tier directory whenever no per-call desk is
     * installed; docs/MCP.md §Server requests states both readings.
     */
    private function perCallDesk(string $callId): ?McpServerRequests
    {
        $ask = $this->elicitationsClosed ? null : $this->permissionApprover;
        $emit = $this->toolProgressEmitter;
        if ($ask === null && ($emit === null || $callId === '')) {
            return null;
        }

        return new McpServerRequests(
            '',
            $ask === null ? null : function (array $questions) use ($ask, $callId): mixed {
                return self::askElicitation($ask, $this->descriptor->serverName, $callId, $questions);
            },
            $emit === null || $callId === '' ? null : self::progressSink($emit, $callId, $this->name()),
        );
    }

    /**
     * Answer one server elicitation through the turn's ask channel: each
     * schema field becomes a short question in the AskUser modal shape, in
     * declaration order, and the person's words are coerced to the field's
     * declared type. A multi-field form is therefore a SHORT SERIES of
     * modals — the risk the plan disclosed — not a new form UI.
     *
     * Return contract is {@see McpElicitation}'s approver dialect: a field
     * map, or the CANCEL/DECLINE sentinel. Anything that could not be put
     * (no channel, thrown channel, an answer that matches no offered option)
     * cancels rather than inventing: an MCP server must never receive an
     * answer nobody gave.
     *
     * PUBLIC/STATIC because both MCP spellings (this bridge and the MCP-7
     * dispatcher) must put ONE dialect, the same reason
     * {@see replyResult()} exists.
     *
     * @param \Closure(ToolCall, HookResult): ApprovalVerdict $approver
     * @param list<array<string,mixed>> $questions
     *
     * @return array<string,mixed>|string the field map, or a McpElicitation sentinel
     */
    public static function askElicitation(?\Closure $approver, string $server, string $callId, array $questions): mixed
    {
        if ($approver === null) {
            return McpElicitation::CANCEL;
        }

        $answers = [];
        foreach ($questions as $question) {
            $text = Lang::t('mcp.elicitation.ask', [
                'server' => $server,
                'question' => self::scrubServerText((string) ($question['message'] ?? '')),
            ]);
            $enum = $question['enum'] ?? null;
            $options = is_array($enum)
                ? array_values(array_map(static fn (mixed $o): string => self::scrubServerText((string) $o), $enum))
                : [];

            try {
                $verdict = ApprovalVerdict::of($approver(
                    new ToolCall($callId, AskUserTool::NAME, [
                        'description' => AskUserTool::label($text),
                        'question' => $text,
                        'options' => $options,
                    ]),
                    HookResult::ask(AskUserTool::prompt($text, $options)),
                ));
            } catch (\Throwable) {
                return McpElicitation::CANCEL;
            }

            if ($verdict->isUnanswered()) {
                return McpElicitation::CANCEL;
            }

            $typed = AskUserTool::typedAnswer($verdict->feedback);
            if ($typed === null) {
                // A reason the harness wrote, not the person's words — the
                // question was never actually put; the server learns cancel.
                return McpElicitation::CANCEL;
            }

            if ($typed === '') {
                if (!$verdict->permits()) {
                    return McpElicitation::DECLINE;
                }
                if ($options !== []) {
                    $answers[(string) $question['key']] = $options[0];
                    continue;
                }
                if ((string) ($question['type'] ?? 'string') === 'boolean') {
                    $answers[(string) $question['key']] = true;
                    continue;
                }
                if (($question['required'] ?? false) === true) {
                    // "y" on a free-input question is not an answer to it.
                    return McpElicitation::CANCEL;
                }
                continue;
            }

            try {
                $answers[(string) $question['key']] = self::coerceElicitationAnswer($typed, $question, $options);
            } catch (\InvalidArgumentException) {
                return McpElicitation::CANCEL;
            }
        }

        return $answers;
    }

    /**
     * The sink that turns each `notifications/progress` parameter map into a
     * {@see ToolProgress} frame on the turn's channel, seq counting from 1.
     *
     * PUBLIC/STATIC for the dispatcher spelling's parity, like
     * {@see askElicitation()}.
     */
    public static function progressSink(\Closure $emit, string $callId, string $toolName): \Closure
    {
        $seq = 0;

        return static function (array $params) use ($emit, $callId, $toolName, &$seq): void {
            $seq++;
            [$tail, $truncated] = self::progressTail($params);
            $emit(new ToolProgress(
                toolCallId: $callId,
                toolName: $toolName,
                tail: $tail,
                seq: $seq,
                truncated: $truncated,
                tailBudget: self::PROGRESS_TAIL_BUDGET,
            ));
        };
    }

    /**
     * One line of untrusted server copy, safe for a single TUI row / web
     * card line: control bytes collapse to spaces and the two mouse-zone
     * sentinel codepoints are swept, because a progress tail rides marked
     * frames downstream (the fleet's untrusted-content law).
     */
    public static function scrubServerText(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F\x{e000}\x{e001}]+/u', ' ', $text));
    }

    /**
     * @param array<string,mixed> $question one entry from {@see McpElicitation::questions()}
     * @param list<string> $options the scrubbed enum list, [] without one
     *
     * @throws \InvalidArgumentException when the words answer nothing of the field's type
     */
    private static function coerceElicitationAnswer(string $typed, array $question, array $options): mixed
    {
        $typed = trim($typed);
        $type = (string) ($question['type'] ?? 'string');

        if ($options !== []) {
            $index = AskUserTool::pickedOption($typed, $options);
            if ($index === null) {
                throw new \InvalidArgumentException('the answer is none of the offered options');
            }

            return $options[$index];
        }

        switch ($type) {
            case 'integer':
                if (preg_match('/\A[+-]{0,1}\d+\z/', $typed) !== 1) {
                    throw new \InvalidArgumentException('the field wants a whole number');
                }

                return (int) $typed;

            case 'number':
                if (preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/', $typed) !== 1) {
                    throw new \InvalidArgumentException('the field wants a number');
                }

                return preg_match('/\A[+-]{0,1}\d+\z/', $typed) === 1 ? (int) $typed : (float) $typed;

            case 'boolean':
                return match (strtolower($typed)) {
                    'y', 'yes', 'true', '1', 'on' => true,
                    'n', 'no', 'false', '0', 'off' => false,
                    default => throw new \InvalidArgumentException('the field wants yes or no'),
                };

            default:
                return $typed;
        }
    }

    /**
     * The tail text and truncation flag for one progress notification: the
     * server's own `message` when it carried one, else its bare pacing
     * (percent when the server named a total, the raw figure otherwise) —
     * numbers the server itself wrote, so no English is minted here and no
     * lang key is earned.
     *
     * @param array<string,mixed> $params
     *
     * @return array{0: string, 1: bool}
     */
    private static function progressTail(array $params): array
    {
        $message = $params['message'] ?? null;
        $text = is_string($message) && trim($message) !== ''
            ? self::scrubServerText($message)
            : self::progressPacing($params);

        $truncated = strlen($text) > self::PROGRESS_TAIL_BUDGET;
        if ($truncated) {
            $text = substr($text, -self::PROGRESS_TAIL_BUDGET);
        }

        return [$text, $truncated];
    }

    /**
     * @param array<string,mixed> $params
     */
    private static function progressPacing(array $params): string
    {
        $progress = $params['progress'] ?? null;
        if (!is_numeric($progress)) {
            return '';
        }

        $total = $params['total'] ?? null;
        if (is_numeric($total) && (float) $total > 0.0) {
            $pct = ((float) $progress / (float) $total) * 100.0;

            return rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.') . '%';
        }

        return is_float($progress) ? rtrim(rtrim(number_format($progress, 1, '.', ''), '0'), '.') : (string) $progress;
    }

    /**
     * Turn one raw `tools/call` reply into the ToolResult envelope the bridge
     * has always produced — same announcement, same renderer, same error-flag
     * precedence; only the truncation budget arrives as a closure.
     *
     * WHY IT EARNS ITS PLACE: the MCP-7 dispatcher reaches the very same
     * servers through `mcp_call` and must return byte-identical results — if
     * rendering lived only in the bridge, the two spellings of one capability
     * would answer with two different renderers, and the parity promise would
     * stop at the permission gate. `TruncatesOutput` is trait-private in each
     * class, so the cutter rides in rather than being duplicated.
     *
     * $server and $tool name the answer's origin for {@see McpOutputFence::wrap()}
     * (C-SEC-8.a): the body is fenced BEFORE $truncate runs, so the provenance
     * header survives even a clipped answer, and because both call sites pass
     * the names they dispatched on, the two spellings fence byte-identically.
     *
     * @param array<string, mixed> $raw
     */
    public static function replyResult(array $raw, string $id, float|int $start, string $server, string $tool, callable $truncate): ToolResult
    {
        return new ToolResult(
            toolCallId: $id,
            content: $truncate(McpOutputFence::wrap(self::announceUnreadableErrorFlag($raw) . self::renderContent($raw), $server, $tool)),
            isError: self::isError($raw),
            durationMs: self::elapsedMs($start),
        );
    }

    /** Elapsed whole milliseconds since `$start`; public so the MCP-7 dispatcher stamps identically. */
    public static function elapsedMs(float|int $start): int
    {
        return (int) ((hrtime(true) - $start) / 1_000_000);
    }

    /**
     * Did the server report a failure?
     *
     * TWO shapes, because two producers exist. `isError` is the MCP spec's own
     * flag on a `tools/call` result; `['error' => …]` is what
     * {@see \SugarCraft\Code\MCP\StdioMcpServer::callTool()} substitutes when no
     * response arrives at all, and treating that as a success would hand the model
     * the literal string "Tool call failed" as an answer. `['error' => …]` is
     * checked FIRST and unconditionally, so an `isError: false` sitting next to an
     * `error` key still errors.
     *
     * NOT `=== true`, WHICH IS WHAT THIS WAS. MCP servers are written in
     * dynamically-typed languages and emit the flag as a string or a number:
     * measured on the strict comparison, `isError: "true"` and `isError: 1` both
     * came out FALSE and the failure text was then handed to the model as the
     * tool's ANSWER — a server-reported error read as success, which is the one
     * direction this predicate must never get wrong.
     *
     * AND NOT PHP TRUTHINESS EITHER, which is the trap in the obvious fix:
     * `(bool) "false"` is TRUE, so a loose cast inverts the common spelling of
     * the common case. {@see parseErrorFlag()} interprets each shape explicitly.
     *
     * @param array<mixed> $raw
     */
    private static function isError(array $raw): bool
    {
        if (isset($raw['error'])) {
            return true;
        }

        // A flag this client cannot read is treated as a FAILURE — see
        // {@see parseErrorFlag()} for why that direction, and
        // {@see announceUnreadableErrorFlag()} for what the model is told.
        return self::parseErrorFlag($raw['isError'] ?? null) ?? true;
    }

    /**
     * One `isError` value read as the boolean it was meant to be, or `null` when
     * no reading exists.
     *
     *     true, 1, 1.0, "1", "true"            -> error
     *     false, 0, 0.0, "0", "false", ""      -> not an error
     *     absent, null                         -> not an error
     *     anything else (2, [], "maybe", …)    -> null, i.e. unreadable
     *
     * Strings are trimmed and case-folded, because `"TRUE"` and `" true"` are the
     * same intent as `"true"` and a server that emits one emits the others.
     *
     * UNREADABLE MEANS FAILURE, and that is a deliberate choice rather than
     * defensiveness — it is the non-obvious half of this method. An `isError` the
     * client cannot parse is a protocol violation by the server, and the two
     * outcomes are not symmetric: calling it success presents a possible failure
     * to the model AS THE ANSWER, silently and with nothing in the transcript,
     * which is exactly the defect the `=== true` comparison had. Calling it a
     * failure costs a tool call the model can retry, and
     * {@see announceUnreadableErrorFlag()} puts the raw value in front of it so
     * the misbehaving server is nameable rather than merely unlucky.
     */
    private static function parseErrorFlag(mixed $flag): ?bool
    {
        if ($flag === null) {
            return false;
        }

        if (is_bool($flag)) {
            return $flag;
        }

        if (is_int($flag) || is_float($flag)) {
            // Widened to float so one comparison covers `1` and `1.0` without a
            // loose `==`, which would also accept `"1"` and hide the string arm
            // below.
            return match ((float) $flag) {
                1.0 => true,
                0.0 => false,
                default => null,
            };
        }

        if (is_string($flag)) {
            return match (strtolower(trim($flag))) {
                '1', 'true' => true,
                '0', 'false', '' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * The line prepended to the content when the server's `isError` could not be
     * read at all — empty on every other result.
     *
     * PREPENDED RATHER THAN REPLACING THE CONTENT: whatever the server did send is
     * still the most useful thing the model has, and the announcement is about the
     * ENVELOPE. Naming the raw value is the point — "unreadable" alone tells a
     * user nothing about which server to go and fix.
     *
     * @param array<mixed> $raw
     */
    private static function announceUnreadableErrorFlag(array $raw): string
    {
        if (!array_key_exists('isError', $raw) || self::parseErrorFlag($raw['isError']) !== null) {
            return '';
        }

        // json_encode() rather than {@see encode()}, which passes a string
        // through unquoted: `"maybe"` and a hypothetical bare token would then
        // look identical, and telling `1` from `"1"` is most of the diagnostic.
        // Its `false` return is reachable here — NAN and INF are exactly the
        // numbers that fail to parse AND fail to encode.
        $json = json_encode($raw['isError']);

        return sprintf(
            "[unreadable isError: %s — treated as a failure]\n",
            $json === false ? 'a value that cannot be encoded' : $json,
        );
    }

    /**
     * An MCP `tools/call` result rendered as the text a model reads.
     *
     * The spec's shape is `content: [{type: "text", text: …}, …]`, so the text
     * parts are joined. A non-text part is announced rather than dropped — and
     * since ISS-044 it is announced with the SUMMARIES it actually carries
     * ({@see renderPart()}), not by type alone: an embedded resource's text is
     * readable by any model, and an image's mime type and byte count say what
     * `[image]` never did. The older hazard stands either way: a tool whose
     * whole answer was one image would otherwise return the empty string, which
     * reads as success with no output. Anything that is not the spec shape at all is JSON-encoded, on
     * the principle that showing the model the raw envelope beats inventing a
     * summary of it.
     *
     * AND NEITHER IS THE EMPTY STRING EVER RETURNED, which is the same decision
     * one step further out. The non-text announce above exists because "one image"
     * would otherwise render as `''`, and `content: []` — an empty list, the
     * shape a server sends for a call that did its work and had nothing to say —
     * produced exactly that `''`, on a result whose `isError` is false: success
     * with no output, indistinguishable from a tool that silently did nothing.
     * So an empty render is announced too, in the same bracketed shape the
     * non-text parts use rather than a second style for the same idea.
     *
     * TWO ANNOUNCES, BECAUSE THE CODE CAN ACTUALLY TELL THEM APART — stated as
     * the mechanism rather than as an intention, since only the mechanism is
     * true. `[no content]` means there was nothing to render: `content` was the
     * empty list, or the envelope-encoding fallback produced nothing (an
     * `['error' => '']` does). `[empty content]` means parts WERE present and
     * every one of them rendered to the empty string — a lone
     * `{"type":"text","text":""}` is the shape. Nothing here guesses at the
     * server's intent; one case is an empty list, the other is a non-empty list
     * that joined to `''`.
     *
     * NOT CONDITIONED ON `isError`. A failure with no message is as unreadable as
     * a success with no output, and the failure is carried by
     * {@see ToolResult::isError()} beside this text rather than by it, so the
     * announce says only what the envelope contained.
     *
     * @param array<mixed> $raw
     */
    private static function renderContent(array $raw): string
    {
        $content = $raw['content'] ?? null;

        if (!is_array($content)) {
            $rendered = isset($raw['error'])
                ? self::encode($raw['error'])
                : self::encode($raw);

            return $rendered === '' ? '[no content]' : $rendered;
        }

        if ($content === []) {
            return '[no content]';
        }

        $parts = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                $parts[] = self::encode($part);

                continue;
            }

            if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];

                continue;
            }

            $parts[] = self::renderPart($part);
        }

        $rendered = implode("\n", $parts);

        return $rendered === '' ? '[empty content]' : $rendered;
    }

    /**
     * A non-text content part rendered as the summary a model can actually use.
     *
     * (ISS-044) The arm this replaced announced every non-text part by type
     * alone — `[image]`, `[resource]` — which discarded exactly the payload the
     * part carries: an embedded file's TEXT is a string any model reads, and a
     * base64 blob dumped inline would burn the context window while rendering
     * to nothing the model can see. So binary payloads are announced by mime
     * type and byte count and left UNDECODING-FREE, an embedded resource's text
     * rides inline under {@see MAX_RESOURCE_TEXT_BYTES}, and the whole answer
     * still passes through the per-tool output cap at {@see call()}, which
     * clips this render with the trait's announced marker as its outer bound.
     *
     * A vision-attachment path (handing the bytes to a capable provider as an
     * actual image) would have to thread provider capability through surfaces
     * outside this class's contract; the size line keeps the answer honest
     * about what it saw until that exists.
     *
     * @param array<string, mixed> $part
     */
    private static function renderPart(array $part): string
    {
        $type = is_string($part['type'] ?? null) ? $part['type'] : 'unknown';

        return match ($type) {
            'image', 'audio' => self::renderInlineBytes($type, $part),
            'resource' => self::renderResource($part),
            'resource_link' => self::renderResourceLink($part),
            default => '[' . $type . ']',
        };
    }

    /**
     * An `image`/`audio` part: mime and size named, base64 never dumped.
     *
     * @param array<string, mixed> $part
     */
    private static function renderInlineBytes(string $type, array $part): string
    {
        $mime = is_string($part['mimeType'] ?? null) && $part['mimeType'] !== ''
            ? $part['mimeType']
            : 'unknown mime';
        $data = $part['data'] ?? null;

        if (!is_string($data) || $data === '') {
            return '[' . $type . ': ' . $mime . ', no data attached]';
        }

        return sprintf('[%s: %s, %d base64 bytes not shown]', $type, $mime, strlen($data));
    }

    /**
     * An MCP `resource` (embeddedResource) part: `resource: {uri, text?, blob?, mimeType?}`.
     *
     * TEXT is the case ISS-044 was filed for — a server returning a file's
     * contents as an embedded resource used to render to the literal string
     * `[resource]`. The excerpt is capped because one resource can be a whole
     * dump; the marker states the cap and the true size so the model knows
     * exactly what it did not get. BLOB is binary and behaves like the inline
     * image bytes: counted, never shown. Neither, and the uri still names what
     * the server attached.
     *
     * @param array<string, mixed> $part
     */
    private static function renderResource(array $part): string
    {
        $resource = $part['resource'] ?? null;

        if (!is_array($resource)) {
            return '[resource]';
        }

        $uri = is_string($resource['uri'] ?? null) && $resource['uri'] !== ''
            ? $resource['uri']
            : 'unknown uri';

        $text = $resource['text'] ?? null;
        if (is_string($text) && $text !== '') {
            if (strlen($text) > self::MAX_RESOURCE_TEXT_BYTES) {
                $text = substr($text, 0, self::MAX_RESOURCE_TEXT_BYTES) . sprintf(
                    ' … [resource text capped at %d of %d bytes]',
                    self::MAX_RESOURCE_TEXT_BYTES,
                    strlen($text),
                );
            }

            return '[resource: ' . $uri . "]\n" . $text;
        }

        $blob = $resource['blob'] ?? null;
        if (is_string($blob) && $blob !== '') {
            $mime = is_string($resource['mimeType'] ?? null) && $resource['mimeType'] !== ''
                ? $resource['mimeType']
                : 'unknown mime';

            return sprintf('[resource: %s: %s, %d base64 bytes not shown]', $uri, $mime, strlen($blob));
        }

        return '[resource: ' . $uri . ']';
    }

    /**
     * A `resource_link` part: a reference the server expects the client to
     * fetch elsewhere, so name and uri are the whole useful payload.
     *
     * @param array<string, mixed> $part
     */
    private static function renderResourceLink(array $part): string
    {
        $uri = is_string($part['uri'] ?? null) ? $part['uri'] : '';
        $name = is_string($part['name'] ?? null) && $part['name'] !== '' ? $part['name'] : '';

        if ($name === '' || $name === $uri) {
            return '[resource link: ' . ($uri !== '' ? $uri : 'unidentified') . ']';
        }

        return '[resource link: ' . $name . ' -> ' . $uri . ']';
    }

    private static function encode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value);

        return $json === false ? '' : $json;
    }
}
