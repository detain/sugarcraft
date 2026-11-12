<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

/**
 * The one table of advertised context windows for the Claude families, read
 * by every provider that can reach a Claude model (the native Anthropic
 * endpoint, Bedrock's Anthropic route, and Claude Code) so the same model
 * reports the same window whichever wrapper carries it.
 *
 * Keys are the bare pricing family (`claude-sonnet-4-6`). A Bedrock id arrives
 * with its `anthropic.` route prefix, which {@see lookup()} strips. Meta Llama
 * rows are keyed by their full Bedrock id because no other wrapper here
 * reaches them.
 *
 * An id absent from this table is NOT a window: {@see lookup()} answers null,
 * and the provider reports 0 ("unknown") so {@see ContextWindow} labels the
 * fallback as assumed instead of presenting a guess as a measurement.
 */
final class ModelWindows
{
    /** @var array<string, int> */
    private const WINDOWS = [
        'claude-fable-5-1' => 1_000_000,
        'claude-fable-5' => 1_000_000,
        'claude-opus-5-5' => 1_000_000,
        'claude-opus-5' => 1_000_000,
        'claude-opus-4-8' => 1_000_000,
        'claude-opus-4-7' => 1_000_000,
        'claude-opus-4-6' => 1_000_000,
        'claude-opus-4-5' => 200_000,
        'claude-opus-4-1' => 200_000,
        'claude-opus-4' => 200_000,
        'claude-3-opus' => 200_000,
        'claude-sonnet-5-5' => 1_000_000,
        'claude-sonnet-5' => 1_000_000,
        'claude-sonnet-4-6' => 1_000_000,
        'claude-sonnet-4-5' => 200_000,
        'claude-sonnet-4' => 200_000,
        'claude-3-7-sonnet' => 200_000,
        'claude-3-5-sonnet' => 200_000,
        'claude-3-sonnet' => 200_000,
        'claude-haiku-4-5' => 200_000,
        'claude-3-5-haiku' => 200_000,
        'claude-3-haiku' => 200_000,
        'meta.llama3-70b-instruct' => 8_192,
        'meta.llama3-8b-instruct' => 8_192,
    ];

    private const ROUTE_PREFIX = 'anthropic.';

    /** The advertised window for $family, or null when the table does not list it. */
    public static function lookup(string $family): ?int
    {
        $id = str_starts_with($family, self::ROUTE_PREFIX)
            ? substr($family, strlen(self::ROUTE_PREFIX))
            : $family;

        return self::WINDOWS[$id] ?? null;
    }
}
