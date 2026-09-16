<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests\Support;

/**
 * A plain, NON-capable {@see \SugarCraft\Ansi\Parser\Handler} that reads the
 * colon flags the legacy way — through a late-bound closure over the owning
 * parser's accessor, which is exactly what
 * `SugarCraft\Vt\Handler\ScreenHandler::attachSubparamsProvider()` and
 * `SugarCraft\Freeze\SgrStateHandler::bindParser()` do.
 *
 * It exists to pin the back-compat half of the
 * {@see \SugarCraft\Ansi\Parser\SubparamsAwareHandler} change: introducing the
 * push must not disturb the pull, because the consuming repositories migrate on a
 * later step (vt-B), not in this change.
 */
final class PullSubparamsRecorder extends DispatchRecorder
{
    /**
     * Late-bound flag source, deliberately nullable and untyped at the boundary
     * the way the consumers wire it; see {@see self::csiDispatch()}.
     *
     * @var (callable(): list<bool>)|null
     */
    public $provider = null;

    /** @var list<list<bool>> flags observed *during* each CSI dispatch */
    public array $flagsDuringDispatch = [];

    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void
    {
        $provider = $this->provider;
        if ($provider !== null) {
            $this->flagsDuringDispatch[] = $provider();
        }
        parent::csiDispatch($final, $params, $prefix, $intermediate);
    }
}
