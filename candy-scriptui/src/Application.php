<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi;

use SugarCraft\ScriptUi\Command\ChooseCommand;
use SugarCraft\ScriptUi\Command\ConfirmCommand;
use SugarCraft\ScriptUi\Command\FileCommand;
use SugarCraft\ScriptUi\Command\FilterCommand;
use SugarCraft\ScriptUi\Command\FormatCommand;
use SugarCraft\ScriptUi\Command\InputCommand;
use SugarCraft\ScriptUi\Command\JoinCommand;
use SugarCraft\ScriptUi\Command\LogCommand;
use SugarCraft\ScriptUi\Command\PagerCommand;
use SugarCraft\ScriptUi\Command\SpinCommand;
use SugarCraft\ScriptUi\Command\StyleCommand;
use SugarCraft\ScriptUi\Command\TableCommand;
use SugarCraft\ScriptUi\Command\VersionCommand;
use SugarCraft\ScriptUi\Command\WriteCommand;
use SugarCraft\ScriptUi\Attribute\Alias;
use SugarCraft\ScriptUi\Attribute\Example;
use SugarCraft\ScriptUi\Discovery\CommandScanner;
use SugarCraft\ScriptUi\Help\HelpFormatter;
use SugarCraft\ScriptUi\Help\TypoSuggester;
use SugarCraft\ScriptUi\Runtime\ColourGate;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Top-level Symfony Console application registering each subcommand.
 */
final class Application extends SymfonyApplication
{
    private const ENV_PREFIX = 'CANDY_SCRIPTUI_';

    /** Composer identity of THIS package — {@see findRootComposerJson()} only
     *  accepts a manifest bearing this name, so a source checkout of the
     *  monorepo never reports the host root's version as the tool's own. */
    private const PACKAGE_NAME = 'sugarcraft/candy-scriptui';

    /**
     * Option names permitted to be sourced from `CANDY_SCRIPTUI_<NAME>`
     * environment variables. This is an ALLOW-LIST, deliberately narrow.
     *
     * Why: the previous universal option↔env mapping defeated every
     * fail-closed decision in the CLI. `CANDY_SCRIPTUI_ALLOW_ENV='*'` made
     * `format --type template` exfiltrate the whole environment with zero
     * warning; `CANDY_SCRIPTUI_DEFAULT=yes` turned `confirm` gates into
     * silent yes; `CANDY_SCRIPTUI_FILE` / `--value` / `--selected` retargeted
     * sinks and pre-filled answers from a variable no help text mentioned.
     * Because names are unscoped, one variable also collided across
     * commands (CANDY_SCRIPTUI_FILE hit log, format, and table at once).
     *
     * Admitted here: presentation-only knobs (colour, weight, geometry,
     * deadline) whose worst-case env effect is cosmetic. Everything that
     * answers for the user, grants capability, or redirects data stays
     * CLI-only by construction.
     *
     * @var list<string>
     */
    public const ENV_FALLBACK_OPTIONS = [
        'align',
        'background',
        'bold',
        'border',
        'border-foreground',
        'cursor-prefix',
        'faint',
        'foreground',
        'height',
        'italic',
        'prefix',
        'reverse',
        'separator',
        'strikethrough',
        'timeout',
        'underline',
        'unselected-prefix',
        'width',
    ];

    /**
     * Sentinel stamp written by tools/build-phar.php when `git describe` found
     * no reachable scriptui tag. versionString() refuses it as a version —
     * working-tree noise must not masquerade as a release identity.
     */
    public const WORKING_TREE_STAMP = 'dev-build';

