<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The single choke point for the process-wide `error_log()` side-channel.
 *
 * WHY THIS EXISTS. Before this class the side-channel had no home: twenty-five
 * raw call sites across twelve files each re-spelled its own prefix, so
 * "which lines can land on fd 2 and where do they come from" was a grep
 * question, and the answer disagreed with the census for the dumbest possible
 * reason — the census counts calls, the grep counted doc-block prose. The
 * channels themselves are old and deliberate (audit C2a's routing decisions,
 * E154's prefix lineage, E154c's gated detach copy); what was missing was a
 * NAMED place. Every line the application logs to PHP's error log now starts
 * its life here, with a channel constant, and the census roster
 * (`StderrEmitterCensusTest::ERROR_LOG_SITES`) pins the tree to exactly this
 * file's one call — a new raw call anywhere else in `src/` reds.
 *
 * THE WIRE BYTES ARE THE CONTRACT. `channel()` prefixes `"<name>: "`,
 * `sub()` prefixes the app envelope and then the sub-channel, `raw()` adds
 * nothing. Each mirrors a prefix shape that existed at a call site before the
 * fold, byte for byte: the envelope is `candy-code: `, the nested label is
 * `candy-code: AgentWorkerPool: `, the pre-composed line is what
 * `RuntimeNoticeSink::warn()` and the translated MCP launch format already
 * carry themselves. Parity pins live in
 * {@see \SugarCraft\Code\Tests\Support\LogTest::testEachChannelWritesTheBytesTheFoldedSitesWrote()}.
 *
 * IT IS STILL `error_log()` UNDERNEATH, ON PURPOSE. The sink below calls
 * PHP's `error_log()` directly, so every mechanism built on the ini target
 * keeps working untouched: {@see \SugarCraft\Code\Diagnostics\TuiErrorLog}
 * redirects the whole channel into a private file under the alt screen,
 * `DiscardsErrorLogTrait` and the sink's stderr tests capture through
 * `ini_set('error_log', …)`, and PHP's own logged diagnostics ride the same
 * destination. A façade that wrote fd 2 itself would have torn the frame the
 * renderer owns — the exact failure C2a exists to prevent.
 *
 * GATES. The per-family OPT-IN flags (`CANDY_CODE_DEBUG_STREAM`,
 * `CANDY_CODE_DEBUG_RULES`, `CANDY_CODE_DEBUG_SKILLS`,
 * `CANDY_CODE_DEBUG_COMMANDS`) stay where they are: at the caller, inside the
 * family that decided the gate. This class does not take over those product
 * decisions — it emits what it is handed. The one thing it adds is the
 * process-wide OPT-OUT: {@see QUIET_LOGS_ENV}, read narrowly through
 * {@see EnvBool::isTrue()} like the newer keys (`1`/`true`/`yes`, never by
 * typo), for CI captures and stderr-piping launches where the diagnostics are
 * noise rather than signal. Unset — every test, every ordinary run — behaves
 * exactly as the raw calls did: the writes flow.
 *
 * NOT THE TRANSCRIPT. `Log` silences only the error-log copy. The mid-session
 * transcript seam ({@see \SugarCraft\Code\Diagnostics\RuntimeNoticeSink})
 * records independently of this gate, and a suppressed stderr line never
 * suppresses its transcript row; the two channels were separated in round 47
 * precisely so they could be decided separately.
 */
final class Log
{
    /**
     * Set to `1`, `true` or `yes` (case-insensitive) to suppress every write
     * through this façade for the life of the process. Unset, `0`, empty or
     * any other word keeps today's behavior: the writes flow. Rostered by
     * `EnvRosterDriftTest` and tabulated in `docs/ENVIRONMENT.md`.
     */
    public const QUIET_LOGS_ENV = 'CANDY_CODE_QUIET_LOGS';

    /** The app-wide envelope every prefixed diagnostic carries. */
    public const APP = 'candy-code';

    /** The stream-observer self-label, kept by E154c's routing decision. */
    public const CHAT = 'Chat';

    /** The fork-pool diagnostics' sub-channel label. */
    public const AGENT_WORKER_POOL = 'AgentWorkerPool';

    /** The DSML tool-call envelope heuristics' sub-channel label. */
    public const DSML_TOOL_CALL_PARSER = 'DsmlToolCallParser';

    /** The MiniMax XML fallback parser's sub-channel label. */
    public const MINIMAX_XML_FALLBACK_TOOL_CALL_PARSER = 'MinimaxXmlFallbackToolCallParser';

    /** The SGLang provider's heuristic sub-channel label. */
    public const SGLANG_PROVIDER = 'SglangProvider';

    /** The foreign-preset loader's sub-channel label. */
    public const FOREIGN_AGENT_PRESET_REGISTRY = 'ForeignAgentPresetRegistry';

    /** The foreign memory importer's sub-channel label. */
    public const FOREIGN_MEMORY_IMPORTER = 'ForeignMemoryImporter';

    /**
     * Log on a named channel: writes `"<channel>: <message>"`. Pass
     * {@see APP} for the envelope-only shape; pass a family name like
     * {@see CHAT} for a self-labelled one.
     */
    public static function channel(string $channel, string $message): void
    {
        self::sink($channel . ': ' . $message);
    }

    /**
     * Log a sub-channel of the app: writes
     * `"<self::APP>: <subchannel>: <message>"` — the two-stage shape the
     * E154 lineage spelled inline at its call sites.
     */
    public static function sub(string $subchannel, string $message): void
    {
        self::sink(self::APP . ': ' . $subchannel . ': ' . $message);
    }

    /**
     * Log a fully composed line as-is, with no prefix added: for messages
     * whose text is already complete before it reaches here — a translated
     * launch format, or the transcript seam's copy, which carries its own
     * envelope by construction.
     */
    public static function raw(string $message): void
    {
        self::sink($message);
    }

    /**
     * THE SINK: the only `error_log(` call in `src/`, pinned structurally by
     * LogTest and tree-wide by the census roster.
     *
     * The quiet gate is read per write, not memoized at first sight: it costs
     * one `getenv()` on paths that are already exception handling (a fork
     * failure, a parser refusal), and a memo would make `putenv()` from a
     * test or a mid-process toggle silently inert, which is how the
     * interactive-availability memo bit lane cd.
     */
    private static function sink(string $line): void
    {
        if (EnvBool::isTrue(\getenv(self::QUIET_LOGS_ENV))) {
            return;
        }

        \error_log($line);
    }
}
