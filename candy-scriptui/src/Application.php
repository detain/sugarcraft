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
use SugarCraft\ScriptUi\Command\WriteCommand;
use SugarCraft\ScriptUi\Attribute\Alias;
use SugarCraft\ScriptUi\Attribute\Example;
use SugarCraft\ScriptUi\Discovery\CommandScanner;
use SugarCraft\ScriptUi\Help\HelpFormatter;
use SugarCraft\ScriptUi\Help\TypoSuggester;
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

    public function __construct()
    {
        parent::__construct('candy-scriptui', $this->versionFromComposer());
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
        ]);
    }

    /**
     * Reads the version from the root composer.json file.
     */
    public function versionFromComposer(): string
    {
        $json = $this->findRootComposerJson();
        if ($json === null) {
            return '0.0.0';
        }

        return $json['version'] ?? '0.0.0';
    }

    /**
     * Finds the root composer.json and returns its decoded JSON.
     *
     * @return array<string, mixed>|null Decoded composer.json, or null if not found.
     */
    private function findRootComposerJson(): ?array
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
                if (is_array($json) && ($json['version'] ?? '') !== '') {
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
            } catch (\Symfony\Component\Console\Exception\RuntimeException) {
                // Input binding failed (e.g., unrecognized options); continue
                // with env-var fallback which applies values best-effort.
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
        //      injected as tokens when possible, else applied best-effort.
        $valueOptions = [];
        $definition = $command->getDefinition();
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
            if (!$option->acceptValue()) {
                // Flag option — setOption works fine for these since they have no
                // value that could be reset by a second bind() call.
                if (in_array(strtolower($envValue), ['1', 'true', 'yes'], true)) {
                    $input->setOption($option->getName(), true);
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
        // Inject the env-backed value options at the front of the ArgvInput
        // token stream so they survive Command::run()'s second bind().
        if ($valueOptions === [] || !$input instanceof \Symfony\Component\Console\Input\ArgvInput) {
            return;
        }
        if (property_exists($input, self::ARGV_TOKENS_PROPERTY)) {
            $tokens = [];
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
        // ArgvInput's private $tokens property, so we can no longer inject
        // tokens. Fall back to best-effort setOption() for each value option.
        // This may not survive Command::run()'s second bind() (the very reason
        // token injection exists), but it beats a fatal ReflectionException.
        // ApplicationEnvTokenInjectionTest fails loudly in CI when this path is
        // reached so ARGV_TOKENS_PROPERTY gets updated to the new name.
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

    public function find(string $name): Command
    {
        try {
            return parent::find($name);
        } catch (CommandNotFoundException $e) {
            $commandNames = array_keys($this->all());
            $suggester = new TypoSuggester($commandNames);
            $suggestion = $suggester->suggest($name);

            if ($suggestion !== null) {
                // The message already names the single suggestion. Passing
                // every registered command as "alternatives" violated the
                // CommandNotFoundException contract (string[]) and would
                // have made Symfony render its own Did-you-mean list on
                // top of ours — keep alternatives empty. Do NOT chain the
                // parent exception either: Symfony 7.4's renderer prints
                // every previous exception, which stacked a second
                // "Did you mean" block on top of this one (measured).
                throw new CommandNotFoundException(
                    sprintf(
                        'Command "%s" not found. Did you mean %s?',
                        $name,
                        $suggestion
                    )
                );
            }

            throw $e;
        }
    }
}
