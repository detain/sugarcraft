<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SettingsSchema;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\WebFetch;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;

/**
 * The tool bounds a setting can move (roadmap N-P4c, design Appendix N §2.2
 * "Tools"), resolved once from the merged config.
 *
 * WHY A TURN-TIME REBIND. The tools are built once, at launch, by
 * `Bootstrap::tools()`; the settings view saves at any time and a turn runs in
 * a child forked per turn. So `EngineBackend::turnTools()` reads the merged
 * config at the top of each turn and hands every tool through
 * {@see applyTo()}, which rebinds the turn's copy with the bounds that are set.
 * A save — the session tier included, which the child inherits — therefore
 * applies from the next turn with no apply arm, and the tools the launch built
 * are never touched.
 *
 * UNSET IS "KEEP WHAT THE TOOL WAS BUILT WITH", never "the default": a key
 * that no tier sets leaves the tool exactly as constructed, so an embedder's
 * own caps survive and nothing is rebuilt for nothing. A value is honoured only
 * when it passes its {@see SettingsSchema} definition's validation — the range
 * a tool can be held to is stated once, in the schema, and an out-of-range or
 * mistyped value falls back to the tool's own bound rather than being clamped.
 * A JSON number with no fractional part (`4096.0`) counts as the whole number
 * it spells; a quoted `"4096"` does not, for the reason
 * {@see \SugarCraft\Crush\Config\Settings\Validator\RangeValidator} gives.
 *
 * Bounds read where a turn-time rebind cannot reach — the search tool's,
 * built per use by `/websearch` too, and the two process-wait bounds read
 * at the moment they are armed — are read through {@see current()} at their
 * use site instead, from the same definitions.
 */
