<?php

declare(strict_types=1);

// Child for SrcExitCensusTest::testTheFallbackDiesWithConventionalSignalStatus().
// Run under `php -d disable_functions=posix_kill,posix_getpid` so the
// posix re-raise arm of handleRescueSignal() is unavailable and the
// exit(128 + signo) fallback must fire. Exits with the conventional
// status; must NEVER print REACHED_AFTER.

require __DIR__ . '/../../../vendor/autoload.php';

\SugarCraft\Vcr\Cli\RecordCommand::handleRescueSignal((int) $argv[1]);

fwrite(STDOUT, "REACHED_AFTER\n");
exit(7);
