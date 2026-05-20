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
    'help.keyhints'         => 'tab  switch pane  ·  j/k  move  ·  s  stage/unstage  ·  a  stage all  ·  space  checkout  ·  c  commit  ·  R  refresh  ·  ?  help  ·  q  quit',

    // Help overlay
    'help.context_general'  => 'show this help',
    'help.quit'             => 'quit',
    'help.refresh'          => 'refresh',
    'help.switch_pane'      => 'switch pane',
    'help.move_cursor'      => 'move cursor',
    'help.close_help'       => 'close',
    'help.pane_navigation'  => 'Navigation:',
    'help.pane_status'      => 'Status pane:',
    'help.pane_branches'    => 'Branches pane:',
    'help.stage_single'    => 'stage / unstage file',
    'help.stage_all'       => 'stage all files',
    'help.checkout'         => 'checkout branch',
    'help.commit'           => 'commit (opens message input)',

    // Checkout
    'checkout.no_branch'    => 'checkout: no branch selected',

    // Commit
    'commit.prompt'         => 'commit message: ',
    'commit.empty_message'  => 'commit: message cannot be empty',
];
