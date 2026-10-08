<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Support;

use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\Msg;

/**
 * Runs a Cmd the way the Program would, minus the event loop: batches are
 * expanded recursively, a TickRequest is returned as-is (not fired).
 */
final class Cmds
{
    /** @return list<Msg> */
    public static function run(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            $out = [];
            foreach ($msg->cmds as $inner) {
                $out = [...$out, ...self::run($inner)];
            }

            return $out;
        }

        return $msg === null ? [] : [$msg];
    }

    /**
     * @template T of Msg
     * @param class-string<T> $class
     * @return list<T>
     */
    public static function of(string $class, ?\Closure $cmd): array
    {
        return array_values(array_filter(self::run($cmd), static fn (Msg $m): bool => $m instanceof $class));
    }
}
