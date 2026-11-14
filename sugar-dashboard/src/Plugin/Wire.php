<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plugin;

/**
 * Shared doctrine of the plugin wire: the printable-preview budget and
 * the minSize validation/clamp ladder (sugar-dashboard-199 dedup).
 *
 * Both seats were duplicated per side of the boundary — the host
 * (ExternalModule) and the child template (PluginSdk) each carried a
 * byte-identical clamp, and the two refusal previews each carried the
 * magic 120. One class, two functions, both call sites; the ceilings
 * are wire policy now, not per-file folklore.
 */
final class Wire
{
    /** Maximum raw bytes echoed back inside a refusal/preview message. */
    public const PREVIEW_BYTES = 120;

    /** Ceiling a plugin may claim for its minimum width (cells). */
    public const MAX_MIN_WIDTH = 10000;

    /** Ceiling a plugin may claim for its minimum height (cells). */
    public const MAX_MIN_HEIGHT = 1000;

    /** Placeholder advertised before (or without) a handshake answer. */
    public const FALLBACK_MIN_SIZE = [30, 4];

    private function __construct()
    {
    }

    /**
     * Printable, length-clipped rendering of an unparsable wire line.
     *
     * Byte-wise: every non-printable (control bytes, ESC sequences,
     * invalid UTF-8) becomes '?' so a refusal can never smuggle a
     * terminal escape into whatever surface prints it.
     */
    public static function preview(string $raw): string
    {
        $trimmed = trim($raw);
        if (strlen($trimmed) > self::PREVIEW_BYTES) {
            $trimmed = substr($trimmed, 0, self::PREVIEW_BYTES) . '...';
        }

        return preg_replace('/[^\x20-\x7E]/', '?', $trimmed) ?? '';
    }

    /**
     * Validate and clamp a plugin-supplied minSize to a safe default.
     * Must be a two-element array of positive integers; otherwise the
     * fallback placeholder.
     *
     * @return array{0:int,1:int}
     */
    public static function clampMinSize(mixed $minSize): array
    {
        if (
            is_array($minSize)
            && count($minSize) === 2
            && is_int($minSize[0] ?? null)
            && is_int($minSize[1] ?? null)
            && ($minSize[0] ?? 0) > 0
            && ($minSize[1] ?? 0) > 0
        ) {
            return [
                max(1, min((int) $minSize[0], self::MAX_MIN_WIDTH)),
                max(1, min((int) $minSize[1], self::MAX_MIN_HEIGHT)),
            ];
        }

        return self::FALLBACK_MIN_SIZE;
    }
}