    /**
     * The PRIVATE property on {@see \Symfony\Component\Console\Input\ArgvInput}
     * that holds the raw token stream. {@see applyEnvVarFallbackToInput()}
     * reflects into it to prepend env-backed value options so they survive
     * Command::run()'s second bind(). Symfony treats this as an internal
     * implementation detail, so a major bump could rename or remove it — which
     * would silently disable the CANDY_SCRIPTUI_* value-option fallback. The name is
     * centralised here so {@see \SugarCraft\ScriptUi\Tests\ApplicationEnvTokenInjectionTest}
     * can pin its presence: that test fails loudly in CI if Symfony moves the
     * property, and the reflection below degrades gracefully instead of fataling.
     */
    private const ARGV_TOKENS_PROPERTY = 'tokens';

    /**
     * The PRIVATE property on {@see \Symfony\Component\Console\Input\ArrayInput}
     * holding its parameter map — reflected by {@see addHelpToken()} to turn
     * `--show-help` into `--help` for programmatically-built inputs. Same
     * pin-or-perish contract as {@see ARGV_TOKENS_PROPERTY}.
     */
    private const ARRAY_PARAMETERS_PROPERTY = 'parameters';

    /**
     * option name => backed enum class, per command name — supplied by the
     * CommandScanner from `#[Flag(enum: ...)]` and enforced in
     * {@see doRunCommand()} before the command's execute() ever sees input.
     *
     * @var array<string, array<string, class-string>>
     */
    private array $enumConstraints = [];

    public function __construct()
    {
        parent::__construct('candy-scriptui', self::versionString());
        $this->addCommands([
            new StyleCommand(),
            new ChooseCommand(),
            new InputCommand(),
            new ConfirmCommand(),
            new JoinCommand(),
            new LogCommand(),
            new TableCommand(),
            new FilterCommand(),
            new WriteCommand(),
            new FileCommand(),
            new PagerCommand(),
            new SpinCommand(),
            new FormatCommand(),
            new CompletionCommand(),
            new VersionCommand(),
        ]);
    }

    /**
     * Reads the version from this PACKAGE's composer.json manifest.
     */
    public function versionFromComposer(): string
    {
        return self::readComposerVersion();
    }

    /**
     * Instance-free core of {@see versionFromComposer()} — the walk reads only
     * compile-time `__DIR__`, so the phar-aware dispatcher can reach it without
     * constructing an Application (which would re-enter the constructor's own
     * versionString() call). The public shape above is byte-preserved for
     * EnvVarFallbackTest's contract.
     */
    private static function readComposerVersion(): string
    {
        $json = self::findRootComposerJson();
        if ($json === null) {
            return '0.0.0';
        }

        return $json['version'] ?? '0.0.0';
    }

    /**
     * The version this install reports, phar-aware (archive metadata first).
     *
     * The old answer walked up from `__DIR__` looking for a composer.json with
     * a non-empty version field. That walk cannot succeed inside a phar: the
     * archive ships bin/, src/, lang/ and vendor/ only (tools/build-phar.php),
     * and even when a composer.json WAS near, a packaged one would be the
     * build root's — frozen, lying `dev-master`. So under an archive the
     * manifest stamp written at build time is the ONLY truth, and it wins;
     * `dev-build` names working-tree noise rather than a version and falls
     * through fail-closed, exactly like candy-code's Version::fromPharMetadata().
     * Outside an archive the dispatcher answers whatever versionFromComposer()
     * answers — byte-identical to the pre-phar contract (pinned by
     * EnvVarFallbackTest): the identity-gated walk stops at THIS package's
     * manifest (scriptui-39), so a monorepo checkout reports the package's
     * own answer, and inside an archive it would land on `0.0.0` because
     * the phar ships no composer.json at all (the dead answer the stamp
     * overrides).
     */
    public static function versionString(): string
    {
        $archive = self::hostArchive();
        if ($archive !== '') {
            try {
                $metadata = (new \Phar($archive))->getMetadata();
            } catch (\Throwable) {
                $metadata = null;
            }
            if (is_array($metadata)) {
                $stamp = $metadata['version'] ?? null;
                if (is_string($stamp) && trim($stamp) !== '' && trim($stamp) !== self::WORKING_TREE_STAMP) {
                    return trim($stamp);
                }
            }
        }

        return self::readComposerVersion();
    }

