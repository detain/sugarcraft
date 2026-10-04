<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Goal;

/**
 * What the `/goal` judge decided about one turn (roadmap 3.D-3): OpenHands'
 * strict `{score, complete, missing}` answer.
 *
 * {@see parse()} is deliberately unforgiving about the SHAPE — a reply that is
 * not one JSON object with a boolean `complete` is no verdict at all, and the
 * loop stops rather than guess — and conservative about the MEANING: a judge
 * that says `complete` while still listing something missing has contradicted
 * itself, and the goal is not taken as met on half a verdict.
 */
final class GoalVerdict
{
    /** Longest `missing` item kept; it is quoted into a prompt and a notice. */
    public const ITEM_CHARS = 300;

    /** Most `missing` items kept. */
    public const MAX_ITEMS = 10;

    /** @param list<string> $missing */
    private function __construct(
        public readonly bool $complete,
        public readonly ?int $score,
        public readonly array $missing,
    ) {
    }

    /** @param list<string> $missing */
    public static function new(bool $complete, ?int $score = null, array $missing = []): self
    {
        return new self($complete && $missing === [], $score === null ? null : max(0, min(100, $score)), $missing);
    }

    /**
     * The verdict in the judge's raw reply, or null when the reply is not one.
     *
     * A reasoning model's `<think>…</think>` preamble and a single surrounding
     * code fence are taken off first — neither is the answer, and both are
     * what cheap models add however firmly they are told not to.
     */
    public static function parse(string $raw): ?self
    {
        $text = trim((string) preg_replace('/<think>.*?<\/think>/is', '', $raw));
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?```$/is', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        try {
            $data = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data) || array_is_list($data) || !\is_bool($data['complete'] ?? null)) {
            return null;
        }

        $score = $data['score'] ?? null;
        $missing = [];
        foreach (\is_array($data['missing'] ?? null) ? $data['missing'] : [] as $item) {
            if (!\is_string($item) || trim($item) === '') {
                continue;
            }
            $line = trim((string) preg_replace('/\s+/u', ' ', $item));
            $missing[] = mb_strlen($line) > self::ITEM_CHARS ? mb_substr($line, 0, self::ITEM_CHARS - 1) . '…' : $line;
            if (\count($missing) === self::MAX_ITEMS) {
                break;
            }
        }

        return self::new($data['complete'], \is_int($score) || \is_float($score) ? (int) round($score) : null, $missing);
    }

    /** `score 40/100`, or '' when the judge gave none. */
    public function scoreLabel(): string
    {
        return $this->score === null ? '' : sprintf('score %d/100', $this->score);
    }
}
