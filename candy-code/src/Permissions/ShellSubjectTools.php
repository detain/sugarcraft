<?php

declare(strict_types=1);

namespace SugarCraft\Code\Permissions;

use SugarCraft\Code\Tools\BuiltIn\PtyRun;

/**
 * The canonical roster of tools whose subject IS a shell command line.
 *
 * Three of the six sites below were routed through this class by fix lane
 * f-perms-sec ({@see PermissionRule}, {@see PermissionGate}, {@see
 * SessionPermissionMemo}). The other three keep literals — SafetyClassifier's
 * `match` arm cannot interpolate a constant, and Renderer/Runtime sit in
 * files that lane may not edit — until they adopt it, the completeness test
 * pins their literals against this roster.
 *
 * rv-php-2 (crush-review-1014): the pair `Bash` + `PtyRun` was hand-copied
 * across six production sites — permission matching ({@see PermissionRule}),
 * the rm-rf breaker ({@see PermissionGate}), the classifier dispatch
 * ({@see SafetyClassifier}), the argument-scoped memo ({@see
 * SessionPermissionMemo}), the modal's subject lines (Renderer) and the
 * write-capable roster (Runtime). A seventh shell tool added without all six
 * updates silently denies restrictive rules binding it (or binds allow rules
 * too widely). This class is the single source; the sites read it, and
 * tests/Permissions/ShellSubjectToolsCompletenessTest.php greps every site —
 * including the three still holding literals — so a divergence goes red at CI
 * rather than at review time.
 *
 * WHY `PtyRun` IS HERE AT ALL: it runs `/bin/sh -c` on its `command`, so a
 * restrictive rule naming either shell tool binds both by symmetry (see
 * SHELL_SUBJECT_TOOLS in {@see PermissionRule} for the full argument); that
 * symmetry is only safe while it is mechanically enforced, which is
 * precisely what this roster plus its completeness guard enforce. Its
 * `steps` are a SECOND surface the roster alone does not describe: decoded
 * keystrokes typed INTO that shell (or into any program the command
 * starts), so `vim` under a grant can be driven to `:!/bin/sh` — the step
 * texts below are what every matcher, breaker and classifier must read in
 * addition to `command`. (ISS-001, crush-review-1014: an earlier revision of
 * this docblock claimed the STEPS were what `/bin/sh -c` runs — false. The
 * steps never reach the spawn argv; they are written to the pty after it.)
 */
final class ShellSubjectTools
{
    /**
     * The shell-subject tool names, in display order.
     *
     * @var list<string>
     */
    public const NAMES = ['Bash', 'PtyRun'];

    /**
     * Does $toolName present a shell command line as its permission subject?
     */
    public static function covers(string $toolName): bool
    {
        return \in_array($toolName, self::NAMES, true);
    }

    /**
     * The decoded keystrokes a `PtyRun` call types into its terminal, one
     * string per step, in order — `[]` for `Bash`, for `PtyRun` without
     * steps, and for any malformed step (fail-soft on SHAPE: a step that
     * cannot be read as text simply does not add a reading; refusing to read
     * the rest would only blind the matchers further, and the tool's own
     * argument parse rejects malformed calls before any of this runs).
     *
     * The decode is {@see \SugarCraft\Code\Tools\BuiltIn\PtyRun::decode()} —
     * the SAME function the driver uses to turn `send` into bytes — so the
     * matchers judge exactly what the terminal will receive, `<Enter>` and
     * all. The tool is imported lazily by name in stepTexts() so this file
     * keeps a zero-import surface for the layering purists; the behaviour is
     * the driver's, pinned by ShellSubjectToolsCompletenessTest.
     *
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public static function stepTexts(string $toolName, array $arguments): array
    {
        if ($toolName !== 'PtyRun') {
            return [];
        }

        $steps = $arguments['steps'] ?? null;
        if (!\is_array($steps)) {
            return [];
        }

        $texts = [];
        foreach ($steps as $step) {
            $send = \is_array($step)
                ? ($step['send'] ?? null)
                : (\is_string($step) ? $step : null);
            if (!\is_string($send) || $send === '') {
                continue;
            }
            $decoded = PtyRun::decode($send);
            if ($decoded !== '') {
                $texts[] = $decoded;
            }
        }

        return $texts;
    }

    /**
     * Every text a permission decision must judge for a shell-subject call:
     * the `command`, plus each decoded `PtyRun` step. Bash calls return just
     * `[command]` (when it is a non-empty string), so every consumer that
     * loops these texts keeps its byte-exact Bash behaviour.
     *
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    public static function judgedShellTexts(string $toolName, array $arguments): array
    {
        if (!self::covers($toolName)) {
            return [];
        }

        $command = $arguments['command'] ?? null;
        $texts = \is_string($command) && $command !== '' ? [$command] : [];

        return array_merge($texts, self::stepTexts($toolName, $arguments));
    }

    private function __construct()
    {
    }
}
