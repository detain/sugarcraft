<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\MCP\McpTool;

/**
 * One MCP server-side tool, presented to the model as an ordinary {@see Tool}.
 *
 * THIS IS THE WHOLE OF "MCP TOOLS ARE REACHABLE BY THE MODEL". {@see McpClient}
 * could already start servers, list their tools and call them; nothing turned a
 * listed {@see McpTool} descriptor into something {@see \SugarCraft\Crush\Runtime}
 * would dispatch, so a configured server's tools were invisible to every real
 * run. {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} appends one of these per
 * discovered tool.
 *
 * NOT NAMED `McpTool`, and the name is load-bearing: {@see McpTool} is the DTO
 * this class wraps, in the same subsystem. Two types called `McpTool` one `use`
 * apart is the collision crush_code.md Phase 2 item 1 spent a bundle undoing, and
 * this class lives in `src/Tools/` — where `Tool` implementations live — rather
 * than beside the DTO so a future reader is not invited to re-introduce it.
 *
 * WHY IT IS ORDINARY, i.e. why there is no permission check in here: every tool
 * call the engine dispatches goes through {@see \SugarCraft\Crush\Runtime::gate()}
 * -> `HookManager::preToolUse()`, and
 * {@see \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook} rides that chain with
 * a `.*` matcher. So arriving as a plain `Tool` is what puts these calls on the
 * SAME GATING CHAIN `Bash` is on — measured end to end in
 * {@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest}. A second gate in
 * here would be a second policy to keep in step with the first.
 *
 * THE CHAIN IS SHARED; THE DECISION IS NOT ALWAYS THE SAME ONE, and an earlier
 * revision of this note said "the same gating `Bash` gets", which reads as the
 * decision and is false in one mode. Measured on a real
 * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} chain, all six
 * {@see \SugarCraft\Crush\Permissions\PermissionMode} values, one `mcp__*` name
 * against `Bash`:
 *
 *     mode                 an mcp__* name      Bash
 *     default              ask                 ask
 *     accept-edits         ask                 ask
 *     plan                 DENIED              ALLOWED   <- diverges
 *     auto                 allowed             allowed
 *     dont-ask             DENIED              DENIED
 *     bypass-permissions   allowed             allowed
 *
 * Five of six coincide. `plan` diverges because
 * {@see \SugarCraft\Crush\Permissions\PermissionGate::evaluatePlan()} allows
 * `Bash` for EXPLORATION — its own doc-block says so — while
 * {@see \SugarCraft\Crush\Permissions\PermissionGate::isWriteTool()} treats every
 * `mcp__` name as a write, and a server-side tool's effects are unknowable from
 * here. So the divergence runs in the CONSERVATIVE direction: the `mcp__*` name
 * is the more restricted of the two, never the less. It is not a hole, and it is
 * written down because the sentence it replaces was the load-bearing half of a
 * safety argument and `plan` is the mode the differential test drives.
 *
 * `dont-ask` DENIES EVERY MCP TOOL OUTRIGHT — the row above, not an inference.
 * Whether the servers were STARTED is a separate decision made in a separate
 * place: on a root the user has listed under `trustedProjectMcp` they start in
 * every mode including this one, and on a root they have not they start in none.
 * Permission mode is not, and never was, the control over launching; see
 * {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()}.
 *
 * DELIBERATELY NOT {@see ParallelSafe}: for the reason the `plan` row above
 * turns on — a server-side tool's effects are server-defined and unknowable here
 * — so it is a barrier, which is the same conservative default
 * {@see \SugarCraft\Crush\Permissions\PermissionGate::isWriteTool()} applies to
 * the `mcp__*` name.
 */
final class McpToolBridge implements Tool
{
    /**
     * The model-facing name prefix. `mcp__<server>__<tool>` is the convention
     * {@see \SugarCraft\Crush\Permissions\PermissionRule},
     * {@see \SugarCraft\Crush\Permissions\PermissionGate::isWriteTool()} and
     * {@see \SugarCraft\Crush\Runtime::runsConcurrently()} were all written
     * against while nothing produced such a name. This class is what makes those
     * three docblocks describe reachable code.
     */
    public const NAME_PREFIX = 'mcp__';

    public function __construct(
        private McpClient $client,
        private McpTool $descriptor,
    ) {}

    /**
     * The descriptor this bridge speaks for, so a caller inspecting
     * {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} can tell which server a
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
     * `__` IS THE SEPARATOR AND ALSO A LEGAL CHARACTER IN BOTH SEGMENTS, which
     * makes this name AMBIGUOUS rather than merely collision-prone, and the
     * ambiguity needs no substitution at all to fire:
     *
     *     server `a__b` + tool `c`   ->  mcp__a__b__c
     *     server `a`    + tool `b__c` ->  mcp__a__b__c
     *
     * Nothing here is rewritten in either case; two DIFFERENT tools on two
     * DIFFERENT servers simply have one name, and {@see \SugarCraft\Crush\Runtime}'s
     * `findTool()` returns the first match, so the model loses the ability to
     * ADDRESS the second one. That is the same cost {@see sanitize()} describes
     * for a substituted character, from a source that note did not mention.
     *
     * WHAT IT IS NOT is a misrouted call: {@see execute()} addresses
     * `$this->descriptor->serverName` and the unsanitized server-side tool name,
     * so a bridge that IS reached always reaches the tool it speaks for. The
     * failure is "unreachable", never "reached the wrong server".
     *
     * NOT FIXED HERE, deliberately. Escaping the underscore on the way in — so a
     * literal `_` in a key can never be read as part of the separator — is what
     * would make this injective, at the cost of rewriting every name the model and
     * every user-written permission rule have already learned. That is a change
     * with its own migration, and it is on the hardening backlog (E42) rather than
     * folded into the change-set that merely made these names reachable.
     */
    public function name(): string
    {
        return self::NAME_PREFIX
            . self::sanitize($this->descriptor->serverName)
            . '__'
            . self::sanitize($this->descriptor->name);
    }

