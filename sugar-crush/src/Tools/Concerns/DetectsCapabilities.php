<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

/**
 * Which optional host search binaries are genuinely installed, so a tool
 * description can offer one without lying about it.
 *
 * THE CARRIER IS THE BOOT FACTORY, NOT THE TOOL. {@see \SugarCraft\Crush\Cli\Bootstrap}
 * asks once per launch and passes the answer down as a constructor boolean to
 * the tool whose prose consumes it ({@see \SugarCraft\Crush\Tools\BuiltIn\Grep}
 * for `rg`, {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} for `fd`). That split is
 * forced before it is chosen: both tools are `final readonly class`es, and PHP
 * refuses a readonly class that uses a trait declaring a property — a static one
 * included, since `readonly` cannot modify a static — so a memoizing trait
 * cannot live on either tool. Measured on this tree, not recalled from a manual.
 *
 * What the split buys is the rule the whole step turns on: `description()` stays
 * a pure function of the instance, so a golden prompt renders the same bytes on
 * a machine with `rg` installed and one without. A probe reached lazily from
 * inside `description()` would make rendered text a function of whichever shell
 * built it.
 *
 * THE ANSWER COMES FROM A STAT WALK AND NEVER FROM A SUBPROCESS, which is the
 * load-bearing half of this file. `proc_open(['rg', '--version'])` on a host
 * without `rg` emits a PHP warning, and `phpunit.xml` sets `failOnWarning` — so
 * the idiomatic capability probe turns every suite run on a plain container into
 * a red one. That exact hazard is why
 * {@see \SugarCraft\Crush\ClaudeCodeMcpClient::resolveExecutable()} pre-validates
 * a command before spawning it, and this walk is that primitive duplicated on
 * purpose: reaching into an unrelated backend's private method would be a
 * dependency pointing the wrong way, and ~20 lines of stat calls are cheaper
 * than either that or an edit outside this step's file ceiling.
 *
 * Memoized in a static keyed by binary — the house idiom for a probe-once
 * capability, as {@see \SugarCraft\Crush\ToolResult::mosaic()} caches the
 * terminal probe that {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} warms at
 * boot and every later reader (Doctor among them, since audit F-T6) shares —
 * so a launch that walks `app()`, `backend()` and `backendFor()` in sequence
 * still pays for one scan per name, and the second ask is a hash lookup.
 */
trait DetectsCapabilities
{
    /**
     * Binary name => installed, answered by the first ask for that name in the
     * process; every later ask reads this array.
     *
     * @var array<string, bool>
     */
    private static array $hostCapabilities = [];

    /**
     * Is $binary installed and executable?
     *
     * With no second argument the answer is memoized and comes from the
     * process `PATH` — the boot-time question. The `$pathList` form exists so
     * the boundary can be tested at all: pointing the walk at a sandbox
     * directory answers a question about that directory, deterministically, on
     * a host where `rg` may or may not be installed, and without a `putenv()`
     * that would leak into every sibling test in the process. It deliberately
     * does not consult the memo, because the whole point of the seam is an
     * answer derived from a `PATH` the process never had.
     *
     * A name containing a separator is not a PATH lookup at all — it is a path,
     * and is answered as one, exactly as the precedent does.
     */
    public static function capabilityPresent(string $binary, ?string $pathList = null): bool
    {
        if ($pathList !== null) {
            return self::searchPath($binary, $pathList);
        }

        return self::$hostCapabilities[$binary] ??= self::searchPath($binary, (string) getenv('PATH'));
    }

    /**
     * Walk $pathList for an executable file named $binary.
     *
     * `is_file()` and `is_executable()` are both stat calls that FOLLOW
     * symlinks, which is the behaviour wanted: a distribution that ships
     * `/usr/bin/rg` as a link into `/usr/bin/rg.real` is a host with `rg` on it,
     * and an `lstat()` here would report it missing.
     *
     * An empty entry is skipped rather than treated as `.`: POSIX gives an
     * empty PATH element the meaning "the current directory", and answering a
     * capability question from whatever the working directory happens to be at
     * boot is precisely the nondeterminism this concern exists to avoid.
     */
    private static function searchPath(string $binary, string $pathList): bool
    {
        if ($binary === '' || $pathList === '') {
            return false;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        $separator = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        foreach (explode($separator, $pathList) as $dir) {
            if ($dir === '') {
                continue;
            }

            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
