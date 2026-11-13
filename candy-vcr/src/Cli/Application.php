<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Cli;

use SugarCraft\Vcr\Lang;
use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Subcommand router for `bin/candy-vcr`. Dispatches to one of the
 * registered {@see Command} implementations based on the first
 * positional arg.
 */
final class Application
{
    /** The composer-mode answer of `--version` (see {@see self::versionString()}). */
    public const FALLBACK_VERSION = 'dev';

    /** @var array<string> */
    public const VERSION_FLAGS = ['--version', '-V'];

    /** @var array<string, Command|SymfonyCommand> */
    private array $commands;

    /**
     * @param array<string, Command|SymfonyCommand>|null $commands
     */
    public function __construct(?array $commands = null)
    {
        $this->commands = $commands ?? [
            'record' => new RecordCommand(),
            'inspect' => new InspectCommand(),
            'replay' => new ReplayCommand(),
            'diff' => new DiffCommand(),
            'stats' => new StatsCommand(),
            'migrate' => new MigrateCommand(),
            'render-tape' => new RenderTapeCommand(),
            'render-batch' => new RenderBatchCommand(),
        ];
    }

    /**
     * @param list<string> $argv  Full argv from the bin script (argv[0] is the script).
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $args = array_slice($argv, 1);
        if ($args !== [] && in_array($args[0], self::VERSION_FLAGS, true)) {
            // Answered before the help/unknown-subcommand arms: like every other
            // bin in the fleet, --version is the first thing a packaging script
            // or a bug report runs, and until the phar program this app answered
            // no version at all (`--version` landed in the unknown-subcommand arm
            // with exit 2).
            fwrite($stdout, 'candy-vcr ' . self::versionString() . "\n");

            return 0;
        }
        if ($args === [] || in_array($args[0], ['-h', '--help', 'help'], true)) {
            if (($args[0] ?? '') === 'help' && isset($args[1])) {
                return $this->printCommandHelp($args[1], $stdout, $stderr);
            }
            $this->printUsage($stdout);
            return $args === [] ? 2 : 0;
        }

        $name = $args[0];
        $rest = array_slice($args, 1);
        if (!isset($this->commands[$name])) {
            fwrite($stderr, Lang::t('app.unknown_subcommand', ['name' => $name]) . "\n\n");
            $this->printUsage($stderr);
            return 2;
        }

        $command = $this->commands[$name];

        if ($command instanceof SymfonyCommand) {
            return $this->runSymfonyCommand($command, $rest, $stdout, $stderr);
        }

        if ($command instanceof DocumentedCommand && self::requestsHelp($rest)) {
            // One help contract across the whole CLI: `-h`/`--help` anywhere on
            // a hand-rolled verb answers with that verb's own usage block on
            // stdout and exit 0. Before this the flag was refused as an unknown
            // option by inspect/replay/migrate (exit 2), dropped by
            // stats/diff, and only record/render-* — the Symfony verbs —
            // honoured it; worse, `-h` after a hand-rolled verb was taken as a
            // positional path, so `candy-vcr stats -h` tried to open a cassette
            // literally named `-h`.
            fwrite($stdout, $command->usage());

            return 0;
        }

        return $command->run($rest, $stdout, $stderr);
    }

    /**
     * `candy-vcr help <sub>` answers with that sub's own help. Before this the
     * verb was ignored and only the global usage printed, so `help inspect`
     * could never show inspect's flags — while the same information was
     * reachable as `inspect --help` (the DocumentedCommand door above).
     *
     * @param resource $stdout
     * @param resource $stderr
     */
    private function printCommandHelp(string $name, $stdout, $stderr): int
    {
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            fwrite($stderr, Lang::t('app.unknown_subcommand', ['name' => $name]) . "\n\n");
            $this->printUsage($stderr);
            return 2;
        }

        if ($command instanceof SymfonyCommand) {
            return $this->runSymfonyCommand($command, ['--help'], $stdout, $stderr);
        }

