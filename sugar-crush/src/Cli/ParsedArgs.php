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
    public const SUBCOMMANDS = ['completion', 'doctor', 'mcp', 'models', 'session'];

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
    ) {
    }

    /**
     * Construct a ParsedArgs instance.
     *
     * @param list<string> $unknownFlags
     * @param list<string> $subcommandArgs
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
    ): self {
        return new self($help, $prompt, $root, $outputFormat, $unknownFlags, $promptRequested, $version, $usageError, $usageHint, $configPath, $subcommand, $subcommandArgs, $model, $permissionMode, $continueSession, $resumeSession, $resumeRequested);
    }
}
