<?php

declare(strict_types=1);

namespace SugarCraft\Top\Cli;

use Composer\InstalledVersions;
use SugarCraft\Top\Support\Version;

/**
 * The one-shot (non-interactive) version surface for the candy-top binary.
 *
 * Mirrors the version arms of candy-code/src/Cli/Help.php (the
 * phar+self-update program). candy-top's `--help` page stays exactly where
 * it has always lived — the `cli.help.usage` / `cli.options` catalogue keys
 * rendered inline by `bin/candy-top` — and this class owns only `--version`
 * plus the host-archive resolution `self-update` depends on. A version line
 * is produced by no state, so it lives in a static factory next to the flag
 * that prints it, before any TUI wiring exists.
 *
 * Production reachability: `bin/candy-top` prints
 * `fwrite(STDOUT, Help::version())` on `--version`, and `SelfUpdate::run()`
 * reads {@see versionString()} as its default "what am I running" answer.
 */
final class Help
{
    /**
     * The Composer package whose installed version IS candy-top's version.
     */
    private const PACKAGE = 'sugarcraft/candy-top';

    /** Wall-clock bound on each of the two ISS-082 probes. */
    private const GIT_PROBE_TIMEOUT_SECONDS = 2.0;

    /** A short object id is 41 bytes; anything bigger is not an id. */
    private const GIT_PROBE_MAX_BYTES = 64;

    /**
     * Return the --version line for the candy-top binary, newline-terminated.
     *
     * Plain text on STDOUT: `--version` is the flag a packaging script or a
     * bug report runs first, so it must work on a machine with no config and
     * no TTY — no theme, no host probe, no logo corpus.
     */
    public static function version(): string
    {
        return 'candy-top ' . self::versionString() . "\n";
    }

