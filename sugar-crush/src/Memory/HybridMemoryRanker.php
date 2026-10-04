<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * Ranks memory notes by relevance to one query — the user's latest message —
 * for the per-turn memory recall (roadmap 5.3-2).
 *
 * It mirrors OpenClaw's hybrid memory search (crush_report 11-openclaw §5.2),
 * the defaults included, because those are the tuned part of that design:
 *
 *  - TWO LEGS, MERGED `0.7 × vector + 0.3 × keyword` ({@see VECTOR_WEIGHT},
 *    {@see TEXT_WEIGHT}). The keyword leg is BM25 over the note's body, type
 *    and tags; the vector leg is the cosine similarity of the note's
 *    embedding to the query's.
 *  - RECENCY: the merged relevance is multiplied by `0.5^(age / 30 days)`
 *    ({@see HALF_LIFE_DAYS}), age measured from the note's last edit. Decay
 *    only REORDERS: whether a note is relevant at all is judged before it
 *    ({@see MIN_SCORE}), so an old note that answers the question is still
 *    recalled, just behind a fresher one that answers it as well.
 *  - DIVERSITY: the final pick is MMR with λ = 0.7 ({@see MMR_LAMBDA}) over
 *    the Jaccard overlap of the notes' word sets, so three near-duplicates
 *    never fill all three recall slots.
 *  - RELEVANCE GATE: a note is a candidate when its merged relevance reaches
 *    {@see MIN_SCORE}; a note the keyword leg matched is kept even below it,
 *    because a shared distinctive word is evidence a similarity score is not.
 *
 * WHY THE KEYWORD LEG IS SCORED HERE, NOT BY {@see MemorySearchIndex}. That
 * index answers `/memory search` and the Memory tool's `recall`, whose
 * contract is "every word must match" — the right contract for a search a
 * person types, and the wrong one for a whole user prompt as the query, where
 * almost no note contains every word. Recall needs the disjunctive score:
 * each matching word contributes its BM25 term weight. The formula is the
 * same Okapi BM25 FTS5's `bm25()` computes (k1 = 1.2, b = 0.75, the same
 * column weights: body 1.0, type 0.5, tags 2.0), over the at most a few
 * hundred notes a store holds — which also means the keyword leg works on a
 * PHP build with no FTS5 at all.
 *
 * GRACEFUL WITHOUT EMBEDDINGS. With no embedder (no embedding model
 * configured, which is the default — SGLang servers often serve none) the
 * ranking is keyword-only and says so ({@see mode()}). With an embedder that
 * FAILS, the ranking is keyword-only too, and the failure is reported
 * ({@see degradedReason()}) rather than swallowed: an explicitly configured
 * model that does not answer is something the operator should hear about,
 * which is OpenClaw's failure rule.
 *
 * Deterministic: the same query, notes, vectors and clock give the same
 * order; ties break on the note id.
 */
final class HybridMemoryRanker
{
    public const VECTOR_WEIGHT = 0.7;

    public const TEXT_WEIGHT = 0.3;

    public const HALF_LIFE_DAYS = 30;

    public const MMR_LAMBDA = 0.7;

    /** Merged relevance a note needs to be recalled without a keyword hit. */
    public const MIN_SCORE = 0.35;

    /** BM25 term-frequency saturation. */
    public const BM25_K1 = 1.2;

    /** BM25 length normalisation. */
    public const BM25_B = 0.75;

    /** Field weights, as {@see MemorySearchIndex}'s FTS5 `bm25()` call uses them. */
    private const FIELD_WEIGHTS = ['content' => 1.0, 'type' => 0.5, 'tags' => 2.0];

    /** Words shorter than this carry no recall signal. */
    private const MIN_WORD_LENGTH = 2;

