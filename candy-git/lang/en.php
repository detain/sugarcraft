<?php

/**
 * English (default) translations for candy-git.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Git errors
    'git.spawn_failed' => 'git: failed to spawn',
    'git.error'        => 'git: {stderr}',
    'git.timeout'      => 'git: command timed out after {seconds}s',
    'git.unsafe_path'  => 'git: unsafe worktree path: {path}',
    'git.unsafe_merge_driver' => 'git: the repository defines a merge driver that cannot be safely disabled; merge refused',
    'git.unsafe_filter_driver' => 'git: the repository defines a filter driver; strict filter mode refused the command',

    // CLI errors
    'cli.not_a_repo'   => 'candy-git: not a git repository: {cwd}',
    'cli.unknown_flag' => 'candy-git: unknown argument: {flag}',
    'cli.no_git'       => 'candy-git: git is unavailable -- install git and make sure it is on PATH',
    'cli.old_git'      => 'candy-git: git {version} is too old -- version {min} or newer is required',
    'cli.bare_repo'    => 'candy-git: {cwd} is a bare repository without a work tree -- run candy-git from a checked-out working directory',
    'cli.needs_tty'    => 'candy-git: needs an interactive terminal (stdout is not a tty)',

    // UI labels
    'ui.error_prefix'  => 'error: ',
    // Chrome titles routed through Lang (audit A16).
    'ui.title.app'    => ' CandyGit ',
    'ui.pane.status'  => ' status ',
    'ui.pane.branches'=> ' branches ',
    'ui.pane.log'     => ' log ',
    'ui.title.help'   => ' Help ',
    'ui.title.rebase' => ' rebase ',
    'ui.title.cherry' => ' cherry-pick ',
    'ui.title.stashes'=> ' stashes ',
    'ui.title.worktrees'=> ' worktrees ',
    'ui.title.interactive_rebase'=> ' interactive rebase ',
    'ui.group.general'=> 'General:',
    'ui.label.path'   => 'path: ',
    'ui.label.branch' => 'branch: ',
    'ui.count.n'      => 'n = ',
    'ui.diff_prefix'  => ' diff: ',
    'viewport.above'  => '+{n} above',
    'viewport.below'  => '+{n} below',

    // Empty-state messages
    'status.clean'          => 'clean working tree',
    'branches.empty'        => '(no branches)',
    'log.empty'             => '(empty log)',

    // Key hints
    'help.keyhints'         => 'S  stash  ·  V  cherry-pick  ·  i  rebase-i  ·  w  worktrees  ·  tab  switch pane  ·  j/k  move  ·  s  stage/unstage  ·  a  stage all  ·  d  discard  ·  P  diff  ·  space  checkout  ·  c  commit  ·  A  amend  ·  n  new branch  ·  R  refresh  ·  u  undo  ·  M  merge  ·  r  rebase  ·  ?  help  ·  q  quit',

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
    'help.discard'          => 'discard changes to file (press twice)',
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
    'history.redone'          => 'redone: {op}',
    'history.op.stage'        => 'stage',
    'history.op.unstage'      => 'unstage',
    'history.op.discard'      => 'discard',
    'history.op.commit'       => 'commit',
    'history.op.checkout'     => 'branch switch',
    'history.op.createBranch' => 'branch creation',
    'history.op.deleteBranch' => 'branch deletion',
    'history.op.restoreBranch'=> 'branch restore',
    'history.op.stageAll'     => 'stage all',
    'history.op.unstageAll'   => 'unstage all',
    'history.op.stagePatch'   => 'hunk stage',
    'history.op.unstagePatch' => 'hunk unstage',
    'history.op.merge'        => 'merge',
    'history.op.amend'        => 'amend',
    'history.op.reset'        => 'reset',
    'history.unavailable'     => 'history: unavailable (app constructed without a HistoryManager)',
    'history.cannot_undo'     => 'history: cannot undo {op} — the data it would restore is gone',
    'history.unknown_op'      => 'history: unknown operation {op}',

    // Branch delete
    'branch.delete_current'  => 'cannot delete current branch',
    'branch.deleted'         => 'deleted branch {name}',
    'branch.delete_no_select'=> 'delete: no branch selected',

    // Merge
    'merge.prompt'           => 'merge: ',
    'merge.empty_target'     => 'merge: target branch required',
    'merge.success'           => 'merged {branch}',
    'merge.conflict'          => 'could not merge {branch}: unresolved conflicts',
    'confirm.discard'         => 'discard {path}: press d again to discard (unrecoverable)',
    'confirm.delete_branch'   => 'delete branch {name}: press D again to delete',
    'confirm.stash_drop'      => 'drop stash {ref}: press d again to drop (unrecoverable)',

    // Rebase
    'rebase.prompt'          => 'rebase: [c]ontinue [a]bort [s]kip',
    'rebase.no_rebase'        => 'no rebase in progress',
    'rebase.merge_state'      => 'a conflicted merge is in progress - resolve conflicts in the status pane',
    'rebase.cherry_prompt'    => 'cherry-pick: [c]ontinue [a]bort',
    'rebase.success'         => 'rebase: {action}',
    'rebase.conflict'        => 'rebase: unresolved conflicts',
    'rebase.continue'        => 'continue',
    'rebase.abort'          => 'abort',
    'rebase.skip'           => 'skip',
    'rebase.cancel'          => 'cancel',

    // Help overlay additions
    'help.undo'              => 'undo last operation',
    'help.redo'              => 'redo last undone operation',
    'help.delete_branch'      => 'delete branch',
    'help.merge'             => 'merge branch',
    'help.rebase'            => 'rebase options (when in progress)',
    'help.stash'            => 'open stash manager',
    'help.cherry_pick'      => 'cherry-pick a commit',
    'help.worktrees'        => 'open worktrees manager',
    'help.interactive_rebase'=> 'interactive rebase (preview actions)',
    'help.viewport'         => 'scroll / jump panes and hunks',

    // Stash
    'stash.empty'             => '(no stashes)',
    'stash.apply'             => 'apply stash',
    'stash.drop'             => 'drop stash',
    'stash.applied'          => 'applied {ref}',
    'stash.dropped'          => 'dropped {ref}',

    // Cherry-pick
    'cherry_pick.prompt'      => 'cherry-pick: ',
    'cherry_pick.empty_ref'  => 'cherry-pick: commit ref required',
    'cherry_pick.success'    => 'cherry-picked {ref}',

    // Worktrees
    'worktree.empty'          => '(no worktrees)',
    'worktree.empty_path'    => 'worktree: path required',
    'worktree.add_prompt'   => 'add worktree:',
    'worktree.confirm_add'  => 'confirm add',
    'worktree.added'        => 'added worktree {path}',
    'worktree.remove_confirm' => 'remove worktree {path}?',
    'worktree.confirm_remove'=> 'confirm remove',
    'worktree.removed'      => 'removed worktree {path}',
    'worktree.add'           => 'add worktree',
    'worktree.remove'        => 'remove worktree',
    'worktree.branch_head_default'=> '(from current HEAD)',

    // Interactive rebase
    'rebase_i.select_count'  => 'select number of commits to rebase:',
    'rebase_i.confirm_count' => 'confirm (enter)',
    'rebase_i.no_commits'   => '(no commits)',
    'rebase_i.todo_list'    => 'todo list:',
    'rebase_i.cycle_action'  => 'cycle action (preview)',
    'rebase_i.drop'          => 'drop commit (preview)',
    'rebase_i.preview_only'  => 'preview only — not yet executed',
    'rebase_i.count_positive' => 'count must be > 0',
];
