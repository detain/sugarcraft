<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;

/**
 * Which jail a host answers the `bashSandbox` setting with.
 *
 * One OS table, one place: Darwin runs the Seatbelt backend (macOS ships no
 * bubblewrap; {@see Seatbelt} is its native equivalent), every other family
 * runs {@see Bubblewrap} — whose own probe refuses Linux-only work on Windows
 * and on any host without bwrap, so an unmapped OS keeps the standing
 * fail-closed refusal rather than inventing a third behaviour. (Windows is
 * left on the bubblewrap answer deliberately: the honest answer there is "the
 * configured jail cannot start", and a command must still refuse rather than
 * run unsandboxed — see the class doc-block of {@see Bubblewrap} on why a
 * configured-but-dead jail fails while an absent setting does not.)
 *
 * THE BUBBLEWRAP ARM IS BUILT, NOT JUST CHOSEN. Two launch facts belong to the
 * jail rather than to any one command, and this is the one place both are
 * available: the user-tier {@see NESTED_USERNS_KEY} (C-TOOL-4.c — the flag pair
 * that stops a jailed command from creating its own user namespace), and the
 * `--config` file {@see LaunchContext::configPathOverride()} named, which the
 * jail re-binds read-only when it sits inside the writable root. They ride the
 * backend as instance state because the spawn sites are three — `Bash`'s
 * capture, `PtyRun`'s pty argv prefix and the probe — and a fact threaded
 * through only one of them is a fact that jail skips. Seatbelt carries neither:
 * `sandbox-exec` has no user namespaces to block, and its own config-file
 * re-bind is a profile change, named as a seam in C-TOOL-4.c's report.
 *
 * The vocabulary itself lives on the backends' shared `MODE_*` constants and
 * the unknown-value-is-strictest law is kept per backend, so the factory
 * routes, builds the jail, and nothing else. The $os parameter exists for the
 * doctor row and the tests: it is the host family the answer is for, and it
 * defaults to the real one.
 */
final class SandboxFactory
{
    /**
     * The user-tier bool that lets a jailed command create fresh user
     * namespaces, dropping {@see Bubblewrap::HARDENED_FLAGS}. Named here as
     * well as in the settings schema because it is read at jail-build time.
     */
    public const NESTED_USERNS_KEY = 'bashSandboxNestedUserns';

    /**
     * The sandbox a `bashSandbox` value asks for on $os, or null for `off`.
     *
     * Null and `off` (and `false`) mean no sandbox, `true` is accepted as
     * `on`, and ANY OTHER VALUE IS READ AS THE STRICTEST MODE (`no-network`),
     * never as off — the law {@see Bubblewrap::fromSetting()} was written
     * for, kept exactly, per backend.
     *
     * $userConfig is the user-tier settings the jail is built under; null reads
     * them through {@see Bootstrap::readUserConfig()} — the same single source
     * `Bash::fromCatalog()` reads `bashSandbox` from, so the two keys cannot
     * disagree about which launch they describe. Only a literal `true` opts in:
     * a junk value must never be the typo that removes a security control, the
     * reading direction {@see Bubblewrap::fromSetting()} was written for.
     *
     * @param ?array<string, mixed> $userConfig
     */
    public static function fromSetting(
        mixed $value,
        string $os = PHP_OS_FAMILY,
        ?array $userConfig = null,
    ): ?SandboxBackend {
        if ($os === 'Darwin') {
            return Seatbelt::fromSetting($value);
        }

        $bubblewrap = Bubblewrap::fromSetting($value);
        if ($bubblewrap === null) {
            return null;
        }

        $config = $userConfig ?? Bootstrap::readUserConfig();
        $bubblewrap = $bubblewrap->withNestedUserns(($config[self::NESTED_USERNS_KEY] ?? null) === true);

        $override = LaunchContext::configPathOverride();

        return $override === null || $override === ''
            ? $bubblewrap
            : $bubblewrap->withProtectedPaths([$override]);
    }
}
