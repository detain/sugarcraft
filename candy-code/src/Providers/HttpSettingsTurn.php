<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

/**
 * One turn's hold on an {@see HttpSettingsSnapshot}: the snapshot ends when
 * this object is released.
 *
 * {@see \SugarCraft\Code\Backend\EngineBackend::runTurn()} keeps it in a
 * local, so the snapshot ends exactly when that frame does, on a return or
 * on a throw, without wrapping the turn's whole body in a try/finally.
 * {@see end()} ends it earlier; ending twice is a no-op.
 */
final class HttpSettingsTurn
{
    private bool $ended = false;

    public function __construct(private readonly int $token)
    {
    }

    /** End the snapshot now rather than when this object is released. */
    public function end(): void
    {
        if (!$this->ended) {
            $this->ended = true;
            HttpSettingsSnapshot::endTurn($this->token);
        }
    }

    public function __destruct()
    {
        $this->end();
    }
}
