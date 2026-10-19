<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap GIT-1. A canned review prompt rather than a diff generator: the
// prompt closes on the `@diff` mention (scoped to the revision argument when
// there is one) and the mention scanner attaches the change set — one diff
// path, same as typed prose. Mandates "report, do not edit". A command that
// STARTS A TURN (as `/init` does) — see Chat::handleReviewCommand(). Coded
// under Tools like /init files under Memory: a canned prompt's category is
// its domain, not the Session transcript menu it happens to post into.
return BuiltInCommand::new(CommandSpec::new(
    'review',
    Lang::t('cmd.review.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.review.hint'),
))->withHandler('handleReviewCommand');