    /**
     * Function words that would otherwise make every note a keyword match for
     * every English prompt. Only the query side is filtered: a note's own
     * length still counts every word, as BM25's length normalisation expects.
     */
    private const STOPWORDS = [
        'a', 'about', 'above', 'after', 'again', 'all', 'also', 'am', 'an', 'and', 'any', 'are', 'as', 'at',
        'be', 'been', 'before', 'being', 'both', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'doing',
        'done', 'each', 'few', 'for', 'from', 'get', 'got', 'had', 'has', 'have', 'having', 'he', 'her',
        'here', 'him', 'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'just', 'let', 'like',
        'make', 'me', 'more', 'most', 'my', 'need', 'no', 'not', 'now', 'of', 'off', 'on', 'once', 'one',
        'only', 'or', 'other', 'our', 'out', 'over', 'please', 'should', 'so', 'some', 'such', 'than', 'that',
        'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'to', 'too', 'up', 'us',
        'use', 'very', 'want', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why',
        'will', 'with', 'would', 'you', 'your',
    ];

    /** @var list<array{entry: MemoryEntry, score: float}> */
    private array $ranked = [];

    private string $mode = 'keyword';

    private ?string $degradedReason = null;

    /**
     * @param ?\Closure(list<string>): list<list<float>> $embedder the vector
     *        leg: one vector per input text, in order; null for keyword-only
     */
    private function __construct(
        private readonly ?\Closure $embedder,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    /**
     * @param ?\Closure(list<string>): list<list<float>> $embedder
     */
    public static function new(?\Closure $embedder = null, ?\DateTimeImmutable $now = null): self
    {
        return new self($embedder, $now ?? new \DateTimeImmutable());
    }

    /**
     * The at most $limit notes most relevant to $query, best first.
     *
     * @param list<MemoryEntry> $entries
     *
     * @return list<array{entry: MemoryEntry, score: float}> each with its
     *         final (decayed) score
     */
    public function rank(string $query, array $entries, int $limit = 3): array
    {
        $this->ranked = [];
        $this->mode = 'keyword';
        $this->degradedReason = null;

        $queryTerms = self::queryTerms($query);
        if ($entries === [] || $limit < 1 || ($queryTerms === [] && $this->embedder === null)) {
            return [];
        }

        $entries = array_values($entries);
        $keyword = self::bm25($queryTerms, $entries);
        $vector = $this->vectorScores($query, $entries);

        $candidates = [];
        foreach ($entries as $i => $entry) {
            $kw = $keyword[$i];
            $relevance = $vector === null
                ? $kw
                : self::VECTOR_WEIGHT * $vector[$i] + self::TEXT_WEIGHT * $kw;

            if ($kw <= 0.0 && $relevance < self::MIN_SCORE) {
                continue;
            }

            $candidates[] = [
                'entry' => $entry,
                'score' => $relevance * $this->decay($entry),
                'words' => self::wordSet($entry),
            ];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => [$b['score'], $a['entry']->id()] <=> [$a['score'], $b['entry']->id()],
        );

        $this->ranked = self::mmr($candidates, $limit);

        return $this->ranked;
    }

    /** `hybrid` when the last {@see rank()} used both legs, else `keyword`. */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * Why the last {@see rank()} fell back to keyword-only although an
     * embedder was configured, or null when it did not.
     */
    public function degradedReason(): ?string
    {
        return $this->degradedReason;
    }

    /**
     * The query's distinct content words, lower-cased, stopwords removed.
     *
     * @return list<string>
     */
    public static function queryTerms(string $query): array
    {
        $terms = [];
        foreach (self::words($query) as $word) {
            if (\strlen($word) >= self::MIN_WORD_LENGTH && !\in_array($word, self::STOPWORDS, true)) {
                $terms[$word] = true;
            }
        }

        return array_map('strval', array_keys($terms));
    }

    /**
     * Lower-cased letter/digit runs; camelCase and snake_case split into
     * their parts as well as kept whole, so `RepoMapTool` in a prompt meets
     * `repo map tool` in a note and the other way round.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $runs = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = [];
        foreach ($runs as $run) {
            $words[] = self::stem(mb_strtolower($run));
            $parts = preg_split('/(?<=\p{Ll})(?=\p{Lu})|(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', $run, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if (\count($parts) > 1) {
                foreach ($parts as $part) {
                    $words[] = self::stem(mb_strtolower($part));
                }
            }
        }

        return $words;
    }

    /**
     * A light English suffix fold — `deploys`, `deploying` and `deployed`
     * all meet `deploy` — standing in for the porter stemmer the FTS5 index
     * tokenises with. Short words are left alone, so `is`, `bus` or `ted`
     * are never cut to nothing.
     */
    private static function stem(string $word): string
    {
        if (\strlen($word) < 5 || preg_match('/^[a-z]+$/', $word) !== 1) {
            return $word;
        }

        foreach (['ing' => 3, 'ies' => 3, 'ed' => 2, 'es' => 2, 's' => 1] as $suffix => $length) {
            if (str_ends_with($word, $suffix) && !str_ends_with($word, 'ss')) {
                $stem = substr($word, 0, -$length);

                return $suffix === 'ies' ? $stem . 'y' : $stem;
            }
        }

        return $word;
    }

    /**
     * BM25 per entry, normalised so the best match scores 1.0 and a note
     * sharing no query word scores 0.
     *
     * @param list<string>      $terms
     * @param list<MemoryEntry> $entries
     *
     * @return list<float>
     */
    private static function bm25(array $terms, array $entries): array
    {
        $n = \count($entries);
        if ($terms === []) {
            return array_fill(0, $n, 0.0);
        }

        /** @var list<array<string, array<string, int>>> $fieldCounts entry => field => word => count */
        $fieldCounts = [];
        /** @var array<string, int> $totalLength field => summed word count */
        $totalLength = array_fill_keys(array_keys(self::FIELD_WEIGHTS), 0);
        $documentFrequency = [];
        foreach ($entries as $i => $entry) {
            $fields = [
                'content' => self::words($entry->content()),
                'type' => self::words($entry->type()),
                'tags' => self::words(implode(' ', $entry->tags())),
            ];
            $seen = [];
            foreach ($fields as $field => $words) {
                $fieldCounts[$i][$field] = array_count_values($words);
                $totalLength[$field] += \count($words);
                foreach ($words as $word) {
                    $seen[$word] = true;
                }
            }
            foreach ($terms as $term) {
                if (isset($seen[$term])) {
                    $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
                }
            }
        }

        $scores = [];
        foreach ($entries as $i => $entry) {
            $score = 0.0;
            foreach ($terms as $term) {
                $df = $documentFrequency[$term] ?? 0;
                if ($df === 0) {
                    continue;
                }
                // The non-negative IDF (Lucene's): a word in every note still
                // weighs a little rather than subtracting relevance.
                $idf = log(1.0 + ($n - $df + 0.5) / ($df + 0.5));
                foreach (self::FIELD_WEIGHTS as $field => $weight) {
                    $tf = $fieldCounts[$i][$field][$term] ?? 0;
                    if ($tf === 0) {
                        continue;
                    }
                    $length = array_sum($fieldCounts[$i][$field]);
                    $average = max(1.0, $totalLength[$field] / $n);
                    $norm = 1.0 - self::BM25_B + self::BM25_B * $length / $average;
                    $score += $weight * $idf * ($tf * (self::BM25_K1 + 1.0)) / ($tf + self::BM25_K1 * $norm);
                }
            }
            $scores[$i] = $score;
        }

        $max = max($scores);

        return $max > 0.0 ? array_map(static fn (float $s): float => $s / $max, $scores) : $scores;
    }

    /**
     * Cosine similarity of each entry to the query, clamped to [0, 1], or
     * null when there is no embedder or it failed.
     *
     * @param list<MemoryEntry> $entries
     *
     * @return list<float>|null
     */
    private function vectorScores(string $query, array $entries): ?array
    {
        if ($this->embedder === null) {
            return null;
        }

        $texts = [$query];
        foreach ($entries as $entry) {
            $texts[] = self::embeddingText($entry);
        }

        try {
            $vectors = ($this->embedder)($texts);
            if (!\is_array($vectors) || \count($vectors) !== \count($texts)) {
                throw new \RuntimeException('the embedder answered the wrong number of vectors');
            }
            $vectors = array_values($vectors);
            $queryVector = $vectors[0];
            $scores = [];
            foreach (array_keys($entries) as $i) {
                $scores[] = max(0.0, self::cosine($queryVector, $vectors[$i + 1]));
            }
        } catch (\Throwable $e) {
            $this->degradedReason = $e->getMessage() !== '' ? $e->getMessage() : $e::class;

            return null;
        }

        $this->mode = 'hybrid';

        return $scores;
    }

    /** What a note is embedded as: its type, tags and body. */
    public static function embeddingText(MemoryEntry $entry): string
    {
        $tags = $entry->tags() === [] ? '' : ' (' . implode(', ', $entry->tags()) . ')';

        return '[' . $entry->type() . ']' . $tags . ' ' . $entry->content();
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     *
     * @throws \RuntimeException when the vectors are not comparable
     */
    private static function cosine(array $a, array $b): float
    {
        if (\count($a) !== \count($b) || $a === []) {
            throw new \RuntimeException('the embedder answered vectors of different dimensions');
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach (array_values($a) as $i => $x) {
            $y = (float) $b[$i];
            $x = (float) $x;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na > 0.0 && $nb > 0.0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }

    /** `0.5^(age / half-life)`, age from the note's last edit; never above 1. */
    private function decay(MemoryEntry $entry): float
    {
        $ageDays = max(0, $this->now->getTimestamp() - $entry->modifiedAt()->getTimestamp()) / 86400;

        return 0.5 ** ($ageDays / self::HALF_LIFE_DAYS);
    }

    /** @return array<string, true> */
    private static function wordSet(MemoryEntry $entry): array
    {
        return array_fill_keys(self::words(self::embeddingText($entry)), true);
    }

    /**
     * Maximal marginal relevance over candidates already sorted best first:
     * each pick maximises `λ·score − (1−λ)·max Jaccard(similarity to a pick)`.
     *
     * @param list<array{entry: MemoryEntry, score: float, words: array<string, true>}> $candidates
     *
     * @return list<array{entry: MemoryEntry, score: float}>
     */
    private static function mmr(array $candidates, int $limit): array
    {
        $picked = [];
        while ($candidates !== [] && \count($picked) < $limit) {
            $bestIndex = null;
            $bestValue = -INF;
            foreach ($candidates as $index => $candidate) {
                $overlap = 0.0;
                foreach ($picked as $pick) {
                    $overlap = max($overlap, self::jaccard($candidate['words'], $pick['words']));
                }
                $value = self::MMR_LAMBDA * $candidate['score'] - (1.0 - self::MMR_LAMBDA) * $overlap;
                // Strictly greater: candidates arrive best first, so a tie
                // keeps the higher-ranked (then lower-id) note.
                if ($value > $bestValue) {
                    $bestValue = $value;
                    $bestIndex = $index;
                }
            }
            $picked[] = $candidates[$bestIndex];
            unset($candidates[$bestIndex]);
        }

        return array_map(
            static fn (array $pick): array => ['entry' => $pick['entry'], 'score' => $pick['score']],
            $picked,
        );
    }

    /**
     * @param array<string, true> $a
     * @param array<string, true> $b
     */
    private static function jaccard(array $a, array $b): float
    {
        $union = \count($a + $b);

        return $union === 0 ? 0.0 : \count(array_intersect_key($a, $b)) / $union;
    }
}
