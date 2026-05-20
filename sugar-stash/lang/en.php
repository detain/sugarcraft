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
    'help.keyhints'         => 'tab  switch pane  ·  j/k  move  ·  s  stage/unstage  ·  a  stage all  ·  d  discard  ·  P  diff  ·  space  checkout  ·  c  commit  ·  A  amend  ·  n  new branch  ·  R  refresh  ·  u  undo  ·  M  merge  ·  r  rebase  ·  ?  help  ·  q  quit',

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
    'help.amend'            => 'amend last commit',
    'help.new_branch'       => 'create new branch',
    'help.discard'          => 'discard changes to file',
    'help.diff_viewer'     => 'open diff viewer',

    // Checkout
    'checkout.no_branch'    => 'checkout: no branch selected',

    // Commit
    'commit.prompt'         => 'commit message: ',
    'commit.empty_message'  => 'commit: message cannot be empty',

    // Branch creation
    'branch.prompt'         => 'branch name: ',
    'branch.empty_name'     => 'branch: name cannot be empty',

    // Diff viewer
    'diff.hunk_staged'      => 'hunk staged',
    'diff.navigation_hint'  => 'space: stage hunk  ·  ↑↓: navigate  ·  esc: close',

    // Undo/Redo
    'history.nothing_to_undo' => 'nothing to undo',
    'history.nothing_to_redo' => 'nothing to redo',
    'history.undone'          => 'undone: {op}',

    // Branch delete
    'branch.delete_current'  => 'cannot delete current branch',
    'branch.deleted'         => 'deleted branch {name}',
    'branch.delete_no_select'=> 'delete: no branch selected',

    // Merge
    'merge.prompt'           => 'merge: ',
    'merge.empty_target'     => 'merge: target branch required',
    'merge.success'           => 'merged {branch}',

    // Rebase
    'rebase.prompt'          => 'rebase: [c]ontinue [a]bort [s]kip',
    'rebase.no_rebase'        => 'no rebase in progress',
    'rebase.success'         => 'rebase: {action}',
    'rebase.conflict'        => 'rebase: unresolved conflicts',

    // Help overlay additions
    'help.undo'              => 'undo last operation',
    'help.redo'              => 'redo last undone operation',
    'help.delete_branch'      => 'delete branch',
    'help.merge'             => 'merge branch',
    'help.rebase'            => 'rebase options (when in progress)',
];
