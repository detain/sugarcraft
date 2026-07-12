<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Shine\Renderer as Markdown;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Pure view function for {@see Chat}. Lays out the conversation
 * scrollback (with each turn rendered through CandyShine) above
 * a fixed input area at the bottom.
 *
 * Rendered shape:
 *
 *   ┌─ SugarCrush ───────────────────────┐
 *   │ user> hello                        │
 *   │ assistant: ## Hi there!             │
 *   │            paragraph of markdown    │
 *   │ user> question                     │
 *   │ assistant: …                        │
 *   ├─────────────────────────────────────┤
 *   │ > █                                 │   ← input area
 *   └─────────────────────────────────────┘
 *
 * The CandyShine renderer is constructed once per call (cheap;
 * just holds a theme reference). Only the assistant's Markdown gets
 * rendered through CandyShine; the raw user/system turns and the
 * in-progress input are run through {@see Sanitize::untrusted()}
 * first (see the render methods for why).
 */
final class Renderer
{
    public static function render(Chat $chat): string
    {
        $body = self::renderHistory($chat->history);
        $input = self::renderInput($chat);
        $status = $chat->inFlight ? '⠴ thinking…' : 'Enter to send · Esc / ^C to quit';

        $shell = Style::new()
            ->border(Border::rounded())
            ->padding(1, 2)
            ->render($body);

        return $shell . "\n" . $input . "\n" . $status;
    }

    /**
     * @param list<Message> $history
     */
    private static function renderHistory(array $history): string
    {
        if ($history === []) {
            return '_(empty conversation — type a question and press Enter)_';
        }
        $md = new Markdown();
        $blocks = [];
        foreach ($history as $msg) {
            // Defense-in-depth (candy-buffer #1362): User and System content is
            // untrusted and reaches the terminal wire verbatim. A raw ESC would
            // desync the frame-diff line model or forge SGR that escapes the
            // renderer's own styling (e.g. a smuggled reset() breaking out of the
            // system FAINT wrapper); NUL/BEL/DEL garble or beep the terminal.
            // These turns are plain text with no legitimate SGR, so untrusted()
            // (full ANSI + C0/DEL/lone-C1 strip) is correct — the Assistant path
            // stays raw because CandyShine emits legitimate, already-processed SGR.
            $blocks[] = match ($msg->role) {
                Role::User      => Ansi::sgr(1, 36) . "user>" . Ansi::reset() . " " . Sanitize::untrusted($msg->content),
                Role::Assistant => Ansi::sgr(1, 35) . "assistant" . Ansi::reset() . "\n" . trim($md->render($msg->content)),
                Role::System    => Ansi::sgr(Ansi::FAINT) . "system: " . Sanitize::untrusted($msg->content) . Ansi::reset(),
            };
        }
        return implode("\n\n", $blocks);
    }

    private static function renderInput(Chat $chat): string
    {
        $cursor = $chat->inFlight ? '' : '█';
        // The in-progress input buffer is untrusted keystroke data (e.g. a
        // bracketed-paste dump can smuggle ESC/C0/DEL). Strip it before it hits
        // the terminal so a paste can't inject control sequences at draw time.
        $body = "> " . Sanitize::untrusted($chat->inputBuf) . $cursor;
        return Style::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->render($body);
    }
}
