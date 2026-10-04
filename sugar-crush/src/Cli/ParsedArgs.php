<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

/**
 * Immutable value-object returned by {@see ArgvParser::parse()}.
 * All fields are readonly — use ArgvParser::parse() to construct.
 */
final readonly class ParsedArgs
{
    /**
     * Mirrors {@see NonInteractive::FORMAT_TEXT} — duplicated as a literal
     * (rather than referencing the constant directly) so this file has no
     * compile-time dependency on NonInteractive; the values are kept in
     * sync by {@see ArgvParserTest::testOutputFormatDefaultsToText()}.
     */
    public const DEFAULT_OUTPUT_FORMAT = 'text';

    /**
     * Every value `--output-format` accepts, in help-text order. Mirrors
     * {@see NonInteractive::FORMAT_TEXT} and {@see NonInteractive::FORMAT_JSON}
     * as literals for the same no-compile-time-dependency reason as
     * {@see self::DEFAULT_OUTPUT_FORMAT}; kept in sync by
     * {@see ArgvParserTest::testOutputFormatsMirrorNonInteractiveConstants()}.
     *
     * Matched CASE-SENSITIVELY by {@see ArgvParser::parse()} -- see the
     * comment at that check for why.
     *
     * @var list<string>
     */
    public const OUTPUT_FORMATS = ['text', 'json'];

    /**
     * Every subcommand VERB {@see ArgvParser::parse()} recognises, in help-text
     * order. The verb only; each one's own operands (`list`, `delete <id>`,
     * `bash|zsh|fish`) are carried unvalidated in {@see self::$subcommandArgs}
     * and decided by {@see Subcommands}, which owns their messages.
     *
     * The split is deliberate and is the same one {@see
     * ArgvParser::rootError()} and {@see ArgvParser::configError()} already
     * make: `parse()` stays a pure argv->value-object transform with no
     * filesystem access and no per-command semantics, so the class that has to
     * open the session database is also the class that decides what a missing
     * session id means.
     *
     * `run` is NOT in this list. It is the pre-existing alias for `-p` and
     * lives on its own branch in `parse()`, because it takes a PROMPT rather
     * than operands and dispatches to {@see NonInteractive::run()} rather than
     * to {@see Subcommands}.
     *
     * @var list<string>
     */
    public const SUBCOMMANDS = ['completion', 'doctor', 'mcp', 'models', 'serve', 'session'];

    /**
     * The flags each subcommand verb owns, keyed by verb: `true` when the flag
     * takes a value (`--limit 5`, `--limit=5`), `false` for a bare switch.
     *
     * SCOPED, not global: {@see ArgvParser::parse()} recognises one of these
     * only once its verb has been seen, so `sugarcrush session list --all` is
     * a listing of every row while `sugarcrush --all` is still an unknown
     * option at exit 2. Before this table every `-x` after a verb was recorded
     * as unknown, which is why `session list --archived` could not exist
     * (Appendix P §3.4). The parser only checks the SHAPE (a value flag needs
     * a value); which ACTION a flag applies to is {@see Subcommands}' call,
     * for the reason {@see self::SUBCOMMANDS} gives.
     *
     * @var array<string, array<string, bool>>
     */
    public const SUBCOMMAND_FLAGS = [
        // `serve` (Appendix O §4.7): every flag scoped, none global — a
        // `--port` before the verb is an unknown option, not a server setting.
        // `--allowed-origin` takes a comma-separated list; a repeat keeps the
        // last one, as every value flag here does.
        'serve' => [
            '--allow-bypass' => false,
            '--allow-remote' => false,
            '--allow-root' => false,
            '--allowed-origin' => true,
            '--host' => true,
            '--no-web' => false,
            '--port' => true,
            '--web-root' => true,
        ],
        'session' => [
            '--all' => false,
            '--archived' => false,
            '--children' => false,
            '--limit' => true,
            '--with-children' => false,
        ],
    ];

    /**
     * @param list<string> $unknownFlags Unrecognised `-`-prefixed arguments,
     *   in the order given. Non-empty means the invocation is a usage error.
     * @param bool $promptRequested True when `-p`/`--prompt`/`--prompt=`/`run`
     *   appeared at all, even without a value — distinct from
     *   `$prompt !== null`, which is only true when a value followed.
     * @param bool $version True when `--version`/`-v` appeared. Dispatched by
     *   `bin/sugarcrush` the same way `$help` is: print and exit before any
     *   TUI/backend wiring is touched.
     * @param string|null $usageError A malformed invocation that is neither an
     *   unknown flag nor a bad `--root` — "a prompt option was handed a flag"
     *   (follow-up #48) or an `--output-format` value nothing implements
     *   (crush_code.md Phase 4 item 6). Non-null means the binary must fail
     *   with exit 2 rather than run anything.
     * @param string|null $usageHint The remedy line printed under
     *   `$usageError`, chosen by whichever check produced that error — a
     *   single hard-coded hint in the binary was wrong for every error but
     *   the first one.
     * @param string|null $configPath The config file named by `--config`, or
     *   null to let {@see \SugarCraft\Crush\Cli\Bootstrap} discover
     *   `~/.sugar-crush/config.json` itself. Names a FILE, not the config
     *   directory: the agents/, skills/ and hooks trees are still discovered.
     *   Filesystem validity is NOT checked here — see
     *   {@see ArgvParser::configError()}.
     * @param string|null $subcommand The subcommand VERB, one of
     *   {@see self::SUBCOMMANDS}, or null when the invocation is a plain TUI /
     *   one-shot run. `bin/sugarcrush` dispatches a non-null value to
     *   {@see Subcommands::dispatch()} before Program is ever constructed.
     * @param list<string> $subcommandArgs The verb's own operands in the order
     *   given (`['delete', '<id>']`), NOT validated here.
     * @param array<string, string|true> $subcommandFlags The verb's own
     *   {@see self::SUBCOMMAND_FLAGS} that appeared after it, flag => value
     *   (`true` for a switch). A repeated flag keeps its last value.
     * @param list<string> $positionals Every bare operand of the invocation
     *   itself (not a subcommand's) that nothing claimed, in the order given:
     *   not a flag's value, not `run`'s prompt, and not the path-shaped
     *   operand {@see ArgvParser::parse()} made the root. Carried rather than
     *   dropped (audit CLI-2) so {@see ArgvParser::resolveOperands()} can do
     *   the filesystem half -- an existing directory becomes the root -- and
     *   turn what is left into {@see $initialPrompt} (or refuse it on a
     *   `-p`/`run` or subcommand run), instead of `sugarcrush fix the login
     *   bug` silently opening the TUI in the cwd.
     */
    private function __construct(
        public bool $help,
        public ?string $prompt,
        public ?string $root,
        public string $outputFormat = self::DEFAULT_OUTPUT_FORMAT,
        public array $unknownFlags = [],
        public bool $promptRequested = false,
        public bool $version = false,
        public ?string $usageError = null,
        public ?string $usageHint = null,
        public ?string $configPath = null,
        public ?string $subcommand = null,
        public array $subcommandArgs = [],
        /** The model `--model` named, or null. Not validated — any string is a
         *  plausible model name to a provider this binary does not know. */
        public ?string $model = null,
        /** The RAW string `--permission-mode` named, or null. Validated by
         *  {@see \SugarCraft\Crush\Cli\Bootstrap::permissionGate()}, not here. */
        public ?string $permissionMode = null,
        /** `-c`/`--continue`: open the most recently used session. */
        public bool $continueSession = false,
        /** The id or name `--resume` named, or null (bare `--resume` opens the picker). */
        public ?string $resumeSession = null,
        /** Whether `--resume` appeared at all, with or without a value. */
        public bool $resumeRequested = false,
        public array $positionals = [],
        /**
         * The leftover words of a TUI launch, joined with single spaces —
         * `sugarcrush fix the login bug` — which the TUI submits as its first
         * prompt (audit CLI-2(b)). Set only by
         * {@see ArgvParser::resolveOperands()}; null when there were none.
         */
        public ?string $initialPrompt = null,
        public array $subcommandFlags = [],
    ) {
    }

    /**
     * A copy whose root is $root and whose unclaimed operands are
     * $positionals -- the result of {@see ArgvParser::resolveOperands()}
     * turning a bare directory operand into the root.
     *
     * @param list<string> $positionals
     */
    public function withRoot(string $root, array $positionals): self
    {
        return $this->copyWith(['root' => $root, 'positionals' => $positionals]);
    }

    /**
     * A copy whose leftover operands became the TUI's first prompt — see
     * {@see $initialPrompt}. The operands are consumed: nothing is left for a
     * later step to refuse.
     */
    public function withInitialPrompt(string $prompt): self
    {
        return $this->copyWith(['initialPrompt' => $prompt, 'positionals' => []]);
    }

    /**
     * A copy carrying a usage error raised after parsing -- the leftover
     * operand refusal in {@see ArgvParser::resolveOperands()} -- so the
     * binary reports it through the same `usageError`/`usageHint` pair the
     * parser's own errors use.
     */
    public function withUsageError(string $usageError, string $usageHint): self
    {
        return $this->copyWith(['usageError' => $usageError, 'usageHint' => $usageHint]);
    }

    /**
     * Every promoted property is a constructor parameter of the same name, so
     * the current state spreads back in as named arguments with the changed
     * fields laid over it. Spelling each field out instead re-listed eighteen
     * positionals per wither (one missed field silently resets it) and read
     * `$this->permissionMode` by name, which ForeignAgentPresetWiringTest's
     * census of Agent::$permissionMode readers cannot tell apart from a read
     * of an agent's mode.
     *
     * @param array<string, mixed> $changes
     */
    private function copyWith(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * Construct a ParsedArgs instance.
     *
     * @param list<string> $unknownFlags
     * @param list<string> $subcommandArgs
     * @param list<string> $positionals
     * @param array<string, string|true> $subcommandFlags
     *
     * @internal
     */
    public static function from(
        bool $help,
        ?string $prompt,
        ?string $root,
        string $outputFormat = self::DEFAULT_OUTPUT_FORMAT,
        array $unknownFlags = [],
        bool $promptRequested = false,
        bool $version = false,
        ?string $usageError = null,
        ?string $usageHint = null,
        ?string $configPath = null,
        ?string $subcommand = null,
        array $subcommandArgs = [],
        ?string $model = null,
        ?string $permissionMode = null,
        bool $continueSession = false,
        ?string $resumeSession = null,
        bool $resumeRequested = false,
        array $positionals = [],
        array $subcommandFlags = [],
    ): self {
        return new self($help, $prompt, $root, $outputFormat, $unknownFlags, $promptRequested, $version, $usageError, $usageHint, $configPath, $subcommand, $subcommandArgs, $model, $permissionMode, $continueSession, $resumeSession, $resumeRequested, $positionals, null, $subcommandFlags);
    }
}
