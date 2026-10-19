<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context\Compaction;

/**
 * The coverage audit of a model-written compaction summary (plan_comparison.md
 * CTX-4): given what the summarising model was ASKED to keep, how much of it is
 * actually in what the model WROTE?
 *
 * WHY AUDIT AT ALL. The landing already trusts the model block exactly as far as
 * its shape is auditable — {@see StateSummaryTemplate::missingHeadings()} names
 * the headings, {@see StateSummaryTemplate::filledFrom()} patches them — but a
 * block can have every heading present and still be a paragraph of paraphrase
 * that dropped the security constraint quoted verbatim, the file paths, the
 * exact error text and the numbers the session turns on. Those are the facts the
 * six-facet contract asks for precisely because a paraphrase loses them, and
 * until now a quiet loss was indistinguishable from a good summary. The structural
 * half of the safeguard (missing headings) therefore ships as a precondition of
 * this class, not as a duplicate of it.
 *
 * WHAT IS COUNTED, AND WHERE IT COMES FROM — the identifier set is derived,
 * never extracted a second time:
 *  - file references are the bullet lines of the fallback block's file sections,
 *    which are written by {@see FilesTouched} off the tool rows. There is one
 *    file extractor in this codebase and this audit is not a second one;
 *  - quoted details are backtick/straight-quote spans (≥ {@see MIN_QUOTED_CHARS}
 *    characters — shorter ones are prose punctuation, not facts) from the
 *    fallback's own sections and the exchange texts, plus the error excerpts of
 *    the fallback's Errors-and-fixes lines (the model is told to quote those
 *    verbatim, so their openings are strong needles);
 *  - long numerics are runs of ≥ 6 digits or ≥ 8 hex characters in the exchange
 *    texts — amounts, SHAs, port numbers, error codes.
 *
 * The haystack is ONLY what the model wrote: its raw state block text plus the
 * parsed records' facet values. The landed block is filled from the fallback
 * before it renders, so measuring the rendered block would credit the model for
 * the heuristic's own words.
 *
 * HOLD IS STRUCTURAL COLLAPSE AND NOTHING LESS: a state block missing more
 * than half its model sections AND carrying under half the file references —
 * the two together, never either alone. Partial or missing exchange RECORDS
 * are FILL, not HOLD: the per-exchange lines are what the heuristic re-fills
 * for, and the measured healthy 26-message 325,286-token blocking-tier fixture
 * answers precisely that way — a subset of records over a sound block. The
 * 2,400-message 122%-of-source ramble never reaches this class at all because
 * {@see \SugarCraft\Code\Host\CompactionService::summaryRejection()} throws a
 * reply that is not smaller than its source before any audit runs. Everything
 * else short of collapse — one missing heading, a dropped number, no block at
 * all — is FILL, which is what every compaction does today, word for word.
 *
 * NO CONFIG SWITCH (R12 ruling, taken here so the next reader does not reopen
 * it): `compaction.safeguard` was considered and declined. The audit costs one
 * pass over a reply that is already in memory; its PASS and FILL verdicts change
 * nothing at all; and a switch would multiply the shapes the landing must prove
 * safe for a user gain the advisory notices already provide. Revisit only if a
 * measured false HOLD shows up in real sessions.
 *
 * The instance doubles as the audit's report — verdict plus the counts behind
 * it — because the only consumer prints the detail into a notice, and a notice
 * that can say WHICH safeguard failed is the difference between "the model
 * wrote junk" and "the model dropped 9 of 13 file references".
 */
final class SummarySafeguard
{
    /** Quoted spans shorter than this are punctuation around ordinary words, not carried facts. */
    public const MIN_QUOTED_CHARS = 12;

    /** How much of a fallback error excerpt is used as a needle. */
    public const ERROR_FRAGMENT_CHARS = 60;

    /** Bound on the identifier set, files first — a session quoting thousands of numbers is audited on its first {@see MAX_IDENTIFIERS}. */
    public const MAX_IDENTIFIERS = 256;

    /** Below this share of identifiers carried, a structurally complete summary still only FILLs. */
    public const MIN_IDENTIFIER_COVERAGE = 0.75;

    /** The file-reference share that, combined with missing sections, makes a block HOLD-collapsed. */
    public const COLLAPSE_FILE_COVERAGE = 0.5;

    /** More model sections missing than this, combined with {@see COLLAPSE_FILE_COVERAGE}, is collapse; fewer is FILL. */
    public const COLLAPSE_MISSING_SECTIONS = 4;

