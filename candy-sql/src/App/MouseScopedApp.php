<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;
use SugarCraft\Sql\App;

/**
 * The model bin/candy-sql runs: {@see App} with a per-frame mouse-tracking
 * mode.
 *
 * Mouse reporting is scoped to the one surface that answers clicks, the
 * Connections detail-tab bar ({@see App::frameMouseMode()}). Each frame is
 * a {@see View} carrying that mode, so the runtime turns tracking on when
 * the user reaches the Connections page and off again when they leave it,
 * and every other pane keeps the terminal's native drag-selection. The
 * runtime also switches tracking off on exit, since it records the mode a
 * View set.
 *
 * App itself keeps its string view(), which its tests read directly; this
 * wrapper only adds the mode and delegates everything else.
 */
final class MouseScopedApp implements Model
{
    public function __construct(
        public readonly App $app,
    ) {}

    public function init(): ?\Closure
    {
        return $this->app->init();
    }

    /**
     * @return array{Model, ?\Closure}
     */
    public function update(Msg $msg): array
    {
        [$next, $cmd] = $this->app->update($msg);
        if ($next === $this->app) {
            return [$this, $cmd];
        }
        return [$next instanceof App ? new self($next) : $next, $cmd];
    }

    public function view(): View
    {
        return new View($this->app->view(), mouseMode: $this->app->frameMouseMode());
    }

    public function subscriptions(): ?Subscriptions
    {
        return $this->app->subscriptions();
    }
}
