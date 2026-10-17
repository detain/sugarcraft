<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use Composer\InstalledVersions;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Workspace\GitRunner;
use SugarCraft\Cli\HelpText;

/**
 * Static factory for the one-shot (non-interactive) CLI's --help and
 * --version output.
 *
 * Mirrors the plain-text help pattern from candy-mail/bin/candy-mail's help().
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
     * The Composer package whose installed version IS candy-code's version.
     */
    private const PACKAGE = 'sugarcraft/candy-code';
    /**
     * Return the --help text for the candy-code binary.
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
     *
     * The page is passed through {@see HelpText::renderPage()} (E453,
     * campaign rerun lane A6): catalogue text is caller-supplied display
     * data, so escapes and stray control bytes are stripped before it
     * reaches a terminal — while renderPage's page variant keeps the
     * embedded newlines that ARE the layout above (the single-line
     * flattening in render() would collapse the whole screen onto one row).
     * Width is left null deliberately: the page ships already aligned by
     * its translators, so no probed terminal width may re-flow it. For the
     * clean English page — and any locale that keeps its layout — the call
     * is byte-identity, verified by HelpTest.
     */
    public static function screen(): string
    {
        return HelpText::renderPage(Lang::t('cli.help.screen'));
    }

    /**
     * Return the --version line for the candy-code binary, newline-terminated.
     *
     * Plain text on STDOUT, exactly like {@see screen()}: `--version` is the
     * flag a packaging script or a bug report runs first, so it must work on a
     * machine with no provider, no config and no TTY.
     */
    public static function version(): string
    {
        return 'candy-code ' . self::versionString() . "\n";
    }

    /**
     * The installed version of this package, e.g. `v1.2.0`, or
     * `dev-master (3f9eac2)` for a source checkout.
     *
     * Read from Composer's install metadata rather than declared as a literal
     * anywhere in this repo, because a literal is exactly the thing that rots:
     * candy-code ships from a monorepo whose per-lib package is split out and
     * tagged downstream, so nothing in this directory ever learns its own
     * release number — a hardcoded `const VERSION = '0.1.0'` would still say
     * 0.1.0 three tags later, and the bug reports quoting it would be wrong.
     * `InstalledVersions` is derived from whatever actually got installed, so
     * it reports the real tag the moment there is one and stays honest until
     * then. It is also always present: `bin/candy-code` cannot start without
     * the Composer autoloader that defines it.
     *
     * The commit reference is appended for dev versions only. On a dev
     * checkout `dev-master` alone identifies nothing — every checkout since
     * the branch existed says `dev-master` — whereas a tag is already exact
     * and does not need decorating.
     *
     * ISS-082: on a dev version the reference comes from the CHECKOUT when
     * there is one. Composer's install metadata freezes the commit as of the
     * last `composer install`, which in a git checkout rots one commit per
     * pull — bug reports from a monorepo quoted `dev-master (d2f2a58)` while
     * HEAD had moved 115 commits on. A bounded `git rev-parse --short HEAD`
     * under the package's own install path reports what actually runs, with
     * `+dirty` when tracked files differ from it. Installs that are not a
     * checkout (a dist copy under some consumer's `vendor/`, where any git
     * answer would describe the CONSUMER's repository) keep the Composer
     * reference, and tagged versions are untouched by all of this.
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

        if ($pretty !== null && \str_contains($pretty, 'dev')) {
            $checkout = self::checkoutReference();
            if ($checkout !== null) {
                return self::versionStringFor($pretty, $checkout['sha'], $checkout['dirty']);
            }
        }

        return self::versionStringFor($pretty, $reference);
    }

    /**
     * The commit this checkout is built from, and whether its tracked files
     * differ from it — or null when this install is not a git checkout.
     *
     * Two guards decide "checkout vs vendored copy", because running git in
     * the wrong directory answers a different question than the one asked:
     * the install path must resolve (phars and non-Composer autoloaders have
     * no path to walk from) and must not sit under a `vendor/` directory (a
     * dist-installed copy would otherwise report its CONSUMER's HEAD). The
     * two git calls are bounded ({@see GIT_PROBE_TIMEOUT_SECONDS}) and every
     * refusal — no git binary, no repository, a timeout, output that is not
     * a hex object id — falls back to the Composer reference rather than
     * hiding the version. A dirty read that fails is reported CLEAN: the
     * commit itself was answered honestly, and `--version` must work on a
     * machine where `git status` cannot.
     *
     * Untracked files do NOT set `+dirty` (`status -uno`): the marker answers
     * "is the code that runs what this commit is?", and an untracked note
     * changes nothing that gets built.
     *
     * @return ?array{sha: string, dirty: bool}
     */
    private static function checkoutReference(): ?array
    {
        $installPath = InstalledVersions::getInstallPath(self::PACKAGE);
        if (!\is_string($installPath)) {
            return null;
        }

        $real = \realpath($installPath);
        if ($real === false || \preg_match('#(?:^|/)vendor/#', $real) === 1 || !GitRunner::available()) {
            return null;
        }

        $git = GitRunner::new($real)->withTimeout(self::GIT_PROBE_TIMEOUT_SECONDS);

        $head = $git->capture(self::GIT_PROBE_MAX_BYTES, 'rev-parse', '--short', 'HEAD');
        $sha = \trim($head['stdout']);
        if ($head['exitCode'] !== 0 || $head['timedOut'] || \preg_match('/^[0-9a-f]{7,40}$/', $sha) !== 1) {
            return null;
        }

        $status = $git->capture(64, 'status', '--porcelain', '-uno');

        return [
            'sha' => $sha,
            'dirty' => $status['exitCode'] === 0 && !$status['timedOut'] && \trim($status['stdout']) !== '',
        ];
    }

    /** Wall-clock bound on each of the two ISS-082 probes. */
    private const GIT_PROBE_TIMEOUT_SECONDS = 2.0;

    /** A short object id is 41 bytes; anything bigger is not an id. */
    private const GIT_PROBE_MAX_BYTES = 64;

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
     * @param bool $dirty        ISS-082: appends `+dirty` to the dev decoration;
     *                           has no effect on a tagged version, which carries
     *                           no decoration to dirty.
     */
    public static function versionStringFor(?string $pretty, ?string $reference, bool $dirty = false): string
    {
        if ($pretty === null || $pretty === '') {
            return 'unknown';
        }

        if ($reference !== null && \str_contains($pretty, 'dev')) {
            return $pretty . ' (' . \substr($reference, 0, 7) . ($dirty ? '+dirty' : '') . ')';
        }

        return $pretty;
    }
}
