<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Cli;

use Composer\InstalledVersions;
use SugarCraft\Crush\Lang;

/**
 * Static factory for the one-shot (non-interactive) CLI's --help and
 * --version output.
 *
 * Mirrors the plain-text help pattern from sugar-post/bin/pop's help().
 *
 * `--version` lives here rather than in a class of its own because it is the
 * same kind of thing as `--help`: a plain string the binary writes straight to
 * STDOUT before any TUI or backend wiring exists, produced by no state.
 *
 * Production reachability: wired in W1.E3 via ArgvParser::parse() →
 *   if $args->help { fwrite(STDOUT, Help::screen()); exit(0); }
 *   if $args->version { fwrite(STDOUT, Help::version()); exit(0); }
 */
final class Help
{
    /**
     * The Composer package whose installed version IS sugarcrush's version.
     */
    private const PACKAGE = 'sugarcraft/sugar-crush';
    /**
     * Return the --help text for the sugarcrush binary.
     *
     * This is the plain-text help screen for the one-shot / scripted /
     * CI-friendly invocation mode. It is NOT a TUI component — it is a plain
     * string that gets written directly to STDOUT.
     *
     * The text is ONE catalogue entry, `cli.help.screen` in `lang/en.php`
     * (audit 15b-14): a help screen is translated as a page, column layout
     * included, so it is not split into per-row keys a translator would have
     * to re-align. English is the source of truth; another locale's
     * `lang/<code>.php` replaces the whole page.
     */
    public static function screen(): string
    {
        return Lang::t('cli.help.screen');
    }

    /**
     * Return the --version line for the sugarcrush binary, newline-terminated.
     *
     * Plain text on STDOUT, exactly like {@see screen()}: `--version` is the
     * flag a packaging script or a bug report runs first, so it must work on a
     * machine with no provider, no config and no TTY.
     */
    public static function version(): string
    {
        return 'sugarcrush ' . self::versionString() . "\n";
    }

    /**
     * The installed version of this package, e.g. `v1.2.0`, or
     * `dev-master (3f9eac2)` for a source checkout.
     *
     * Read from Composer's install metadata rather than declared as a literal
     * anywhere in this repo, because a literal is exactly the thing that rots:
     * sugar-crush ships from a monorepo whose per-lib package is split out and
     * tagged downstream, so nothing in this directory ever learns its own
     * release number — a hardcoded `const VERSION = '0.1.0'` would still say
     * 0.1.0 three tags later, and the bug reports quoting it would be wrong.
     * `InstalledVersions` is derived from whatever actually got installed, so
     * it reports the real tag the moment there is one and stays honest until
     * then. It is also always present: `bin/sugarcrush` cannot start without
     * the Composer autoloader that defines it.
     *
     * The commit reference is appended for dev versions only. On a dev
     * checkout `dev-master` alone identifies nothing — every checkout since
     * the branch existed says `dev-master` — whereas a tag is already exact
     * and does not need decorating.
     */
    public static function versionString(): string
    {
        // Guarded rather than assumed: a consumer vendoring this package under
        // a non-Composer autoloader (PSR-4 map, phar) gets "unknown" instead of
        // a fatal error on the one command that exists to be diagnostic.
        if (!\class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
            $reference = InstalledVersions::getReference(self::PACKAGE);
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }

        return self::versionStringFor($pretty, $reference);
    }

    /**
     * The decoration rule on its own, given metadata rather than reading it.
     *
     * Split out because the rule has two arms and no single environment can run
     * both: Composer guesses the root package's reference from VCS only when
     * `COMPOSER_ROOT_VERSION` is unset, so a developer checkout ALWAYS has one
     * and CI — which sets that variable workflow-wide — NEVER does. Measured
     * with `composer show --self`: without the variable the source reference is
     * the real commit, with it the reference is empty. A test calling
     * {@see versionString()} therefore only ever exercises whichever arm its own
     * environment permits, which is how a bare `dev-master` reached CI while
     * every local run stayed green.
     *
     * @param ?string $pretty    Composer's pretty version, or null when absent.
     * @param ?string $reference The install's commit, or null when Composer did
     *                           not resolve one.
     */
    public static function versionStringFor(?string $pretty, ?string $reference): string
    {
        if ($pretty === null || $pretty === '') {
            return 'unknown';
        }

        if ($reference !== null && \str_contains($pretty, 'dev')) {
            return $pretty . ' (' . \substr($reference, 0, 7) . ')';
        }

        return $pretty;
    }
}