    /**
     * The installed version of this package, e.g. `v1.2.0`, or
     * `dev-master (3f9eac2)` for a source checkout.
     *
     * Read from Composer's install metadata rather than declared as a literal
     * anywhere in this repo, because a literal is exactly the thing that
     * rots: candy-top ships from a monorepo whose per-lib package is split
     * out and tagged downstream, so nothing in this directory ever learns its
     * own release number. `InstalledVersions` is derived from whatever
     * actually got installed, so it reports the real tag the moment there is
     * one and stays honest until then.
     *
     * The commit reference is appended for dev versions only. On a dev
     * checkout `dev-master` alone identifies nothing — whereas a tag is
     * already exact and does not need decorating.
     *
     * ISS-082: on a dev version the reference comes from the CHECKOUT when
     * there is one. Composer's install metadata freezes the commit as of the
     * last `composer install`, which in a git checkout rots one commit per
     * pull. A bounded `git rev-parse --short HEAD` under the package's own
     * install path reports what actually runs, with `+dirty` when tracked
     * files differ from it. Installs that are not a checkout (a dist copy
     * under some consumer's `vendor/`, where any git answer would describe
     * the CONSUMER's repository) keep the Composer reference, and tagged
     * versions are untouched by all of this.
     *
     * DST-1/DST-3: inside a phar the archive's own build manifest is the
     * authority, consulted BEFORE `InstalledVersions`. The frozen `vendor/`
     * baked into a release archive carries the build root's dev guess, so the
     * Composer metadata cannot answer for an artifact whose version was
     * stamped at build time. When the manifest names a version this class
     * can order — or a dev stamp it can classify (`dev-…`, the working-tree
     * build's own self-report, and the input `self-update` uses to choose
     * its release channel) — that is the answer; an archive whose manifest
     * names neither falls through to every arm below unchanged.
     */
    public static function versionString(): string
    {
        $archive = self::hostArchive();
        if ($archive !== '') {
            $stamped = Version::fromPharMetadata($archive);
            if ($stamped !== null) {
                return $stamped;
            }
        }

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
     * Test seam only (house shape: `SelfUpdate::useErrorStreamForTesting`).
     * Pretends the process was launched from the phar at $path — pass null
     * to restore auto-detection. Without it the DST-1 metadata arm of
     * {@see versionString()} is unreachable from the suite, because a
     * phpunit process never lives inside an archive; the non-injected
     * self-update pin drives the real resolution path through here.
     */
    public static function pretendHostArchiveForTesting(?string $path): void
    {
        self::$hostArchiveOverride = $path;
    }

    private static ?string $hostArchiveOverride = null;

    /**
     * The filesystem path of the phar this process was launched from, or ''
     * when it was not launched from one.
     *
     * TWO shapes, both measured on candy-code's real builds (the same pair
     * `bin/candy-code`'s autoload guard addresses): `\Phar::running(false)`
     * answers the archive while code runs from inside it, and answers '' in
     * the STUB context — where the process IS the archive. In the stub
     * context this file's `__DIR__` is the archive root itself, so a
     * `.phar`-suffixed `__DIR__` is the archive path (a `.phar`-suffixed
     * `__FILE__` would name a FILE ending in .phar, never the enclosing
     * archive — a one-token bug that reads as working). The suffix gate
     * keeps a stray `.phar`-named plain file from being parsed, and a
     * non-phar install (composer, monorepo checkout, this suite) pays two
     * string checks and answers '' every time.
     */
    private static function hostArchive(): string
    {
        if (self::$hostArchiveOverride !== null) {
            return self::$hostArchiveOverride;
        }

        $running = \Phar::running(false);
        if ($running !== '') {
            return $running;
        }

        $file = __DIR__;

        return \str_ends_with($file, '.phar') ? $file : '';
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
        if (!\class_exists(InstalledVersions::class)) {
            return null;
        }
        $installPath = InstalledVersions::getInstallPath(self::PACKAGE);
        if (!\is_string($installPath)) {
            return null;
        }

        $real = \realpath($installPath);
        if ($real === false || \preg_match('#(?:^|/)vendor/#', $real) === 1) {
            return null;
        }

        $head = self::capture($real, self::GIT_PROBE_MAX_BYTES, self::GIT_PROBE_TIMEOUT_SECONDS, 'git', 'rev-parse', '--short', 'HEAD');
        $sha = \trim($head['stdout']);
        if ($head['exitCode'] !== 0 || $head['timedOut'] || \preg_match('/^[0-9a-f]{7,40}$/', $sha) !== 1) {
            return null;
        }

        $status = self::capture($real, 64, self::GIT_PROBE_TIMEOUT_SECONDS, 'git', 'status', '--porcelain', '-uno');

        return [
            'sha' => $sha,
            'dirty' => $status['exitCode'] === 0 && !$status['timedOut'] && \trim($status['stdout']) !== '',
        ];
    }

    /**
     * One bounded argv-array git probe: wall-clock deadline, byte cap, TERM
     * then KILL escalation on overrun, reap via proc_close only AFTER both
     * pipes drained to EOF (the kernel closes a dying child's stdio before
     * it becomes waitable — a single-shot proc_get_status right after EOF is
     * the documented race; draining first, reaping second, is the safe
     * order). Never throws: an unavailable or misbehaving git is answered
     * like every other refusal in {@see checkoutReference()} — non-zero
     * exit, caller falls back to the Composer reference.
     *
     * The environment is an explicit allow-list, not the inherited one, so a
     * stray GIT_DIR/GIT_WORK_TREE/GIT_INDEX_FILE in the launching shell can
     * never steer the probe at a different repository (the read-only analogue
     * of candy-code's GitRunner SCRUBBED_GIT_ENV, which needs no shell there
     * because argv-array spawn lets us rebuild the environment outright).
     *
     * @return array{stdout: string, exitCode: int, timedOut: bool}
     */
    private static function capture(string $cwd, int $maxBytes, float $timeoutSeconds, string ...$args): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $path = \getenv('PATH');
        $home = \getenv('HOME');
        $env = [
            'PATH' => \is_string($path) ? $path : '/usr/bin:/bin',
            'HOME' => \is_string($home) ? $home : '',
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_TERMINAL_PROMPT' => '0',
        ];

        try {
            $process = @\proc_open($args, $descriptors, $pipes, $cwd, $env);
        } catch (\Error) {
            // proc_open listed in disable_functions: PHP 8 raises Error on the
            // call itself, exactly like the LiveProbe/AsyncCommand guards in
            // this package's collectors. A refusal, not an exception — the
            // version surface must answer on locked-down hosts.
            return ['stdout' => '', 'exitCode' => 127, 'timedOut' => false];
        }
        if (!\is_resource($process)) {
            return ['stdout' => '', 'exitCode' => 127, 'timedOut' => false];
        }
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $stdout = '';
        $timedOut = false;
        $deadline = \microtime(true) + $timeoutSeconds;
        while (true) {
            if (\feof($pipes[1]) && \feof($pipes[2])) {
                break;
            }
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0) {
                $timedOut = true;
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $ready = @\stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
            if ($ready === false) {
                break; // interrupted; drain what arrived, judge below
            }
            foreach ($read as $stream) {
                $chunk = \fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= \substr($chunk, 0, \max(0, $maxBytes - \strlen($stdout)));
                }
            }
        }

        if ($timedOut) {
            \proc_terminate($process, 15);
            $grace = \microtime(true) + 0.5;
            while (\microtime(true) < $grace && (\proc_get_status($process)['running'] ?? false)) {
                \usleep(10_000);
            }
            if (\proc_get_status($process)['running'] ?? false) {
                \proc_terminate($process, 9);
            }
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($process);

        return ['stdout' => $stdout, 'exitCode' => $timedOut ? 124 : $exit, 'timedOut' => $timedOut];
    }

    /**
     * The decoration rule on its own, given metadata rather than reading it.
     *
     * Split out because the rule has two arms and no single environment can
     * run both: Composer guesses the root package's reference from VCS only
     * when `COMPOSER_ROOT_VERSION` is unset, so a developer checkout ALWAYS
     * has one and CI — which sets that variable workflow-wide — NEVER does.
     * A test calling {@see versionString()} therefore only ever exercises
     * whichever arm its own environment permits; {@see versionStringFor()}
     * pins both from data.
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
