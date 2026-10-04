<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * What a call's ARGUMENTS become when the {@see ContextLedger} prunes its
 * input rather than its output (roadmap 2.3): the projector rewrites the
 * assistant row's call, never the history.
 *
 * The arguments stay a JSON object with the same keys — a provider replays
 * them as the call's input, and a call whose shape changed would read as a
 * different call — and the argument that says what the call was about
 * (`file_path` for a write, {@see PrunedOutputPlaceholder::mainArgument()} for
 * the rest) is kept, so the model still knows what it did. Pure: the same
 * arguments always give the same bytes (the prompt-cache contract).
 */
final class PrunedInputPlaceholder
{
    public const WRITE_CONTENT = '[content elided to save context: a later write or read of this file supersedes it]';

    public const FAILED_INPUT = '[input removed to save context: this call failed]';

    /**
     * $arguments as $kind sends them, or unchanged for a kind that does not
     * touch a call's input.
     *
     * @param array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     */
    public static function apply(PruneKind $kind, array $arguments): array
    {
        return match ($kind) {
            PruneKind::WriteContent => self::withoutWriteContent($arguments),
            PruneKind::Input => self::blanked($arguments),
            PruneKind::Output => $arguments,
        };
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     */
    public static function withoutWriteContent(array $arguments): array
    {
        if (is_string($arguments['content'] ?? null)) {
            $arguments['content'] = self::WRITE_CONTENT;
        }

        return $arguments;
    }

    /**
     * Every argument but the main one replaced by {@see FAILED_INPUT}; the
     * main one kept, bounded the way an output placeholder bounds it.
     *
     * @param array<array-key, mixed> $arguments
     * @return array<array-key, mixed>
     */
    public static function blanked(array $arguments): array
    {
        $main = PrunedOutputPlaceholder::mainArgument($arguments);
        $kept = false;
        foreach ($arguments as $key => $value) {
            if (!$kept && $main !== null && is_string($value) && PrunedOutputPlaceholder::mainArgument([$key => $value]) === $main) {
                $arguments[$key] = $main;
                $kept = true;

                continue;
            }
            $arguments[$key] = self::FAILED_INPUT;
        }

        return $arguments;
    }

    /**
     * Estimated tokens $kind saves on $arguments: the encoded arguments less
     * their placeholder form. Never negative.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function savedTokens(PruneKind $kind, array $arguments): int
    {
        $before = \SugarCraft\Crush\Util\TokenEstimate::ofText(self::encode($arguments));
        $after = \SugarCraft\Crush\Util\TokenEstimate::ofText(self::encode(self::apply($kind, $arguments)));

        return max(0, $before - $after);
    }

    /** @param array<array-key, mixed> $arguments */
    private static function encode(array $arguments): string
    {
        return json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }
}
