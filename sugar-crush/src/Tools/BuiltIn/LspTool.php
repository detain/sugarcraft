<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\LSP\LspClient;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Tools\PathJail;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The model-facing door onto `src/LSP/` — go-to-definition, find-references,
 * hover, document symbols, code actions and published diagnostics, answered by
 * a language server instead of by a regex (crush_code.md Phase 2 item 7).
 *
 * WHAT THIS WIRES, AND WHAT IT DELIBERATELY DOES NOT. Before this class,
 * `grep -rn 'LspClient\|LSP\\\\' src bin examples` outside `src/LSP/` matched
 * NOTHING: {@see LspClient}, {@see \SugarCraft\Crush\LSP\LspConnection} and
 * {@see \SugarCraft\Crush\LSP\LspCache} were finished, tested (three files under
 * `tests/LSP/`) and unreachable from any run — the `Write` shape this repo has
 * now hit several times. This is the reachability half. The half that is
 * explicitly NOT here is a server-LAUNCHING subsystem: nothing in `src/` reads a
 * configured server command, so nothing in `src/` starts one. See
 * {@see \SugarCraft\Crush\Cli\Bootstrap::lspTool()} for that seam and for what
 * has to land before an LSP-backed answer is possible at all.
 *
 * SO THE SHIPPED DEFAULT ANSWERS NOTHING, AND SAYS SO. With no client injected
 * — which is every launch today — every call returns an ERROR result naming the
 * language that has no server. That distinction is the whole design decision
 * here, not a stylistic one: an empty SUCCESS result reads to a model as "this
 * symbol has no references", which is a confident lie about the codebase and the
 * kind of claim the model will then act on. An error result reads as "I could not
 * look", which is true. The two are separately pinned in
 * {@see \SugarCraft\Crush\Tests\Tools\BuiltIn\LspToolTest} — a genuinely empty
 * answer FROM a configured server is a success, and only the unconfigured case is
 * an error.
 *
 * NOT {@see \SugarCraft\Crush\Tools\ParallelSafe}, and the reason is per-tool
 * rather than inherited: {@see LspClient} MUTATES on every query (it writes each
 * answer into its {@see \SugarCraft\Crush\LSP\LspCacheInterface}, and collects
 * `publishDiagnostics` into its own map), and a real connection is one stdio pipe
 * pair to one server process. `Runtime::executeToolCalls()` fans a parallel-safe
 * batch out over one FORKED CHILD PER CALL, so two concurrent `Lsp` calls would
 * (a) strand both cache writes in children the parent never sees and (b) have two
 * processes interleaving JSON-RPC frames on the same fd. Either is a wrong answer
 * rather than a slow one. It could become parallel-safe by also implementing
 * {@see \SugarCraft\Crush\Tools\CarriesSessionState} AND by giving each child its
 * own connection; neither is in scope here.
 */