    /** A digit run or hex run long enough to be a fact (an amount, a SHA, a port, an error code) rather than a countable in prose. */
    private const NUMERIC_PATTERN = '/\d{6,}|\b[0-9a-fA-F]{8,}\b/u';

    private const QUOTED_PATTERN = '/`([^`\r\n]{12,})`|"([^"\r\n]{12,})"/u';

    /**
     * @param list<string> $missingHeadings the model headings never written
     * @param float $fileCoverage share of the fallback's file references the model carried (1.0 when there were none)
     * @param float $identifierCoverage share of the whole identifier set carried (1.0 when there was nothing to carry)
     */
    private function __construct(
        public readonly SummarySafeguardVerdict $verdict,
        public readonly int $expectedRecords,
        public readonly int $parsedRecords,
        public readonly array $missingHeadings,
        public readonly float $fileCoverage,
        public readonly float $identifierCoverage,
        private readonly string $detail,
    ) {
    }

    /**
     * The audit itself — pure, and cheap enough to run on every landing.
     *
     * @param ?StateSummaryTemplate $modelBlock the parsed {@see StateSummaryTemplate::OPEN_TAG} block, null when the reply carried none
     * @param StateSummaryTemplate $fallback the heuristic block the landing would have written — the source of the file and error identifiers
     * @param string $sourceText the verbatim user and assistant texts of the condensed exchanges — the source of the quoted and numeric identifiers
     * @param string $recordText the parsed records' facet values — half the haystack
     * @param int $expectedRecords the exchanges offered to the model
     * @param int $parsedRecords how many of them came back as parsed records
     * @param string $modelText the raw block text between the tags, '' when there was no block — the other half of the haystack
     */
    public static function assess(
        ?StateSummaryTemplate $modelBlock,
        StateSummaryTemplate $fallback,
        string $sourceText,
        string $recordText,
        int $expectedRecords,
        int $parsedRecords,
        string $modelText,
    ): self {
        $missing = $modelBlock?->missingHeadings() ?? [];
        $files = self::fileIdentifiers($fallback);
        $quoted = self::quotedIdentifiers($fallback, $sourceText);
        $numerics = self::numericIdentifiers($sourceText);

        $files = array_values(array_unique(array_filter(array_map(self::flatten(...), $files), static fn (string $n): bool => $n !== '')));
        $others = array_values(array_unique(array_filter(array_map(self::flatten(...), [...$quoted, ...$numerics]), static fn (string $n): bool => $n !== '')));
        // Files first: the cap must never drop a file reference in favour of a
        // numeric, because fileCoverage is a HOLD input and numeric coverage is not.
        $candidates = array_slice([...$files, ...$others], 0, self::MAX_IDENTIFIERS);
        $scannedFiles = array_slice($files, 0, \count($candidates));

        $haystack = self::flatten($modelText . "\n" . $recordText);
        $hits = 0;
        $fileHits = 0;
        foreach ($candidates as $needle) {
            if (str_contains($haystack, $needle)) {
                $hits++;
                if (\in_array($needle, $scannedFiles, true)) {
                    $fileHits++;
                }
            }
        }

        $total = \count($candidates);
        $fileTotal = \count($scannedFiles);
        $identifierCoverage = $total === 0 ? 1.0 : $hits / $total;
        $fileCoverage = $fileTotal === 0 ? 1.0 : $fileHits / $fileTotal;

        // HOLD is collapse, and collapse is seen in the BLOCK: it parses to
        // fewer than half its model sections while also carrying under half the
        // file references — a shape that is junk as a summary whatever the
        // records beside it say. Lost or partial RECORDS are NOT collapse: the
        // per-exchange lines are exactly what the heuristic re-fills for, and
        // the measured healthy fixtures (the 26-message blocking-tier session)
        // routinely answer with a subset of records over a sound block.
        $collapse = $modelBlock !== null
            && \count($missing) > self::COLLAPSE_MISSING_SECTIONS
            && $fileCoverage < self::COLLAPSE_FILE_COVERAGE;

        $verdict = match (true) {
            $collapse => SummarySafeguardVerdict::HOLD,
            $modelBlock === null => SummarySafeguardVerdict::FILL,
            $missing !== [] => SummarySafeguardVerdict::FILL,
            $parsedRecords < $expectedRecords => SummarySafeguardVerdict::FILL,
            $identifierCoverage < self::MIN_IDENTIFIER_COVERAGE => SummarySafeguardVerdict::FILL,
            default => SummarySafeguardVerdict::PASS,
        };

        return new self(
            $verdict,
            $expectedRecords,
            $parsedRecords,
            $missing,
            $fileCoverage,
            $identifierCoverage,
            self::describe($verdict, $modelBlock, $missing, $expectedRecords, $parsedRecords, $hits, $total, $fileHits, $fileTotal),
        );
    }

