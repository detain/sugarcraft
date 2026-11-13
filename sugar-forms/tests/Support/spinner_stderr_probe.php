<?php

declare(strict_types=1);

/**
 * Forked-child stderr probe for ActionSpinner (sugar-forms-087).
 *
 * The stray failure line the forked child writes to STDERR cannot be
 * captured in-process (the child duplicates fd 2 at fork time), so the
 * owning test spawns THIS script and reads its pipes: the child's labeled
 * line arrives on stderr, the parent's rethrow summary on stdout.
 */

require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\Forms\ActionSpinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;

$spinner = ActionSpinner::new()
    ->withTitle('failing')
    ->withStyle(SpinnerStyle::line())
    ->withAction(static function (): void {
        throw new \RuntimeException('boom');
    });

try {
    $spinner->run();
    fwrite(STDOUT, "PARENT: returned without throwing\n");
    exit(0);
} catch (\RuntimeException $e) {
    fwrite(STDOUT, 'PARENT: ' . $e->getMessage() . "\n");
    exit(7);
}
