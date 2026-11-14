<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use React\EventLoop\LoopInterface;

/**
 * Loop-pumping for tests (msteams-27, ported from the discord twin). await()
 * runs the loop under a PERIODIC 5 ms probe so it also makes progress while a
 * fake keeps a connection open (a bare run()-to-empty can only ever return
 * when every stream closed). The probe stops the loop the moment the caller's
 * condition holds; the cap is a hard timer, never a burned window.
 *
 * window() is the legacy fixed shape, kept ONLY for negative tests — sites
 * that assert silence after N ms have no positive condition to await.
 */
final class Settle
{
    /** @param callable():bool $done @return bool whether $done became true */
    public static function await(LoopInterface $loop, int $capMs, callable $done): bool
    {
        $reached = false;
        $probe = null;
        $probe = $loop->addPeriodicTimer(0.005, static function () use ($loop, $done, &$reached, &$probe): void {
            if ($done()) {
                $reached = true;
                if ($probe !== null) {
                    $loop->cancelTimer($probe);
                }
                $loop->stop();
            }
        });
        $cap = $loop->addTimer($capMs / 1000.0, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        if (!$reached) {
            $loop->cancelTimer($probe);
        }
        $loop->cancelTimer($cap);

        return $reached;
    }

    /** Fixed-window pump for silence assertions. */
    public static function window(LoopInterface $loop, int $ms): void
    {
        $loop->addTimer($ms / 1000.0, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
    }
}