        if ($command instanceof DocumentedCommand) {
            fwrite($stdout, $command->usage());
            return 0;
        }

        // A hand-rolled verb without usage(): the global listing is the best
        // answer available; keep exit 0 because the sub itself is valid.
        $this->printUsage($stdout);
        return 0;
    }

    /**
     * @param list<string> $args
     */
    private static function requestsHelp(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $argv
     * @param resource $stdout
     * @param resource $stderr
     */
    private function runSymfonyCommand(
        SymfonyCommand $command,
        array $argv,
        $stdout,
        $stderr,
    ): int {
        $symfonyApp = new SymfonyApp();
        $symfonyApp->setAutoExit(false);
        $symfonyApp->add($command);

        $name = $command->getName() ?? '';
        $argString = $name;
        foreach ($argv as $arg) {
            $argString .= ' ' . self::escapeArg($arg);
        }
        $input = new StringInput($argString);

        // A dual-stream output so ConsoleOutputInterface-aware diagnostics
        // (dry-run warnings, progress) reach the caller's stderr instead of
        // vanishing behind the plain StreamOutput the S09 warning channel
        // could not see through (finding 024).
        $output = new DualStreamOutput($stdout, $stderr);

        return $symfonyApp->run($input, $output);
    }

    private static function escapeArg(string $arg): string
    {
        if ($arg === '') {
            return "''";
        }
        if (preg_match('/^[A-Za-z0-9_\-\.\/=@:]+$/', $arg) === 1) {
            return $arg;
        }
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }

    /**
     * @param resource $out
     */
    private function printUsage($out): void
    {
        fwrite($out, Lang::t('app.usage') . "\n\n");
        fwrite($out, Lang::t('app.subcommands') . "\n");
        foreach ($this->commands as $name => $cmd) {
            if ($cmd instanceof SymfonyCommand) {
                fwrite($out, sprintf("  %-14s %s\n", $name, $cmd->getDescription()));
            } else {
                fwrite($out, sprintf("  %-14s %s\n", $name, $cmd->summary()));
            }
        }
        fwrite($out, "\n  --version, -V  " . Lang::t('app.version_flag') . "\n");
    }

    /**
     * The `--version` answer, phar-aware (the version-stamp lesson from candy-code).
     *
     * Inside a phar the archive's own build manifest is the authority: the
     * frozen `vendor/` baked into the artifact makes Composer's InstalledVersions
     * report the build root's dev guess forever, so the stamp written by
     * tools/build-phar.php wins whenever it names a usable version. A bare
     * `dev-build` stamp is working-tree noise (no release tag was reachable at
     * build time) and falls through, exactly like candy-code's fail-closed
     * Version::fromPharMetadata(); outside an archive nothing is consulted and
     * the fallback answers — this app carried no version string before the
     * phar program, so `dev` is the honest composer-mode answer.
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
                if (is_string($stamp) && trim($stamp) !== '' && trim($stamp) !== 'dev-build') {
                    return trim($stamp);
                }
            }
        }

        return self::FALLBACK_VERSION;
    }

    /**
     * The real-filesystem path of the phar archive hosting this install, or ''
     * when this code is not running from an archive.
     *
     * Three identities, first answer wins (measured on PHP 8.3.6 while porting
     * the candy-code version-stamp guard): {@see \Phar::running()} names the archive
     * for any script executing from inside it; in the stub region of a directly
     * executed archive it answers '' and the archive is named by `__FILE__`
     * ending in `.phar`; the `phar://` prefix on this file's own `__FILE__`
     * covers shapes where a class was loaded from the archive but running()
     * stayed empty.
     */
    public static function hostArchive(): string
    {
        $running = \Phar::running(false);
        if ($running !== '') {
            return $running;
        }

        $self = __FILE__;
        if (str_starts_with($self, 'phar://')) {
            if (preg_match('#^phar://(.+\.phar)(?:/|$)#', $self, $m) === 1) {
                return $m[1];
            }

            return '';
        }

        return str_ends_with($self, '.phar') ? $self : '';
    }
}
