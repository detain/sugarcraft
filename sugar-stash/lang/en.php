<?php

/**
 * English (default) translations for sugar-stash.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Git errors
    'git.spawn_failed' => 'git: failed to spawn',
    'git.error'        => 'git: {stderr}',

    // CLI errors
    'cli.not_a_repo'   => 'sugar-stash: not a git repository (no .git in {cwd})',

    // UI labels
    'ui.error_prefix'  => 'error: ',

    // Empty-state messages
    'status.clean'          => 'clean working tree',
    'branches.empty'        => '(no branches)',
    'log.empty'             => '(empty log)',

    // Key hints
    'help.keyhints'         => 'tab  switch pane  ·  j/k  move  ·  s  stage/unstage  ·  R  refresh  ·  q  quit',
];
