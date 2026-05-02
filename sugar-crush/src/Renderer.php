<?php

declare(strict_types=1);

namespace CandyCore\Crush;

use CandyCore\Shine\Renderer as Markdown;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;

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
 * just holds a theme reference). User turns stay raw — only the
 * assistant's Markdown gets rendered.
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
            $blocks[] = match ($msg->role) {
                Role::User      => "\x1b[1;36muser>\x1b[0m " . $msg->content,
                Role::Assistant => "\x1b[1;35massistant\x1b[0m\n" . trim($md->render($msg->content)),
                Role::System    => "\x1b[2msystem: " . $msg->content . "\x1b[0m",
            };
        }
        return implode("\n\n", $blocks);
    }

    private static function renderInput(Chat $chat): string
    {
        $cursor = $chat->inFlight ? '' : '█';
        $body = "> " . $chat->inputBuf . $cursor;
        return Style::new()
            ->border(Border::normal())
            ->padding(0, 1)
            ->render($body);
    }
}
