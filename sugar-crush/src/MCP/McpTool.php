<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

final readonly class McpTool
{
    /**
     * The keys {@see fromArray()} reads out of a tool definition, each with the
     * check that says whether the value would satisfy the constructor parameter
     * it lands in.
     *
     * ⚠️ IT LIVES IN THIS FILE BECAUSE IT IS A MIRROR OF THIS FILE, AND THAT WAS
     * THE HAZARD.
     * WHAT THIS SAID, while the table lived in {@see StdioMcpServer}: "THIS IS A
     * HAND MIRROR OF ANOTHER CLASS, AND THAT IS THE HAZARD. A fourth
     * `$data[...]` in `fromArray()` reopens exactly the `TypeError` this filter
     * exists to close, and nothing about adding one would red a test that merely
     * exercised the three keys below."
     * WHAT IS TRUE NOW: the table and the subscripts it mirrors are eight lines
     * apart in one file, so a fourth `$data[...]` added without a fourth row is
     * visible on sight rather than one class away. The move happened because a
     * SECOND server type needed the same filter, and copying it would have made
     * the hazard two hazards.
     * WHY THIS STILL EARNS ITS PLACE: proximity is not a guard. It is still a
     * CONST rather than a literal inside {@see toolDefinitionIsWellTyped()} so
     * that `StdioMcpServerToolListRobustnessTest::testTheTypeFilterStillMirrorsEveryKeyMcpToolReads()`
     * can read it and compare it against `fromArray()`'s actual subscripts and
     * this class's actual parameter types.
     *
     * `serverName` is absent deliberately: `fromArray()` takes it from its own
     * second parameter, not from the definition, so it is not a key a peer's
     * reply can put a wrong type into.
     *
     * @var array<string, callable-string>
     */
    public const TOOL_DEFINITION_TYPES = [
        'name' => 'is_string',
        'description' => 'is_string',
        'inputSchema' => 'is_array',
    ];

    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public string $serverName,
    ) {}

    public static function fromArray(array $data, string $serverName): self
    {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            inputSchema: $data['inputSchema'] ?? [],
            serverName: $serverName,
        );
    }

    /**
     * {@see fromArray()} for a definition that came off a wire, or `null` when a
     * third party got it wrong — NEVER a `TypeError`.
     *
     * ⚠️ THE GAP THIS CLOSES IS A MEASURED ONE-HOP KILL OF THE WHOLE MCP
     * SUBSYSTEM. {@see fromArray()} reads `$data['name'] ?? ''` into a `string`
     * parameter, so a well-formed JSON-RPC reply of `{"tools":[{"name":5}]}`
     * raises a `TypeError` — and a `TypeError` is not a `RuntimeException`,
     * which is the only class {@see McpClient::startServer()} used to catch
     * under a comment promising that "a single unreachable/misbehaving server
     * must not abort loading the rest".
     *
     * MEASURED end to end on this host (PHP 8.3.6, Linux 6.8), three consecutive
     * takes, driving `McpClient::startServers()` over a two-server config the way
     * {@see \SugarCraft\Crush\Cli\Bootstrap::mcpClient()} does — one server
     * answering `{"tools":[{"name":5}]}` and one answering correctly:
     *
     *     startServers() THREW TypeError ... Argument #1 ($name) must be of type
     *     string, int given
     *     -> the well-formed server was never started, 0 tools visible
     *
     * The same shape falls out of a `name` that is an object or a bool, and of an
     * `inputSchema` that is a string; `name: null` alone is safe, because `??`
     * catches it.
     *
     * ⚠️ CHECKED HERE RATHER THAN MADE LENIENT BELOW, ON PURPOSE. This class's
     * promoted properties are the contract every consumer of a tool list reads,
     * and widening them to `mixed` to survive a bad server would push the same
     * `TypeError` out to whichever of those consumers touched it first. That is
     * asserted in
     * `StdioMcpServerToolListRobustnessTest::testMcpToolIsStillStrictSoTheFilterIsWhatIsBeingTested()`.
     *
     * SKIPPING RATHER THAN THROWING is what every caller wants: one malformed
     * tool in a list of forty is a defect in that tool, and taking the other
     * thirty-nine down with it is the behaviour this whole path exists to avoid.
     *
     * @param array<mixed> $data
     */
    public static function tryFromArray(array $data, string $serverName): ?self
    {
        return self::toolDefinitionIsWellTyped($data)
            ? self::fromArray($data, $serverName)
            : null;
    }

    /**
     * Does `$def` carry the types this class's constructor declares?
     *
     * ⚠️ `isset()` AND NOT `array_key_exists()`, AND THE DIFFERENCE IS A TOOL.
     * {@see fromArray()} reads every field with `??`, which supplies the typed
     * default for an ABSENT key and for an explicit `null` alike — so
     * `{"name":"write","description":null}` is perfectly well-typed as far as the
     * constructor is concerned. `array_key_exists()` would call that key present,
     * find `null` failing `is_string()`, and drop a legitimate tool on the floor
     * without saying so. `isset()` is false for both shapes, which is exactly the
     * question this method is asking. Pinned by the `write` entry in
     * {@see \SugarCraft\Crush\Tests\MCP\StdioMcpServerToolListRobustnessTest::testAMalformedEntryIsSkippedAndItsWellFormedNeighboursAreNot()},
     * which is there because the `array_key_exists()` mutation SURVIVED a fixture
     * whose alphabet had no explicit null in it.
     *
     * @param array<mixed> $def
     */
    private static function toolDefinitionIsWellTyped(array $def): bool
    {
        foreach (self::TOOL_DEFINITION_TYPES as $key => $check) {
            if (isset($def[$key]) && !$check($def[$key])) {
                return false;
            }
        }

        return true;
    }
}
