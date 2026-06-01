<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Bits\Tree\Node;
use SugarCraft\Bits\Tree\Tree;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

/**
 * Example: interactive Tree component.
 *
 *   ↑/↓        navigate
 *   Enter      toggle expand
 *   ←/→ h/l    collapse / expand
 *   g / G      jump to top / bottom
 *   q          quit
 */

$tree = Tree::new(
    Node::branch('Documents',
        Node::branch('Projects',
            Node::leaf('app.php',     '~/Documents/Projects/app.php'),
            Node::leaf('README.md',   '~/Documents/Projects/README.md'),
        ),
        Node::leaf('notes.txt',       '~/Documents/notes.txt'),
    ),
    Node::branch('Downloads',
        Node::leaf('photo.png',       '~/Downloads/photo.png'),
        Node::leaf('archive.tar.gz',  '~/Downloads/archive.tar.gz'),
    ),
    Node::leaf('TODO.md',             '~/TODO.md'),
)->withSize(60, 12);

[$tree, ] = $tree->focus();

$model = new class($tree) implements Model {
    public function __construct(private readonly Tree $tree) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        [$tree, $cmd] = $this->tree->update($msg);
        assert($tree instanceof Tree);
        return [new self($tree), $cmd];
    }

    public function view(): string
    {
        $value = $this->tree->selectedValue();
        $footer = "\n\n  Selected: " . ($value ?? '—') . "  ·  q to quit\n";
        return $this->tree->view() . $footer;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
};

(new Program($model))->run();
