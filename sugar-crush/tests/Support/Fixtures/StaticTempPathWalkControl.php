<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support\Fixtures;

/**
 * A DELIBERATE OFFENDER, IN SCOPE, SO THE STATIC-PATH WALK HAS A REAL FILE TO
 * FIND. Nothing calls this class and nothing may.
 *
 * WHY IT EXISTS. {@see \SugarCraft\Crush\Tests\Support\ProcessUniqueTempNameTest}'s
 * second census asserts an ABSENCE over every `.php` file under `tests/`,
 * `src/` and `bin/sugarcrush`, and rule 15 says an absence is worth nothing
 * unless something proves the instrument still works. That job used to be done
 * by `src/Hooks/BuiltIn/AuditHook.php`, whose production default really was one
 * fixed name on the machine's temp root — and E328 fixed it, which is the one
 * outcome that leaves an absence-census with no positive input at all.
 *
 * A synthetic string handed straight to the scanner (there are ten of those, in
 * that file's own liveness helper) proves the MATCHER works. It does not prove
 * the WALK works: that `filesInScope()` still enumerates real files, that
 * `readOrFail()` still returns their bytes, and that the two are still wired to
 * the matcher. This file is the input for that, and unlike a real site it can
 * never be "fixed" out from under the census by somebody improving production
 * code.
 *
 * IT IS NEVER EXECUTED, and that is not an accident of nobody having got round
 * to it. The method below is private, the constructor is private, and the class
 * is not referenced from anywhere outside a doc-block. If a future reader wants
 * to call it, the answer is no: the whole point of the body is that the path in
 * it is a shared name that a running process must never write to.
 *
 * @codeCoverageIgnore
 */
final class StaticTempPathWalkControl
{
    private function __construct() {}

    /**
     * The exact shape E298 took: a fully static temp path bound in one
     * statement and written in another.
     */
    private function neverCalled(): void
    {
        $path = sys_get_temp_dir() . '/sugar-crush-static-path-walk-control.log';
        file_put_contents($path, "never\n", FILE_APPEND);
    }
}