    /**
     * The real-filesystem path of the phar archive hosting this install, or ''
     * when this code is not running from an archive (same measured 3-arm law as
     * the bin guards: Phar::running() inside the archive, a `phar://` __FILE__
     * prefix, a self-naming `.phar` __FILE__; composer mode is always '').
     */
    public static function hostArchive(): string
    {
        $running = \Phar::running(false);
        if ($running !== '') {
            return $running;
        }

        $self = __FILE__;
        if (str_starts_with($self, 'phar://')) {
            return preg_match('#^phar://(.+\.phar)(?:/|$)#', $self, $m) === 1 ? $m[1] : '';
        }

        return str_ends_with($self, '.phar') ? $self : '';
    }

    /**
     * Finds this PACKAGE's composer.json (scriptui-39, finding 039) and
     * returns its decoded JSON.
     *
     * The old shape accepted the FIRST manifest with a non-empty version —
     * in a monorepo checkout the package manifest carries no version field,
     * so the walk rode past candy-scriptui/ and answered the ROOT's 1.0.0:
     * `candy-scriptui --version` reported a release of the whole monorepo
     * as if it were this tool's own version. Identity-gating on the package
     * NAME keeps the answer scoped to what was actually published.
     *
     * @return array<string, mixed>|null Decoded manifest, or null if not found.
     */
    private static function findRootComposerJson(): ?array
    {
        $dir = __DIR__;
        while ($dir !== dirname($dir)) {
            $composerPath = $dir . '/composer.json';
            if (file_exists($composerPath)) {
                // Unreadable-but-existing files are skipped, not fatal: the
                // version lookup is best-effort and 0.0.0 is the honest
                // answer when the metadata cannot be read.
                $contents = @file_get_contents($composerPath);
                if ($contents === false) {
                    $dir = dirname($dir);
                    continue;
                }
                $json = json_decode($contents, true);
                if (
                    is_array($json)
                    && ($json['name'] ?? '') === self::PACKAGE_NAME
                ) {
                    return $json;
                }
            }
            $dir = dirname($dir);
        }
        return null;
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        $this->setAutoExit(false);
        return parent::run($input, $output);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // Symfony handles the no-command-name shapes itself: `--version`,
        // bare `--help`, and the default `list` command. Reaching find()
        // with a null name would ask for the command '' and die with an
        // "ambiguous" error instead — so only resolve a command when the
        // input actually names one.
        $name = $input->getFirstArgument();
        if ($name !== null) {
            $command = $this->find($name);
            try {
                $input->bind($command->getDefinition());
            } catch (\Symfony\Component\Console\Exception\RuntimeException $error) {
                // The gate bind is advisory: the command-name token is still
                // in the stream, so argument-arity complaints are always
                // artifacts (the authoritative bind happens inside
                // Command::run), and an UNDECLARED OPTION is exactly what the
                // CANDY_SCRIPTUI_* fallback is about to supply. Everything
                // else — an option missing its value, a malformed token — the
                // fallback can never repair, so it propagates from the gate
                // instead of coasting on into an accidental exit shape
                // (scriptui-75, finding 101).
                $message = $error->getMessage();
                $tolerated = str_contains($message, 'option does not exist')
                    || str_contains($message, 'Too many arguments')
                    || str_contains($message, 'Not enough arguments')
                    || str_contains($message, 'No arguments expected');
                if (!$tolerated) {
                    throw $error;
                }
            }
            $this->applyEnvVarFallbackToInput($input, $command);

            // --show-help is a gum-compat alias for --help. Rewrite it into
            // the real token so Symfony's own help path renders the command.
            if ($input->hasParameterOption('--show-help', true)) {
                $this->addHelpToken($input);
            }

            // HelpFormatter reads the class-level #[Alias]/#[Example]
            // attributes Symfony knows nothing about; when help is requested
            // for such a command, print that block ahead of Symfony's help.
            if ($input->hasParameterOption(['--help', '-h'], true)) {
                $this->writeAttributeHelp($command, $output);
            }
        }
        return parent::doRun($input, $output);
    }

