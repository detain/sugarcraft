<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Test-only resolver for the git binary (finding 115).
 *
 * The old gates hardcoded /usr/bin/git, so on legitimate hosts where git
 * lives elsewhere on PATH (nix, /usr/local) the deepest real-wire suites
 * skipped silently — a vacuous green hiding the very coverage they exist
 * to provide. Every gate AND every exec now consults this one resolver,
 * so the check and the use can never diverge.
 */
final class GitBinary
{
    private static ?string $path = null;
    private static bool $probed = false;

    /** The first git on PATH, or null when the host carries none. */
    public static function path(): ?string
    {
        if (!self::$probed) {
            self::$probed = true;
            $found = @\shell_exec('command -v git 2>/dev/null');
            $candidate = \is_string($found) ? \trim($found) : '';
            self::$path = ($candidate !== '' && \is_executable($candidate)) ? $candidate : null;
        }

        return self::$path;
    }

    /**
     * PATH-resolved git binary; marks the running test skipped when the
     * host carries none (git is a documented host requirement in README).
     */
    public static function require(): string
    {
        $path = self::path();
        if ($path === null) {
            TestCase::markTestSkipped('e2e pins require a git binary on PATH (host requirement, see README)');
        }

        return $path;
    }
}
