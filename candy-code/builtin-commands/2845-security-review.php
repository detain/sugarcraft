<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;

// Roadmap GIT-1. The security-shaped sibling of `/review`: same `@diff`
// mention close and the same "report, do not edit" mandate, but the body
// walks trust boundaries — injection, SSRF, authz regressions, leaked
// secrets, unbounded sizes. A command that STARTS A TURN — see
// Chat::handleSecurityReviewCommand(). Coded under Tools like /init files
// under Memory: a canned prompt's category is its domain, not the Session
// transcript menu it happens to post into.
//
// C-WEB-2: served by the same ReviewHostCommand body as `/review` — the
// class reads the spelling off the draft's command word.
return BuiltInCommand::new(CommandSpec::new(
    'security-review',
    Lang::t('cmd.security-review.description'),
    Lang::t('cmd.category.tools'),
    argumentHint: Lang::t('cmd.security-review.hint'),
))->withHandler('handleSecurityReviewCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ReviewHostCommand::class);
