<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

/*
 * Roadmap GAP-GIT-03 (C-AGT-6.a) — `/commit`: stage everything the work tree
 * changed and commit it, drafting the message on the title model when the user
 * gave none. A `Text` command: the argument, if any, IS the commit message,
 * passed through untouched.
 *
 * No `paletteAction` arm exists for it and none is minted here: like `/pr` and
 * `/review` it is honestly a write, the typed command IS the intent, and a
 * palette row would only tempt an unintended whole-tree commit. It belongs to
 * no read-only roster — committing is a write, refused in a read-only window —
 * and the mid-turn refusal every non-`/btw` command gets applies unchanged.
 */
return BuiltInCommand::new(CommandSpec::new(
    'commit',
    Lang::t('cmd.commit.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.commit.hint'),
))->withHandler('handleCommitCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\CommitHostCommand::class);
