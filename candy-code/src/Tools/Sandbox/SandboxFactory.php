<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

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
 * The vocabulary itself lives on the backends' shared `MODE_*` constants and
 * the unknown-value-is-strictest law is kept per backend, so the factory
 * routes and nothing else. The $os parameter exists for the doctor row and
 * the tests: it is the host family the answer is for, and it defaults to the
 * real one.
 */
final class SandboxFactory
{
    /**
     * The sandbox a `bashSandbox` value asks for on $os, or null for `off`.
     *
     * Null and `off` (and `false`) mean no sandbox, `true` is accepted as
     * `on`, and ANY OTHER VALUE IS READ AS THE STRICTEST MODE (`no-network`),
     * never as off — the law {@see Bubblewrap::fromSetting()} was written
     * for, kept exactly, per backend.
     */
    public static function fromSetting(mixed $value, string $os = PHP_OS_FAMILY): ?SandboxBackend
    {
        if ($os === 'Darwin') {
            return Seatbelt::fromSetting($value);
        }

        return Bubblewrap::fromSetting($value);
    }
}
