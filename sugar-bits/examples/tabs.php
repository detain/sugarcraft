<?php

declare(strict_types=1);

/**
 * Tabs — interactive tabbed panel.
 *
 *   Tab / Shift+Tab   navigate tabs
 *   1-9               jump to tab
 *   q                 quit
 *
 *   php examples/tabs.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Bits\Tabs\Tabs;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Sprinkles\Style;

$labels = ['Home', 'Profile', 'Settings', 'About'];

$tabs = Tabs::new($labels, 60)
    ->withActiveStyle(Style::new()->bold()->foreground(\SugarCraft\Core\Util\Color::ansi(14)))
    ->withInactiveStyle(Style::new()->foreground(\SugarCraft\Core\Util\Color::ansi(8)));

[$tabs, ] = $tabs->focus();

$model = new class($tabs, $labels) implements Model {
    public function __construct(
        private readonly Tabs $tabs,
        private readonly array $labels,
    ) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        [$tabs, ] = $this->tabs->update($msg);
        assert($tabs instanceof Tabs);
        return [new self($tabs, $this->labels), null];
    }

    public function view(): string
    {
        $tabBar = $this->tabs->view();
        $active = $this->tabs->active();
        $content = match ($active) {
            0 => "  Welcome home! This is the Dashboard content.",
            1 => "  User profile — edit your details here.",
            2 => "  Application settings and preferences.",
            3 => "  About SugarCraft v1.0 — TUI component library.",
            default => "  Tab content.",
        };
        return "\n{$tabBar}\n\n{$content}\n\n  Tab/Shift+Tab to navigate  ·  1-9 to jump  ·  q to quit\n";
    }
};

(new Program($model))->run();