    /**
     * Inject a `--help` token into the raw input so the parent help path
     * fires. The parsed-options bag cannot be used instead: Command::run()
     * re-binds (and thereby resets) it from the token stream — the exact
     * hazard the {@see applyEnvVarFallbackToInput()} token injection exists
     * for. When the underlying property is missing (a Symfony major bump),
     * the alias degrades to a no-op; ApplicationShowHelpTest pins the
     * property names so CI fails loudly before the degradation ships.
     */
    private function addHelpToken(InputInterface $input): void
    {
        if ($input instanceof \Symfony\Component\Console\Input\ArgvInput) {
            if (!property_exists($input, self::ARGV_TOKENS_PROPERTY)) {
                return;
            }
            $reflector = new \ReflectionProperty($input, self::ARGV_TOKENS_PROPERTY);
            $reflector->setAccessible(true);
            $reflector->setValue($input, array_merge(
                ['--help'],
                $reflector->getValue($input),
            ));
            return;
        }

        if ($input instanceof \Symfony\Component\Console\Input\ArrayInput) {
            if (!property_exists($input, self::ARRAY_PARAMETERS_PROPERTY)) {
                return;
            }
            $reflector = new \ReflectionProperty($input, self::ARRAY_PARAMETERS_PROPERTY);
            $reflector->setAccessible(true);
            $parameters = $reflector->getValue($input);
            $parameters['--help'] = true;
            $reflector->setValue($input, $parameters);
        }
    }

    /**
     * Render the #[Alias]/#[Example]/#[Command] header via HelpFormatter
     * for commands that actually carry those attributes. Commands without
     * them get Symfony's stock help verbatim (no duplicate banner).
     */
    private function writeAttributeHelp(Command $command, OutputInterface $output): void
    {
        $reflection = new \ReflectionClass($command);
        if (
            $reflection->getAttributes(Example::class) === []
            && $reflection->getAttributes(Alias::class) === []
        ) {
            return;
        }
        (new HelpFormatter())->write($output, $command);
    }

