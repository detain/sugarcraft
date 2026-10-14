<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

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
 * WHY `PtyRun` IS HERE AT ALL: it runs `/bin/sh -c` on the supplied steps,
 * so a restrictive rule naming either shell tool binds both by symmetry
 * (see SHELL_SUBJECT_TOOLS in {@see PermissionRule} for the full argument);
 * that symmetry is only safe while it is mechanically enforced, which is
 * precisely what this roster plus its completeness guard enforce.
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

    private function __construct()
    {
    }
}
