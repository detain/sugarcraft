<?php

declare(strict_types=1);

/**
 * Drive the pager Model without a terminal: build it from rendered text,
 * feed it key messages, and print each frame. This is the same
 * init()/update()/view() cycle the `candy-mdview -p` program runs.
 *
 *   $ php examples/pager-model.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Markdown\Renderer;
use SugarCraft\MdView\MdViewModel;
use SugarCraft\Core\Msg\KeyMsg;

$lines = [];
for ($i = 1; $i <= 30; $i++) {
    $lines[] = "Line {$i} of a long document.";
}
$rendered = Renderer::plain()->withWordWrap(40)->render(implode("\n\n", $lines));

// A 40x6 window: one header-free body of six rows (no withDocument() call,
// so there is no status header to reserve a row for).
$model = MdViewModel::fromContent($rendered, 40, 6);

echo "--- first frame ---\n", $model->view(), "\n";

// The arrow keys and j/k both move one line down; PgDn pages.
[$model, ] = $model->update(new KeyMsg(KeyType::Char, 'j'));
[$model, ] = $model->update(new KeyMsg(KeyType::Char, 'j'));
echo "--- after two j presses ---\n", $model->view(), "\n";

[$model, ] = $model->update(new KeyMsg(KeyType::Char, 'q'));
echo "--- after q: exited=", $model->isExited() ? 'yes' : 'no', " ---\n";