    private function applyEnvVarFallbackToInput(InputInterface $input, Command $command): void
    {
        // Prepend env-backed options as command-line tokens so they survive
        // the second bind() call in Command::run() (handleErrors + initialize
        // both invoke bind/parse, which resets options bag to defaults before
        // re-processing the token stream). By injecting tokens at the front,
        // the value is re-parsed and persists.
        //
        // @var array<string,string> env-backed value options as name => value;
        //      injected as --name=value tokens when possible, else best-effort.
        // @var list<string> env-backed flag (VALUE_NONE) options as bare
        //      '--name' tokens; setOption(true) dies on every re-bind because
        //      a flag has no value the token stream could restore (M1).
        $valueOptions = [];
        $flagTokens = [];
        $definition = clone $command->getDefinition();
        foreach ($definition->getOptions() as $option) {
            // Explicit CLI flag always wins over env var.
            if ($input->hasParameterOption('--' . $option->getName(), true)) {
                continue;
            }
            $envVar = $this->optionToEnvVar($option);
            $envValue = getenv($envVar);
            if ($envValue === false) {
                continue;
            }
            if ($option->isNegatable()) {
                continue;
            }
            // Fail-closed: only presentation knobs may come from the
            // environment (see ENV_FALLBACK_OPTIONS for why).
            if (!\in_array($option->getName(), self::ENV_FALLBACK_OPTIONS, true)) {
                continue;
            }
            if (!$option->acceptValue()) {
                // Flag option — the bare '--name' token re-parses to true on
                // every bind, exactly like the explicit CLI form.
                if (in_array(strtolower($envValue), ['1', 'true', 'yes'], true)) {
                    $flagTokens[] = '--' . $option->getName();
                }
            } else {
                // Value option — MUST use token injection. setOption() appears to
                // succeed but the value does NOT survive the second bind() call in
                // Command::run() (handleErrors). Token injection is the only approach
                // that survives because bind()/parse() re-populates the options bag
                // from the token stream.
                $valueOptions[$option->getName()] = $envValue;
            }
        }
        // Inject the env-backed options at the front of the ArgvInput token
        // stream so they survive Command::run()'s second bind().
        if ($valueOptions === [] && $flagTokens === []) {
            return;
        }
        if ($input instanceof \Symfony\Component\Console\Input\ArgvInput
            && property_exists($input, self::ARGV_TOKENS_PROPERTY)
        ) {
            $tokens = $flagTokens;
            foreach ($valueOptions as $name => $value) {
                $tokens[] = '--' . $name . '=' . $value;
            }
            $reflector = new \ReflectionProperty($input, self::ARGV_TOKENS_PROPERTY);
            $reflector->setAccessible(true);
            $currentTokens = $reflector->getValue($input);
            $reflector->setValue($input, array_merge($tokens, $currentTokens));
            return;
        }
        // Documented graceful fallback: a Symfony upgrade renamed or removed
        // ArgvInput's private $tokens property (or the input is not ArgvInput
        // at all), so we can no longer inject tokens. Fall back to best-effort
        // setOption() for each option. This may not survive Command::run()'s
        // second bind() (the very reason token injection exists), but it beats
        // a fatal ReflectionException. ApplicationEnvTokenInjectionTest fails
        // loudly in CI when the ArgvInput path is reached so
        // ARGV_TOKENS_PROPERTY gets updated to the new name.
        foreach ($flagTokens as $token) {
            $input->setOption(substr($token, 2), true);
        }
        foreach ($valueOptions as $name => $value) {
            $input->setOption($name, $value);
        }
    }

    private function optionToEnvVar(InputOption $option): string
    {
        $name = strtoupper($option->getName());
        $name = preg_replace('/[^A-Z0-9_]/', '_', $name) ?: $name;
        return self::ENV_PREFIX . $name;
    }

    /**
     * Scan a namespace for classes bearing the #[Command] attribute
     * and register them into this application.
     *
     * @param string $namespace Namespace prefix to scan (not a class name).
     * @return list<class-string> Names of the discovered command classes.
     */
    public function scan(string $namespace): array
    {
        $scanner = new CommandScanner();
        return $scanner->scan($namespace, $this);
    }

    /**
     * Resolve a command by EXACT name only. Symfony's inherited find()
     * additionally accepts unique prefixes, which made typo'd invocations
     * silently EXECUTE a different command — `choos Pizza Burger` ran choose,
     * `confir` ran confirm. On an exit-code-gating
     * CLI that is a safety defect, not a convenience. Unknown names now fail
     * closed with a did-you-mean that lists every plausible candidate
     * (prefix matches first), so the suggestion also stops misdirecting.
     */
    public function find(string $name): Command
    {
        if ($this->has($name)) {
            return parent::find($name);
        }

        $suggester  = new TypoSuggester(array_keys($this->all()));
        $candidates = $suggester->suggestAll($name);

        if ($candidates !== []) {
            // Do NOT chain the parent exception or pass alternatives: both
            // stack Symfony's own "Did you mean" renderer on top of ours
            // (measured, previous lane). Our message already lists them.
            $quoted = array_map(static fn (string $n): string => '"' . $n . '"', $candidates);
            throw new CommandNotFoundException(sprintf(
                'Command "%s" not found. Did you mean %s?',
                $name,
                count($quoted) === 1 ? $quoted[0] : 'one of: ' . implode(', ', $quoted),
            ));
        }

        throw new CommandNotFoundException(sprintf('Command "%s" not found.', $name));
    }

