<?php

declare(strict_types=1);

/**
 * Tab-bar pattern — keep multiple panes addressable by a single
 * cursor index, render only the active one.
 *
 *   php examples/tabs.php
 *
 * Tab to advance, Shift+Tab to step back. 'q' to quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

final class Tabs implements Model
{
    /** @param list<string> $names @param list<string> $bodies */
    public function __construct(
        public readonly array $names,
        public readonly array $bodies,
        public readonly int $cursor = 0,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        return match (true) {
            $msg->type === KeyType::Tab && $msg->alt
                => [new self($this->names, $this->bodies, ($this->cursor - 1 + count($this->names)) % count($this->names)), null],
            $msg->type === KeyType::Tab
                => [new self($this->names, $this->bodies, ($this->cursor + 1) % count($this->names)), null],
            default => [$this, null],
        };
    }

    public function view(): string
    {
        $bar = '';
        foreach ($this->names as $i => $name) {
            $bar .= $i === $this->cursor
                ? "\x1b[7m $name \x1b[0m "
                : " $name  ";
        }
        return rtrim($bar) . "\n\n" . $this->bodies[$this->cursor] . "\n\n(Tab / Shift+Tab to switch, q to quit)\n";
    }
}

(new Program(new Tabs(
    ['Inbox', 'Drafts', 'Sent'],
    [
        "Inbox: 12 unread.\n  • Mom: dinner Sunday?\n  • Bills due Tue\n  • PSA: candy is good",
        "Drafts: 3.\n  • Letter to grandma\n  • Cookie recipe\n  • Resignation",
        "Sent: 47 messages.",
    ],
)))->run();
