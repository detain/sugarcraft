<?php

declare(strict_types=1);

/**
 * S-44 (finding 021) child: drives the Windows-only ACL grant arm on a host
 * where icacls cannot answer, twice, and reports how many disclosures fired.
 * The decline path is platform code Linux never reaches through the public
 * API, so it is invoked by reflection; error_log lands on the captured
 * stderr the parent reads.
 */

require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\Readline\History\FileHistory;

$grant = new ReflectionMethod(FileHistory::class, 'windowsOwnerOnlyGrant');
$grant->setAccessible(true);
$note = new ReflectionProperty(FileHistory::class, 'aclDeclineNote');
$note->setAccessible(true);

$grant->invoke(null, '/nonexistent-history-file.acl-probe');
$first = $note->getValue();
$grant->invoke(null, '/nonexistent-history-file.acl-probe-2');
$second = $note->getValue();

printf("NOTESAME|%s\nDONE\n", $first === $second && $first !== null ? 'yes' : 'no');
exit(0);