    /**
     * Register a value constraint discovered by the CommandScanner from
     * `#[Flag(enum: SomeType::class)]`. The seam existed as documentation
     * only at first, and `--format=pineapple` sailed right through it.
     *
     * @param class-string $enumClass
     */
    public function addEnumConstraint(string $commandName, string $option, string $enumClass): void
    {
        $this->enumConstraints[$commandName][$option] = $enumClass;
    }

    /**
     * Validate every scanned enum-constrained option BEFORE the command
     * executes. Bad values exit 2 (Symfony's invalid-argument convention)
     * with the allowed set named on stderr — an exit-code-gating script
     * must never watch a nonsense value flow into business logic.
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        // Resolve NO_COLOR / FORCE_COLOR / CLICOLOR / TERM=dumb and the
        // global --ansi/--no-ansi flags once per command and pin the
        // ambient sugar-style profile, so every Style minted downstream
        // (flag styles, log badges, markdown themes) renders inside it.
        ColourGate::install($input);

        $constraints = $this->enumConstraints[$command->getName()] ?? [];
        if ($constraints !== []) {
            // Symfony binds INSIDE parent::doRunCommand, so nothing has
            // parsed the options yet at this point. Bind a throwaway clone
            // against a FULLY MERGED definition (the input's own definition
            // is null until Command::run binds it, so our bind is the first
            // and the merge is ours to do); reading the merged stream through
            // an argument-less definition would fatal with
            // 'The "command" argument does not exist'. A bind failure means
            // the parent will raise the same error, louder, moments later.
            try {
                $definition = clone $command->getDefinition();
                if (!$definition->hasArgument('command')) {
                    $definition->addArgument(new \Symfony\Component\Console\Input\InputArgument(
                        'command',
                        \Symfony\Component\Console\Input\InputArgument::OPTIONAL,
                    ));
                }
                $probe = clone $input;
                $probe->bind($definition);
            } catch (\Throwable) {
                return parent::doRunCommand($command, $input, $output);
            }
            foreach ($constraints as $option => $enumClass) {
                if (!$probe->hasOption($option)) {
                    continue;
                }
                $value = $probe->getOption($option);
                if (!is_string($value) && !is_int($value)) {
                    // Unset (null), flag-shaped (bool) or list-shaped values
                    // are not this check's business; the command owns those.
                    continue;
                }
                $message = self::enumViolation($option, (string) $value, $enumClass);
                if ($message !== null) {
                    $target = method_exists($output, 'getErrorOutput')
                        ? $output->getErrorOutput()
                        : $output;
                    // Deliberate contrast with the verbatim selection
                    // echoes in ChooseCommand/FilterCommand: this refusal
                    // embeds a raw value from config/env input, so the
                    // whole message passes through Sanitize::cellValue.
                    $target->writeln('<error>candy-scriptui: ' . \SugarCraft\Core\Util\Sanitize::cellValue($message) . '</error>');
                    return Command::INVALID;
                }
            }
        }

        return parent::doRunCommand($command, $input, $output);
    }

    /**
     * The one violation message shape, shared so wording cannot drift:
     * mirrors {@see Attribute\ValueEnum::validate()}'s text exactly.
     */
    private static function enumViolation(string $option, string $value, string $enumClass): ?string
    {
        if (!enum_exists($enumClass)) {
            return null;
        }
        $reflection = new \ReflectionEnum($enumClass);
        if (!$reflection->isBacked()) {
            return null;
        }

        $allowed = [];
        foreach ($reflection->getCases() as $case) {
            /** @var \ReflectionEnumBackedCase $case */
            $allowed[] = (string) $case->getValue()->value;
        }
        if (in_array($value, $allowed, true)) {
            return null;
        }

        return sprintf(
            "Invalid value for --%s: '%s'. Allowed: %s.",
            $option,
            $value,
            implode('|', $allowed),
        );
    }
}
