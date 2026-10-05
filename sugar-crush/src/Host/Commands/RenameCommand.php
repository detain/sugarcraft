<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Host\TitleService;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Session\TitleSource;

/**
 * `/rename` — name the current session (roadmap P-A4; its logic moved out of
 * `Chat` in roadmap O-2h):
 *
 *   /rename <title>  — the user's title, recorded as {@see TitleSource::User},
 *                      so no generated title ever replaces it
 *   /rename          — the TUI's inline title editor, prefilled (a screen, so
 *                      a headless session answers it as client-only)
 *   /rename --auto   — drops the current title and asks the title model for a
 *                      new one ({@see regenerate()})
 */
final class RenameCommand implements HostCommand
{
    /** The `/rename` argument that asks for a generated title. */
    public const AUTO_FLAG = '--auto';

    public function run(CommandContext $context, string $text): CommandResult
    {
        if ($context->sessionStore === null) {
            return CommandResult::reply($text, Lang::t('host.rename.no_store'));
        }

        if ($context->sessionId === null) {
            return CommandResult::reply($text, Lang::t('host.rename.no_session'));
        }

        $argument = CommandText::argument($text);
        if ($argument === '') {
            return CommandResult::new()->withEffect(CommandEffect::openTitleEditor());
        }

        try {
            [$response, $effects] = $argument === self::AUTO_FLAG
                ? self::regenerate($context)
                : self::userTitle($context, $argument);
        } catch (\Throwable $e) {
            return CommandResult::reply($text, Lang::t('host.rename.error', ['error' => $e->getMessage()]));
        }

        $result = CommandResult::reply($text, $response);
        foreach ($effects as $effect) {
            $result = $result->withEffect($effect);
        }

        return $result;
    }

    /**
     * Name the session as the USER: sanitised like every other title, written
     * as {@see TitleSource::User}, and latched with that source so a generated
     * title in flight can never displace it. A title that sanitises to nothing
     * is refused rather than stored: a blank is the "back to automatic"
     * request, which {@see regenerate()} answers.
     *
     * @return array{0: string, 1: list<CommandEffect>} the line to report and the rename
     */
    public static function userTitle(CommandContext $context, string $raw): array
    {
        $store = $context->sessionStore;
        $sessionId = $context->sessionId;
        if ($store === null || $sessionId === null) {
            throw new \LogicException('A rename needs a session store and a session.');
        }

        $title = TitleService::sanitizeTitle($raw);
        if ($title === '') {
            return [Lang::t('host.rename.blank'), []];
        }

        $store->renameSession($sessionId, $title, TitleSource::User);

        return [Lang::t('host.rename.renamed', ['title' => $title]), [CommandEffect::renameSession($title, TitleSource::User)]];
    }

    /**
     * Drop the current title and ask the title model for a new one — the
     * answer to `/rename --auto` and to a blank inline rename (carried from
     * W2-f: a blank rename resets the name so the auto-titler can name the
     * session again).
     *
     * The store row is made unnamed first, because the titler's write is
     * conditional on exactly that: it never overwrites a name, and a user's
     * name least of all. The in-memory name and its source are cleared with it,
     * so whatever comes back is latched — unless the user names the session
     * again while the request is in flight, which both halves still honour.
     *
     * With no title model there is nothing to regenerate with, and the name is
     * left alone rather than cleared into a state nothing will ever fill. A
     * session with no user turn yet is cleared and left to its first reply.
     *
     * @return array{0: string, 1: list<CommandEffect>} the line to report and the changes
     */
    public static function regenerate(CommandContext $context): array
    {
        $store = $context->sessionStore;
        $sessionId = $context->sessionId;
        if ($store === null || $sessionId === null) {
            throw new \LogicException('A rename needs a session store and a session.');
        }

        if ($context->titleBackend === null) {
            return [Lang::t('host.rename.no_title_model'), []];
        }

        $store->clearSessionName($sessionId);
        $effects = [CommandEffect::renameSession(null, null)];

        $call = $context->titleService()->regenerateCall(
            $context->titleBackend,
            $store,
            $sessionId,
            $context->history,
        );
        if ($call === null) {
            return [Lang::t('host.rename.cleared'), $effects];
        }

        // The answer is a store write the titler makes itself; the TUI latches
        // it from the SessionTitledMsg the call resolves with. Nothing to show.
        $effects[] = CommandEffect::async($call);

        return [Lang::t('host.rename.asking'), $effects];
    }
}