final readonly class ToolLimits
{
    /** Result cap of Bash, Grep, Glob, Lsp and WebFetch — `TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES`. */
    public const OUTPUT_CAP_KEY = 'toolOutputCapBytes';

    /** Result cap of one MCP tool's answer. */
    public const MCP_RESULT_CAP_KEY = 'mcpResultCapBytes';

    /** Read's per-file read bound — {@see Read::DEFAULT_MAX_BYTES}. */
    public const READ_MAX_BYTES_KEY = 'readMaxBytes';

    /** Lines a Read returns when the call names no `limit` — {@see Read::PAGE_LINES}. */
    public const READ_PAGE_LINES_KEY = 'readPageLines';

    /** Bytes one Read page may hold — {@see Read::PAGE_BYTES}. */
    public const READ_PAGE_BYTES_KEY = 'readPageBytes';

    /** Paths one Glob collects — {@see Glob::DEFAULT_MAX_MATCHES}. */
    public const GLOB_MAX_MATCHES_KEY = 'globMaxMatches';

    /** WebFetch's memory bound on a body — {@see WebFetch::MAX_WIRE_BYTES}. */
    public const WEB_FETCH_MAX_BYTES_KEY = 'webFetchMaxBytes';

    /** WebFetch's per-read socket timeout — {@see WebFetch::READ_TIMEOUT_SECONDS}. */
    public const WEB_FETCH_TIMEOUT_KEY = 'webFetchTimeoutSeconds';

    /** Results one WebSearch digest lists. */
    public const WEB_SEARCH_MAX_RESULTS_KEY = 'webSearchMaxResults';

    /** WebSearch's request timeout. */
    public const WEB_SEARCH_TIMEOUT_KEY = 'webSearchTimeoutSeconds';

    /** Silence an `interactive: true` Bash run may keep before it is stopped. */
    public const INTERACTIVE_IDLE_KEY = 'bashInteractiveIdleSeconds';

    /** Wall-clock budget of the chat-native path's forked tool children. */
    public const PARALLEL_TIMEOUT_KEY = 'chatToolTimeoutSeconds';

    /** Every key this class resolves. */
    public const KEYS = [
        self::OUTPUT_CAP_KEY,
        self::MCP_RESULT_CAP_KEY,
        self::READ_MAX_BYTES_KEY,
        self::READ_PAGE_LINES_KEY,
        self::READ_PAGE_BYTES_KEY,
        self::GLOB_MAX_MATCHES_KEY,
        self::WEB_FETCH_MAX_BYTES_KEY,
        self::WEB_FETCH_TIMEOUT_KEY,
        self::WEB_SEARCH_MAX_RESULTS_KEY,
        self::WEB_SEARCH_TIMEOUT_KEY,
        self::INTERACTIVE_IDLE_KEY,
        self::PARALLEL_TIMEOUT_KEY,
    ];

    /** @param array<string, int|float|string> $values every key that is set and honoured */
    private function __construct(private array $values)
    {
    }

    /** @param array<string, mixed> $config the merged config (`Bootstrap::readUserConfig()`) */
    public static function fromConfig(array $config): self
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $value = self::honoured($config, $key);
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * The limits the merged config sets now, or none: guarded like
     * `EngineBackend::userConfig()`, because a missing or malformed settings
     * file must cost the tools' own bounds, never a tool call.
     */
    public static function current(): self
    {
        try {
            return self::fromConfig(Bootstrap::readUserConfig());
        } catch (\Throwable) {
            return new self([]);
        }
    }

    /**
     * $config[$key] when it is set and passes the key's own schema
     * validation, else null. An Int key also takes an integral finite float,
     * the shape a JSON writer may give a whole number.
     *
     * @param array<string, mixed> $config
     */
    public static function honoured(array $config, string $key): int|float|string|null
    {
        $definition = SettingsSchema::byKey($key);
        $raw = $config[$key] ?? null;
        if ($definition === null || $raw === null) {
            return null;
        }

        if ($definition->type === SettingType::Int && \is_float($raw) && is_finite($raw) && $raw === floor($raw) && abs($raw) < 2 ** 53) {
            $raw = (int) $raw;
        }

        if (!\is_int($raw) && !\is_float($raw) && !\is_string($raw)) {
            return null;
        }

        return $definition->validate($raw) === null ? $raw : null;
    }

    /** The honoured whole-number value of $key, or null when it is unset. */
    public function int(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /** The honoured number of seconds of $key, or null when it is unset. */
    public function seconds(string $key): ?float
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }

    /**
     * $tool with every bound a setting names rebound, or $tool itself when
     * none applies to it. Each tool keeps the bounds no key sets.
     */
    public function applyTo(Tool $tool): Tool
    {
        if ($tool instanceof McpToolBridge) {
            $cap = $this->int(self::MCP_RESULT_CAP_KEY);

            return $cap === null ? $tool : $tool->withMaxOutputBytes($cap);
        }

        if ($tool instanceof Read) {
            $maxBytes = $this->int(self::READ_MAX_BYTES_KEY);
            $pageLines = $this->int(self::READ_PAGE_LINES_KEY);
            $pageBytes = $this->int(self::READ_PAGE_BYTES_KEY);

            return $maxBytes === null && $pageLines === null && $pageBytes === null
                ? $tool
                : $tool->withReadLimits($maxBytes, $pageLines, $pageBytes);
        }

        if ($tool instanceof Glob && ($maxMatches = $this->int(self::GLOB_MAX_MATCHES_KEY)) !== null) {
            $tool = $tool->withMaxMatches($maxMatches);
        }

        if ($tool instanceof WebFetch) {
            $timeout = $this->int(self::WEB_FETCH_TIMEOUT_KEY);
            $wire = $this->int(self::WEB_FETCH_MAX_BYTES_KEY);
            if ($timeout !== null || $wire !== null) {
                $tool = $tool->withFetchLimits($timeout, $wire);
            }
        }

        $cap = $this->int(self::OUTPUT_CAP_KEY);
        if ($cap !== null && \in_array(TruncatesOutput::class, class_uses($tool), true)) {
            $tool = $tool->withMaxOutputBytes($cap);
        }

        return $tool;
    }
}