    /**
     * Provider tool names are constrained (`^[a-zA-Z0-9_-]+$` for the OpenAI-shaped
     * APIs every provider here speaks), and an `.mcp.json` server key is free text
     * — `my server`, `github.com/foo` are both plausible. Anything outside the
     * allowed set becomes `_` so the whole request is not rejected over one name.
     *
     * THE HYPHEN IS IN THE KEPT SET DELIBERATELY, and it is load-bearing rather
     * than tidy: hyphens are ubiquitous in real server keys
     * (`sequential-thinking`, `brave-search`, `aws-kb-retrieval`), the providers
     * accept them, and dropping them from this class would rewrite every such
     * name to an underscore — silently, since nothing about the resulting name
     * looks wrong.
     *
     * TWO COLLISION SOURCES, AND THIS IS ONE OF THEM. Two servers whose keys
     * differ only in a substituted character produce the same wire name, and
     * {@see \SugarCraft\Crush\Runtime}'s `findTool()` returns the FIRST match, so
     * the model loses the ability to ADDRESS the second one. An earlier version
     * of this note attributed collisions SOLELY to substitution, which made the
     * other source — `__` being the segment separator and a legal character in
     * both segments, so `a__b`/`c` and `a`/`b__c` collide with no substitution
     * whatever — the one source the docblock did not mention. It is stated at
     * {@see name()}, where the separator is composed, along with why neither
     * source can misroute a call.
     *
     * A USER-WRITTEN PERMISSION RULE MUST USE THE SANITISED NAME, and that is the
     * part of this substitution a reader is most likely to get wrong. A
     * `.mcp.json` key of `github.com/foo` is `mcp__github_com_foo__*` to
     * {@see \SugarCraft\Crush\Permissions\PermissionRule}; an `allow` written
     * against the key as spelled — `mcp__github.com/foo__*` — matches nothing,
     * and the tool sits behind an Ask forever with the rule looking correct. The
     * name IS discoverable: nothing lists the wire names up front, but the
     * permission prompt names the tool it is asking about, so the sanitised
     * spelling is in front of the user at the moment they decide to write a rule
     * for it. A `/mcp` listing that surfaced them without being asked is on the
     * hardening backlog (E42), not here.
     */
    private static function sanitize(string $segment): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $segment);
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
     * {@see \SugarCraft\Crush\Tests\Providers\ToolSchemaEncodingTest}). A
     * NESTED no-argument object (`properties: {opts: {type: object, properties:
     * []}}`, a routine MCP shape) carries the same defect one level down, and
     * fixing that is NOT done here.
     *
     * WHERE IT IS DONE, and why not in both places:
     * {@see \SugarCraft\Crush\Providers\Concerns\ToolSchema::normalizeToolSchema()}
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
     * {@see \SugarCraft\Crush\MCP\McpTool::inputSchema} is filled from
     * `json_decode($line, true)` in {@see \SugarCraft\Crush\MCP\StdioMcpServer}
     * and from the equally associative Guzzle decode in
     * {@see \SugarCraft\Crush\MCP\HttpMcpServer}, so every nested value is an
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
     * {@see \SugarCraft\Crush\Tests\Tools\McpToolBridgeTest}:
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
     * (see {@see \SugarCraft\Crush\Providers\Concerns\ToolSchema} for how total
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
        $id = is_string($args['id'] ?? null) ? $args['id'] : '';
        $start = hrtime(true);

        try {
            $raw = $this->client->callTool(
                $this->descriptor->serverName,
                $this->descriptor->name,
                $args,
            );
        } catch (\Throwable $e) {
            return new ToolResult(
                toolCallId: $id,
                content: sprintf('Error: MCP tool %s failed: %s', $this->name(), $e->getMessage()),
                isError: true,
                durationMs: self::elapsedMs($start),
            );
        }

        return new ToolResult(
            toolCallId: $id,
            content: self::announceUnreadableErrorFlag($raw) . self::renderContent($raw),
            isError: self::isError($raw),
            durationMs: self::elapsedMs($start),
        );
    }

    private static function elapsedMs(float|int $start): int
    {
        return (int) ((hrtime(true) - $start) / 1_000_000);
    }

    /**
     * Did the server report a failure?
     *
     * TWO shapes, because two producers exist. `isError` is the MCP spec's own
     * flag on a `tools/call` result; `['error' => …]` is what
     * {@see \SugarCraft\Crush\MCP\StdioMcpServer::callTool()} substitutes when no
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
     * parts are joined. A non-text part (an image, an embedded resource) is
     * announced by TYPE rather than dropped: a tool whose whole answer was one
     * image would otherwise return the empty string, which reads as success with
     * no output. Anything that is not the spec shape at all is JSON-encoded, on
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

            $parts[] = sprintf('[%s]', is_string($part['type'] ?? null) ? $part['type'] : 'unknown');
        }

        $rendered = implode("\n", $parts);

        return $rendered === '' ? '[empty content]' : $rendered;
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
