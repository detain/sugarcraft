<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

/**
 * The nonsense-setting judges behind {@see Bootstrap::reportNonsenseLimits()}.
 *
 * WHY THIS CLASS EXISTS. The M6 size ratchet makes extraction the ruling path
 * whenever {@see Bootstrap} would cross its ceiling (the G1-L7
 * ManagedConfigTier and LaunchNotices splits are the precedent shapes). These
 * two judges are the smallest cohesive chunk of the launch-nonsense cluster:
 * one answers "was the key set at all" (the precondition for calling a value
 * nonsense), the other renders a set value the way the operator's own file
 * spells it. Neither is a Bootstrap secret — they touch no Bootstrap state,
 * read only the config array handed in — so the composition root keeps the
 * notice-building row and this file keeps the verdict helpers.
 *
 * THE ONE CONTRACT: behavior is byte-identical to the private originals they
 * were lifted from, unchanged; {@see BootstrapLaunchNoticeRoutingTest} keeps
 * pinning the notice end-to-end, and a mirror pin in that family keeps the
 * token verdict aligned with the engine resolver.
 */
final class NonsenseSettings
{
    /**
     * Whether $key is SET to something — the precondition for calling its
     * value nonsense. See {@see Bootstrap::reportNonsenseLimits()} for what
     * counts as unset.
     *
     * @param array<string, mixed> $config
     */
    public static function isSet(array $config, string $key): bool
    {
        $raw = $config[$key] ?? null;

        return $raw !== null && $raw !== '';
    }

    /**
     * A setting's value as the operator would recognise it from their own
     * file — its JSON spelling, so `"16x"` keeps its quotes and `1.0e+19`
     * reads as the number it was — clipped, because a hand-edited file can
     * hold a whole object under a numeric key.
     */
    public static function valueForNotice(mixed $raw): string
    {
        $json = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $json = $json === false ? get_debug_type($raw) : $json;

        return mb_strlen($json, 'UTF-8') > 60 ? mb_substr($json, 0, 59, 'UTF-8') . '…' : $json;
    }
}
