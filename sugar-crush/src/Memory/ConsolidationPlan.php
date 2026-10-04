<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * The parsed answer of an auto-memory consolidation call (roadmap 5.2): the
 * operations to apply, and the things the model decided not to save, each
 * with its reason.
 *
 * The reply is model output, so {@see parse()} trusts none of it: a
 * `<think>` block and a code fence are peeled off, the first JSON object is
 * read, and every operation is validated field by field. Anything malformed
 * becomes a `skip` with reason `invalid` rather than an exception, and
 * operations past {@see MAX_OPS} become `quota_guard` skips — a reply that
 * tries to rewrite the whole memory in one pass is cut off, not obeyed.
 */
final readonly class ConsolidationPlan
{
    /** Kilo's `maxOpsPerRun`: the most notes one run may change. */
    public const MAX_OPS = 16;

    /** The most tags an added note keeps, and the longest tag. */
    private const MAX_TAGS = 5;
    private const MAX_TAG_LENGTH = 32;

    /**
     * @param list<ConsolidationOp> $ops
     */
    private function __construct(public array $ops)
    {
    }

    /**
     * @param list<ConsolidationOp> $ops
     */
    public static function fromOps(array $ops): self
    {
        $kept = [];
        $mutating = 0;
        foreach ($ops as $op) {
            if ($op->mutates() && ++$mutating > self::MAX_OPS) {
                $kept[] = ConsolidationOp::skip('quota_guard', "more than " . self::MAX_OPS . " operations in one run");
                continue;
            }
            $kept[] = $op;
        }

        return new self($kept);
    }

    /** Parse the model's reply; never throws. */
    public static function parse(string $reply): self
    {
        $json = self::extractObject($reply);
        if ($json === null) {
            return new self([ConsolidationOp::skip('invalid', 'the reply held no JSON object')]);
        }

        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new self([ConsolidationOp::skip('invalid', 'the reply is not valid JSON: ' . $e->getMessage())]);
        }
        if (!\is_array($data)) {
            return new self([ConsolidationOp::skip('invalid', 'the reply is not a JSON object')]);
        }

        $ops = [];
        foreach (self::listField($data, 'operations') as $raw) {
            $ops[] = self::operation($raw);
        }
        foreach (self::listField($data, 'skipped') as $raw) {
            $reason = \is_array($raw) && \is_string($raw['reason'] ?? null) ? $raw['reason'] : '';
            $detail = \is_array($raw) && \is_string($raw['detail'] ?? null) ? $raw['detail'] : '';
            $ops[] = ConsolidationOp::skip(
                \in_array($reason, ConsolidationOp::SKIP_REASONS, true) ? $reason : 'unsupported',
                mb_substr($detail, 0, 200),
            );
        }

        return self::fromOps($ops);
    }

    /** @return list<ConsolidationOp> the operations that change a note */
    public function mutations(): array
    {
        return array_values(array_filter($this->ops, static fn(ConsolidationOp $op): bool => $op->mutates()));
    }

    /** @return list<ConsolidationOp> the skips, the model's and the parser's */
    public function skips(): array
    {
        return array_values(array_filter($this->ops, static fn(ConsolidationOp $op): bool => !$op->mutates()));
    }

    /** Whether the plan changes nothing — the answer "prefer saving nothing" expects most often. */
    public function changesNothing(): bool
    {
        return $this->mutations() === [];
    }

    private static function extractObject(string $reply): ?string
    {
        $text = preg_replace('#<think>.*?</think>#is', '', $reply) ?? $reply;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start === false || $end === false || $end < $start ? null : substr($text, $start, $end - $start + 1);
    }

    /**
     * @param array<mixed> $data
     * @return list<mixed>
     */
    private static function listField(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return \is_array($value) && array_is_list($value) ? $value : [];
    }

    private static function operation(mixed $raw): ConsolidationOp
    {
        if (!\is_array($raw)) {
            return ConsolidationOp::skip('invalid', 'an operation is not an object');
        }

        $op = \is_string($raw['op'] ?? null) ? strtolower($raw['op']) : '';
        $content = \is_string($raw['content'] ?? null) ? trim($raw['content']) : '';
        $id = \is_string($raw['id'] ?? null) ? trim($raw['id']) : '';

        return match ($op) {
            ConsolidationOp::ADD => $content === ''
                ? ConsolidationOp::skip('invalid', 'an add without content')
                : ConsolidationOp::add(
                    $content,
                    \in_array($raw['scope'] ?? null, ['project', 'user'], true) ? $raw['scope'] : 'project',
                    \in_array($raw['type'] ?? null, MemoryWriter::TYPES, true) ? $raw['type'] : 'pattern',
                    self::tags($raw['tags'] ?? []),
                ),
            ConsolidationOp::UPDATE => $id === '' || $content === ''
                ? ConsolidationOp::skip('invalid', 'an update needs an id and content')
                : ConsolidationOp::update($id, $content),
            ConsolidationOp::DELETE => $id === ''
                ? ConsolidationOp::skip('invalid', 'a delete needs an id')
                : ConsolidationOp::delete($id),
            'skip', 'noop' => ConsolidationOp::skip(
                \in_array($raw['reason'] ?? null, ConsolidationOp::SKIP_REASONS, true) ? $raw['reason'] : 'unsupported',
                \is_string($raw['detail'] ?? null) ? mb_substr($raw['detail'], 0, 200) : '',
            ),
            default => ConsolidationOp::skip('invalid', 'unknown operation ' . json_encode(mb_substr($op, 0, 40))),
        };
    }

    /**
     * Lower-case, dash-separated, short labels; anything else is dropped.
     *
     * @return list<string>
     */
    private static function tags(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (!\is_string($tag)) {
                continue;
            }
            $tag = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($tag)) ?? '', '-');
            if ($tag !== '' && \strlen($tag) <= self::MAX_TAG_LENGTH) {
                $tags[$tag] = true;
            }
        }

        return \array_slice(array_map('strval', array_keys($tags)), 0, self::MAX_TAGS);
    }
}