final readonly class LspTool implements Tool
{
    use TruncatesOutput;

    /**
     * The `operation` argument's domain, each mapped to the LSP request it
     * becomes. Keys are what the model sends; values are what the wire calls it,
     * and they are in the message so an operation the model got wrong is
     * reported in a vocabulary it can look up.
     *
     * `diagnostics` is the one entry that is NOT a request. It reads the map
     * {@see LspClient::handlePublishDiagnostics()} fills from the server's own
     * `textDocument/publishDiagnostics` NOTIFICATIONS, so its answer is only as
     * current as the last notification something pumped in — and nothing pumps
     * one today, which is why it is listed as server-push in
     * {@see description()} rather than as a query.
     */
    private const OPERATIONS = [
        'definition' => 'textDocument/definition',
        'references' => 'textDocument/references',
        'hover' => 'textDocument/hover',
        'symbols' => 'textDocument/documentSymbol',
        'codeActions' => 'textDocument/codeAction',
        'diagnostics' => 'textDocument/publishDiagnostics',
    ];

    /**
     * The language a request with no `language` argument is asked of.
     *
     * `php` because {@see LspClient::__construct()} registers its injected
     * connection under exactly that key — so a client built the ordinary way has
     * a `php` server and nothing else, and a default of anything else would make
     * the common case refuse itself.
     */
    private const DEFAULT_LANGUAGE = 'php';

    /**
     * $client null is the SHIPPED state, not a test affordance: see the class
     * doc-block. It is nullable rather than absent so that the whole tool is
     * constructible with `new LspTool()` — which is what
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolCorpus::instances()} does
     * for every built-in that needs no wiring, and what keeps this tool inside
     * every corpus-driven conformance test without a bespoke entry.
     *
     * $root is the containment boundary for the model-supplied `path`, exactly as
     * in {@see Read}/{@see Grep}: null means "unrooted", which only a test or an
     * embedder is, and an unrooted instance says so in {@see inputSchema()}
     * rather than claiming a boundary it does not enforce.
     *
     * $maxOutputBytes bounds the encoded answer. A `references` query on a
     * common identifier is unbounded in the same way a `grep` hit list is, and
     * this answer is replayed into every following request of the turn.
     */
    public function __construct(
        private ?LspClient $client = null,
        private ?string $root = null,
        private int $maxOutputBytes = self::DEFAULT_MAX_OUTPUT_BYTES,
    ) {}

    public function name(): string
    {
        return 'Lsp';
    }

    /**
     * Three facts a caller otherwise pays a wasted turn to learn, and each is
     * pinned against the code that owns it rather than against this string:
     *
     *  - `line`/`column` are ZERO-INDEXED. That is the LSP spec's convention and
     *    it disagrees with every editor UI and with `Grep`'s output, which is
     *    `grep -n`'s 1-based numbering. A model that reads a line number out of a
     *    `Grep` result and passes it here is off by one, silently, and gets a
     *    plausible answer about the wrong line.
     *  - the operation set, spelled as {@see OPERATIONS}' keys rather than
     *    prose, so an added operation cannot be missing from the description.
     *  - that an unconfigured language is an ERROR and not an empty list. Stated
     *    here because it is the state every launch is in today, and a model that
     *    reads "no references" as fact will act on it.
     */
    public function description(): string
    {
        return 'Ask a language server about code: '
            . implode(', ', array_keys(self::OPERATIONS))
            . '. line and column are ZERO-INDEXED, per the LSP spec — Grep reports 1-based line '
            . 'numbers, so subtract one when passing a Grep hit here. diagnostics is not a query: '
            . 'it returns whatever the server last pushed for this file. If no language server is '
            . 'configured for the language, this reports an error rather than an empty result — an '
            . 'empty answer from this tool means the server was asked and found nothing, never that '
            . 'nothing could be asked.';
    }

    public function inputSchema(): array
    {
        // Only a rooted instance is contained, so only a rooted instance says so
        // — same rule as Grep::inputSchema().
        $pathScope = $this->root !== null ? ' Must be inside the workspace root.' : '';

        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'enum' => array_keys(self::OPERATIONS),
                    'description' => 'Which language-server question to ask.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'File to ask about; it must already exist.' . $pathScope,
                ],
                'line' => [
                    'type' => 'integer',
                    'description' => 'Cursor line, ZERO-INDEXED (LSP convention; Grep prints 1-based). '
                        . 'Omit it for 0; a value that is not an integer is an error, not a default.',
                ],
                'column' => [
                    'type' => 'integer',
                    'description' => 'Cursor column, ZERO-INDEXED. Omit it for 0; a value that is not an '
                        . 'integer is an error, not a default.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Which registered language server to ask. Defaults to '
                        . self::DEFAULT_LANGUAGE . '.',
                ],
            ],
            'required' => ['operation', 'path'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $id = \is_string($args['id'] ?? null) ? $args['id'] : '';

        $operation = \is_string($args['operation'] ?? null) ? $args['operation'] : '';
        if (!isset(self::OPERATIONS[$operation])) {
            return self::error($id, sprintf(
                'Error: operation must be one of %s%s',
                implode(', ', array_keys(self::OPERATIONS)),
                $operation === '' ? '' : sprintf(' (got "%s")', $operation),
            ));
        }

        $path = \is_string($args['path'] ?? null) ? $args['path'] : '';
        if ($path === '') {
            return self::error($id, 'Error: path cannot be empty');
        }

        // A MESSAGE-QUALITY GUARD, AND ONLY THAT — the earlier note here claimed
        // it stopped a `realpath()` ValueError, and a mutation disproved that.
        // MEASURED on PHP 8.3.6: `realpath()` really does throw on a NUL byte,
        // but neither path below reaches it with one. Rooted, `PathJail::resolve()`
        // screens NUL in its own `unusable()` before calling `realpath()`, so
        // deleting this guard yields `path outside workspace root` — a clean
        // refusal, and a MISLEADING one, since the path is not outside the root.
        // Unrooted, `is_file("/tmp/a\0b")` returns false rather than throwing, so
        // deleting the guard yields `file not found` — also clean, also wrong
        // about the cause. Hence: kept for the message, not for the crash.
        if (str_contains($path, "\0")) {
            return self::error($id, 'Error: path contains a NUL byte');
        }

        // COORDINATES ARE VALIDATED HERE, with the other argument-SHAPE checks
        // and before anything is consulted, because a malformed coordinate is the
        // same class of fault as a misspelled `operation`: nothing about the
        // configuration or the filesystem can make it valid.
        //
        // AND THEY ARE REFUSED RATHER THAN COERCED TO 0, which is what this did
        // first. `is_int($args['line']) ? … : 0` turned `"1"` — a string, which is
        // what a model emits often enough to be the normal case rather than an
        // edge one — into line 0 and then answered about line 0 as if it had been
        // asked. That is the tool's own headline failure mode wearing a different
        // hat: a confident answer to a question nobody asked. An INTEGRAL numeric
        // is accepted, since `"1"` and `1.0` are unambiguous; anything else
        // (`"1.5"`, `"abc"`, `true`, an array) is an error naming the axis.
        $coordinates = [];
        foreach (['line', 'column'] as $axis) {
            $raw = $args[$axis] ?? null;
            if ($raw === null) {
                $coordinates[$axis] = 0;
                continue;
            }

            $parsed = self::coordinate($raw);
            if ($parsed === null) {
                return self::error($id, sprintf(
                    'Error: %s must be a zero-indexed integer (got %s)',
                    $axis,
                    \is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
                ));
            }

            $coordinates[$axis] = $parsed;
        }

        $language = \is_string($args['language'] ?? null) && $args['language'] !== ''
            ? $args['language']
            : self::DEFAULT_LANGUAGE;

        // BEFORE the path is resolved, deliberately. Whether a server exists is a
        // fact about the configuration and not about the path, so a launch with
        // no LSP configured — every launch today — gets the one message that is
        // actionable instead of a "file not found" for a file that would never
        // have been queried.
        if ($this->client === null || !\in_array($language, $this->client->servers(), true)) {
            return self::error($id, sprintf(
                'Error: no language server configured for %s. Nothing was queried, so this is not '
                . 'the same as finding no %s — do not read it as one. Fall back to Grep/Glob for '
                . 'this question.',
                $language,
                $operation,
            ));
        }

        if ($this->root !== null) {
            $resolved = PathJail::resolve($this->root, $path);
            if ($resolved === null) {
                return self::error($id, 'Error: path outside workspace root');
            }
            $path = $resolved;
        }

        // An absent file is refused rather than forwarded. `PathJail::resolve()`
        // accepts a path whose parent exists, and every LSP query for a URI the
        // server has never opened comes back empty — which is the confident-lie
        // shape this whole tool is arranged to avoid.
        if (!is_file($path)) {
            return self::error($id, sprintf('Error: file not found: %s', $path));
        }

        $line = $coordinates['line'];
        $column = $coordinates['column'];
        $uri = self::fileUri($path);

        // The *For() variants rather than use($language) + the bare query: use()
        // returns a CLONE whose caches are the same objects, so it would work,
        // but naming the language per call keeps this tool from holding any
        // language state of its own between calls.
        $answer = match ($operation) {
            'definition' => $this->client->definitionsFor($language, $uri, $line, $column),
            'references' => $this->client->referencesFor($language, $uri, $line, $column),
            'hover' => $this->client->hoverFor($language, $uri, $line, $column),
            'symbols' => $this->client->symbolsFor($language, $uri),
            'codeActions' => $this->client->codeActionsFor($language, $uri, $line, $column),
            // No per-language variant exists: diagnostics are keyed by URI
            // alone. The language was still required to be configured above, so
            // that an empty map cannot be read as "this file is clean" on a
            // launch that has no server at all.
            'diagnostics' => $this->client->diagnostics($uri),
        };

        // DO NOT SAY "from the language server" WITHOUT CHECKING. A registered
        // but DISCONNECTED server is not a refusal — {@see LspClient} answers
        // `definition`/`references`/`symbols` from a same-file grep fallback
        // instead, and returns empty for `hover`/`codeActions`. Labelling that a
        // semantic result is a claim about provenance that is simply false, and
        // it is the difference between "these are the callers" and "these lines
        // contain that word". Measured before this note existed: a disconnected
        // spy produced a one-hit answer headed "from the php language server".
        //
        // THE BOUND ON THIS LABEL, stated because it is not exact: it reports the
        // connection state at the time of THIS call, which is what decides the
        // path for an UNCACHED query. A cache hit is served from whatever state
        // was live when it was stored, so the note says "an uncached query"
        // rather than asserting where these particular bytes came from.
        $note = $this->client->isConnected($language)
            ? ''
            : sprintf(
                "\nNOTE: the %s language server is registered but NOT connected, so an uncached query "
                . 'answers from a same-file text search rather than the server — these are text matches, '
                . 'not semantic results, and hover/codeActions have no fallback at all.',
                $language,
            );

        if ($answer === null || $answer === []) {
            // AN EMPTY `diagnostics` MAP IS THE ONE EMPTY ANSWER THAT IS NOT YET
            // AN ANSWER, so it gets its own caveat rather than reading as "this
            // file is clean". Every other operation above made a request; this
            // one read a local map, and MEASURED on this tree nothing in `src/`
            // fills it — `handlePublishDiagnostics()` has no `src/` call site and
            // no `onNotification()` subscriber is registered anywhere outside
            // `src/LSP/`. The missing-server guard hides that today, because with
            // no server this line is unreachable; the moment the launcher in
            // {@see \SugarCraft\Crush\Cli\Bootstrap::lspTool()} lands, an
            // unnoted empty map would be a confident "no problems in this file"
            // for every file in the repo. This stays a SUCCESS rather than
            // becoming an error because an embedder that DOES pump notifications
            // gets true empties here, and the note is the honest way to serve
            // both. Wiring the subscription is what removes the note.
            $unpumped = $operation === 'diagnostics'
                ? "\nNOTE: nothing in this build subscribes to the server's publishDiagnostics "
                  . 'notifications, so an empty map means none was ever delivered — NOT that this '
                  . 'file has no problems.'
                : '';

            return new ToolResult(
                toolCallId: $id,
                content: sprintf(
                    'No %s found for %s (language: %s).%s%s',
                    $operation,
                    $path,
                    $language,
                    $note,
                    $unpumped,
                ),
            );
        }

        return new ToolResult(
            toolCallId: $id,
            content: $this->truncateOutput(
                sprintf(
                    "%s (%s) for %s (language: %s):%s\n%s",
                    $operation,
                    self::OPERATIONS[$operation],
                    $path,
                    $language,
                    $note,
                    (string) json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ),
                $this->maxOutputBytes,
            ),
        );
    }

    /**
     * A model-supplied coordinate as a zero-indexed int, or null for "this is
     * not a coordinate at all" — which the caller turns into a refusal.
     *
     * WHAT IS ACCEPTED AND WHY EACH: an int, obviously. A string of digits,
     * because a model emitting JSON emits `"line": "12"` routinely and the
     * intent is not in doubt. A float with no fractional part, because
     * `json_decode` of `12.0` is a float and the intent is not in doubt either.
     * Nothing else: `"12.5"` and `"twelve"` are refused rather than truncated,
     * because a truncation here is an answer about a line the caller did not
     * name and this tool has no way to say so afterwards.
     *
     * A NEGATIVE value is CLAMPED to 0 rather than refused — the one coercion
     * left. It is the only value where the intent is unambiguous AND the wrong
     * behaviour is worse: an LSP server rejects a negative position outright, so
     * clamping turns a protocol error into the nearest position that exists.
     */
    private static function coordinate(mixed $raw): ?int
    {
        if (\is_int($raw)) {
            return max(0, $raw);
        }

        if (\is_float($raw) && is_finite($raw) && $raw === floor($raw)) {
            return max(0, (int) $raw);
        }

        if (\is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            return max(0, (int) trim($raw));
        }

        return null;
    }

    /**
     * The `file://` URI for an absolute local path, percent-encoded per segment.
     *
     * `'file://' . $path` was wrong, and wrong in the shape this whole tool
     * exists to prevent. {@see LspClient} turns a URI back into a path to run its
     * grep fallback, and any correct decoder must decode percent-escapes — so an
     * UNencoded path containing a character the decoder treats specially comes
     * back as a DIFFERENT path, the file is not found, and the fallback returns
     * `[]`, which this tool reports as the success "No references found". A
     * successful empty answer for a file nobody opened. MEASURED before this
     * method existed, `sub/Web+Fetch.php` with two occurrences of the identifier:
     * "No references found", isError=false, while `sub/Target.php` set up
     * identically returned both hits. (`+` is the character that bit, because the
     * decoder was `urldecode()`; that end is now `rawurldecode()`, and encoding
     * here closes the same hole for `%`, `#` and `?` against any decoder.)
     *
     * PER SEGMENT, so `/` stays a separator: `rawurlencode()` on the whole path
     * would escape every slash and produce a URI with no path structure at all.
     * The leading `/` survives because `explode('/', '/a/b')` yields an empty
     * first element and `rawurlencode('')` is `''`.
     */
    private static function fileUri(string $path): string
    {
        return 'file://' . implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function error(string $id, string $message): ToolResult
    {
        return new ToolResult(toolCallId: $id, content: $message, isError: true);
    }
}
