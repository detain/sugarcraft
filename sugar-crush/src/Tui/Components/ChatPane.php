<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Messages\Message;

final class ChatPane
{
    public static function render(App $a, int $cols, int $rows): string
    {
        $width = max(40, $cols - 80);  // Remaining width after sidebars

        $messages = $a->messages;
        if ($messages === []) {
            $body = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('Welcome to CandyCrush! Start typing to chat...');
        } else {
            $lines = [];
            foreach ($messages as $msg) {
                $lines[] = self::formatMessage($msg);
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::normal()->withTitle(' chat '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Chat
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($body);
    }

    private static function formatMessage(Message $msg): string
    {
        // Surface the concrete message type (e.g. UserMessage) so the
        // transcript shows provenance, not just the role string.
        $shortName = (new \ReflectionClass($msg))->getShortName();
        $tag = Style::new()->foreground(Color::hex('#7d6e98'))->render('[' . $shortName . ']');
        $role = Style::new()->bold()->foreground(Color::hex('#fde68a'))->render($msg->role() . ':');
        $content = Style::new()->foreground(Color::hex('#c5b6dd'))->render($msg->content());
        return "$tag $role $content";
    }
}
