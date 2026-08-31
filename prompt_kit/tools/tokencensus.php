<?php
declare(strict_types=1);
// Executable-token census: strip whitespace/comments/doc-blocks, hash the rest.
$src = (string) file_get_contents($argv[1]);
$out = [];
foreach (token_get_all($src) as $t) {
    if (is_array($t)) {
        if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out[] = token_name($t[0]) . '|' . $t[1];
    } else { $out[] = $t; }
}
echo count($out), ' tokens  md5=', md5(implode("\x00", $out)), "  ", $argv[1], "\n";