    /** Whether this verdict tells the landing to discard the model text. */
    public function isHold(): bool
    {
        return $this->verdict === SummarySafeguardVerdict::HOLD;
    }

    /**
     * The counts behind the verdict as one English sentence fragment, for the
     * notices: 'kept 1 of 13 exchange records; omitted 7 of 8 state sections
     * (Constraints, Progress, …); carried 0 of 6 file references'.
     */
    public function summary(): string
    {
        return $this->detail;
    }

    /**
     * The fallback's file bullets. Derived from {@see FilesTouched} upstream;
     * split here on the `## Heading` rendering, which is a formatting concern,
     * not a second extraction.
     *
     * @return list<string>
     */
    private static function fileIdentifiers(StateSummaryTemplate $fallback): array
    {
        $out = [];
        foreach (['Files read', 'Files modified'] as $heading) {
            foreach (preg_split('/\R/u', $fallback->section($heading)) ?: [] as $line) {
                // The bullets are written by StateSummaryTemplate::bullets() as
                // exactly '- ' + text; strip that prefix with plain string ops.
                $item = trim($line);
                if (str_starts_with($item, '-')) {
                    $item = trim(substr($item, 1));
                }
                if ($item !== '' && !\in_array($item, $out, true)) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * Quote spans from the fallback's sections and the exchange texts, plus the
     * fallback's error excerpts — the heuristic already keeps verbatim the words
     * the instruction told the model to keep verbatim, so they are the contract.
     *
     * @return list<string>
     */
    private static function quotedIdentifiers(StateSummaryTemplate $fallback, string $sourceText): array
    {
        $needles = [];
        $sections = [];
        foreach (StateSummaryTemplate::HEADINGS as $heading) {
            $sections[] = $fallback->section($heading);
        }
        foreach ([$sourceText, implode("\n", $sections)] as $text) {
            if (preg_match_all(self::QUOTED_PATTERN, $text, $m) === false) {
                continue;
            }
            foreach ([...$m[1], ...$m[2]] as $span) {
                $span = trim($span);
                if (mb_strlen($span) >= self::MIN_QUOTED_CHARS) {
                    $needles[] = $span;
                }
            }
        }
        foreach (preg_split('/\R/u', $fallback->section('Errors and fixes')) ?: [] as $line) {
            $item = trim($line);
            if (str_starts_with($item, '-')) {
                $item = trim(substr($item, 1));
            }
            if ($item === '') {
                continue;
            }
            $position = strpos($item, ': ');
            $fragment = self::flatten($position === false ? $item : substr($item, $position + 2));
            if (mb_strlen($fragment) >= self::MIN_QUOTED_CHARS) {
                $needles[] = mb_substr($fragment, 0, self::ERROR_FRAGMENT_CHARS);
            }
        }

        return $needles;
    }

    /**
     * @return list<string>
     */
    private static function numericIdentifiers(string $sourceText): array
    {
        return preg_match_all(self::NUMERIC_PATTERN, $sourceText, $m) === false ? [] : $m[0];
    }

    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function describe(
        SummarySafeguardVerdict $verdict,
        ?StateSummaryTemplate $modelBlock,
        array $missing,
        int $expectedRecords,
        int $parsedRecords,
        int $hits,
        int $total,
        int $fileHits,
        int $fileTotal,
    ): string {
        $parts = [];
        if ($parsedRecords < $expectedRecords) {
            $parts[] = "kept {$parsedRecords} of {$expectedRecords} exchange records";
        }
        if ($modelBlock === null) {
            $parts[] = 'wrote no state block';
        } elseif ($missing !== []) {
            $parts[] = 'omitted ' . \count($missing) . ' of ' . \count(StateSummaryTemplate::MODEL_HEADINGS)
                . ' state sections (' . implode(', ', $missing) . ')';
        }
        if ($fileTotal > 0 && $fileHits < $fileTotal) {
            $parts[] = "carried {$fileHits} of {$fileTotal} file references";
        }
        $detailTotal = $total - $fileTotal;
        $detailHits = $hits - $fileHits;
        if ($detailTotal > 0 && $detailHits < $detailTotal) {
            $parts[] = "carried {$detailHits} of {$detailTotal} other key details";
        }
        if ($parts !== []) {
            return implode('; ', $parts);
        }

        return $verdict === SummarySafeguardVerdict::PASS
            ? 'every section written and every tracked fact carried'
            : 'complete on its own terms';
    }
}
