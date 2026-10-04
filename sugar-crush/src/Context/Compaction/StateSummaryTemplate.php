<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Compaction;

use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * The holistic STATE block a compaction leaves for the model (roadmap 2.5),
 * layered on top of the per-exchange six-facet records.
 *
 * WHY A SECOND SHAPE. The six-facet records keep each exchange's facts — and
 * they are good at it, a security constraint's exact wording included — but a
 * resumed conversation also needs to know where the WORK stands: what the goal
 * is, what is done and what is not, what to do next, and what the user last
 * asked for that has not been answered. Read off a pile of per-exchange
 * records, that is an inference the model has to redo on every turn, and the
 * one thing it most often gets wrong. So every compaction also writes one block
 * with fixed headings ({@see HEADINGS}) — the shape Claude Code, OpenCode, Kilo,
 * OpenHands, Goose, OpenClaw and Cline converge on.
 *
 * WHO WRITES WHICH HEADING:
 *  - the summarising model writes {@see MODEL_HEADINGS} (it is asked for them
 *    inside `<session-state>` tags by
 *    {@see \SugarCraft\Crush\Host\CompactionService::COMPACT_SUMMARY_PROMPT});
 *  - {@see DERIVED_HEADINGS} are filled MECHANICALLY and a model's version of
 *    them is discarded: the files come from {@see FilesTouched} (the tool rows),
 *    the latest request is the user's own text, verbatim. A model never gets to
 *    paraphrase either.
 *
 * AUDITED, NEVER TRUSTED WHOLE. A model block missing a required heading is not
 * rejected outright — {@see filledFrom()} fills each missing or empty section
 * from the heuristic block (the OpenClaw safeguard) — and with no model at all
 * the heuristic block ({@see fromPairs()}, {@see fromHistory()}) is written with
 * the same headings, so the shape the model reads never depends on whether a
 * provider was configured.
 *
 * MERGED, NOT STACKED. A compaction finds the previous state row and merges it
 * ({@see mergedWith()}): file lists union, and an empty section of the new
 * block keeps the old one's text. The previous row itself is not carried on as
 * a second, truncated copy.
 *
 * BOUNDED at {@see MAX_CHARS}: a state block that grew without limit would let
 * a compaction carry more than it replaced.
 *
 * The block ends with {@see CONTINUE_LINE}, after the user's latest request —
 * the "re-append the prompt, continue if there are next steps" step the other
 * agents run after compacting, carried inside the summary so it holds on every
 * route, including one that sends no turn.
 */
final class StateSummaryTemplate
{
    /** First line of a rendered block; a stored row is `[summary] ` + this. */
    public const HEADER = 'Session state (compacted):';

    /** The marker every stored compaction row carries (see CompactionService::SUMMARY_ROW_PREFIX). */
    public const ROW_PREFIX = '[summary] ';

    /**
     * Where a rendered block rides in a {@see \SugarCraft\Crush\Context\ContextCompactor}
     * exchange-summary map. Exchange keys are SHA-256 hex digests, so a key with
     * `@` in it can never collide with one.
     */
    public const SUMMARY_KEY = '@session-state';

    /** The tags the summarising model writes its block between. */
    public const OPEN_TAG = '<session-state>';

    public const CLOSE_TAG = '</session-state>';

    /** The longest rendered block, in characters (roadmap 2.5: "~16k"). */
    public const MAX_CHARS = 16000;

    /** Headings the summarising model writes, in render order. */
    public const MODEL_HEADINGS = [
        'Goal',
        'Constraints',
        'Progress',
        'Key decisions',
        'Current work',
        'Next step',
        'Pending tasks',
        'Errors and fixes',
    ];

    /** Headings filled mechanically; a model's text under them is discarded. */
    public const DERIVED_HEADINGS = ['Files read', 'Files modified', 'Latest unresolved user request'];

    /** Every heading, in the order a block renders them. */
    public const HEADINGS = [
        'Goal',
        'Constraints',
        'Progress',
        'Key decisions',
        'Current work',
        'Next step',
        'Pending tasks',
        'Files read',
        'Files modified',
        'Errors and fixes',
        'Latest unresolved user request',
    ];

    /** The three sub-headings the Progress section is asked to carry. */
    public const PROGRESS_SUBHEADINGS = ['Done', 'In progress', 'Blocked'];

    /** What an empty section renders as. */
    public const EMPTY = 'none';

    /** The latest-request section of a block that only saw the condensed exchanges. */
    public const LATEST_IN_TAIL = 'the most recent exchanges follow this summary verbatim';

    /** The closing instruction every block ends with. */
    public const CONTINUE_LINE = 'Continue with the next step if there is one; otherwise wait for the user.';

    /** Bound on a heuristic section built from one message's text. */
    private const HEURISTIC_EXCERPT_CHARS = 300;

    /** Bound on the verbatim latest request. */
    private const LATEST_REQUEST_MAX_CHARS = 2000;

    /** How many recent tool errors the heuristic block keeps, and how much of each. */
    private const HEURISTIC_ERRORS = 5;

    private const HEURISTIC_ERROR_CHARS = 300;

    /**
     * @param array<string, string> $sections heading => body; a heading absent
     *        from the map was never written (what {@see missingHeadings()} audits)
     */
    private function __construct(private readonly array $sections = [])
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /** @throws \InvalidArgumentException for a heading that is not one of {@see HEADINGS} */
    public function withSection(string $heading, string $body): self
    {
        if (!\in_array($heading, self::HEADINGS, true)) {
            throw new \InvalidArgumentException("'{$heading}' is not a state-summary heading.");
        }

        return new self([...$this->sections, $heading => trim($body)]);
    }

    /** The body under $heading, '' when absent or empty. */
    public function section(string $heading): string
    {
        return $this->sections[$heading] ?? '';
    }

    /** This block with the two file sections set from $files. */
    public function withFiles(FilesTouched $files): self
    {
        return $this
            ->withSection('Files read', self::bullets($files->read))
            ->withSection('Files modified', self::bullets($files->modified));
    }

    /**
     * The model headings a parsed block never wrote — the audit (roadmap 2.5).
     * A heading that was written with an empty body counts as written.
     *
     * @return list<string>
     */
    public function missingHeadings(): array
    {
        return array_values(array_filter(
            self::MODEL_HEADINGS,
            fn (string $heading): bool => !\array_key_exists($heading, $this->sections),
        ));
    }

    /**
     * This block with every MODEL heading that is missing or empty taken from
     * $fallback, and every DERIVED heading taken from $fallback outright — a
     * derived section is never the model's to write.
     */
    public function filledFrom(self $fallback): self
    {
        $sections = $this->sections;
        foreach (self::HEADINGS as $heading) {
            $derived = \in_array($heading, self::DERIVED_HEADINGS, true);
            if ($derived || self::isBlank($sections[$heading] ?? '')) {
                $sections[$heading] = $fallback->section($heading);
            }
        }

        return new self($sections);
    }

    /**
     * This block merged with the $previous compaction's: the file lists are
     * unioned (this block's paths first), and any other section this block left
     * empty keeps $previous's text.
     */
    public function mergedWith(self $previous): self
    {
        $sections = $this->sections;
        foreach (self::HEADINGS as $heading) {
            $mine = $sections[$heading] ?? '';
            $theirs = $previous->section($heading);
            if ($heading === 'Files read' || $heading === 'Files modified') {
                $sections[$heading] = self::bullets(array_values(array_unique([
                    ...self::bulletItems($mine),
                    ...self::bulletItems($theirs),
                ])));
            } elseif (self::isBlank($mine) && !self::isBlank($theirs)) {
                $sections[$heading] = $theirs;
            }
        }

        // A path the new block lists as modified is no longer merely read.
        $modified = self::bulletItems($sections['Files modified'] ?? '');
        $sections['Files read'] = self::bullets(array_values(array_filter(
            self::bulletItems($sections['Files read'] ?? ''),
            static fn (string $path): bool => !\in_array($path, $modified, true),
        )));

        return new self($sections);
    }

    /**
     * The block as the model reads it: {@see HEADER}, every heading in
     * {@see HEADINGS} order (empty ones as {@see EMPTY}, so the shape never
     * varies), then {@see CONTINUE_LINE}. Never longer than {@see MAX_CHARS}:
     * the longest section is halved until it fits, marked where it was cut.
     */
    public function render(): string
    {
        $sections = [];
        foreach (self::HEADINGS as $heading) {
            $sections[$heading] = self::isBlank($this->section($heading)) ? self::EMPTY : $this->section($heading);
        }

        $text = self::compose($sections);
        while (mb_strlen($text) > self::MAX_CHARS) {
            $longest = array_key_first($sections);
            foreach ($sections as $heading => $body) {
                if (mb_strlen($body) > mb_strlen($sections[$longest])) {
                    $longest = $heading;
                }
            }
            $length = mb_strlen($sections[$longest]);
            if ($length <= 40) {
                // Every section is already short; only a hard cut is left.
                return mb_substr($text, 0, self::MAX_CHARS - 1) . '…';
            }
            $sections[$longest] = mb_substr($sections[$longest], 0, intdiv($length, 2)) . ' …[trimmed]';
            $text = self::compose($sections);
        }

        return $text;
    }

    /**
     * The model's state block and the rest of its reply: the LAST
     * {@see OPEN_TAG}…{@see CLOSE_TAG} span is the block (null when there is
     * none), and the reply with that span removed goes on to the record parse,
     * where block lines would otherwise read as facet continuations.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function extractFromReply(string $reply): array
    {
        $open = strrpos($reply, self::OPEN_TAG);
        if ($open === false) {
            return [null, $reply];
        }
        $bodyStart = $open + \strlen(self::OPEN_TAG);
        $close = strpos($reply, self::CLOSE_TAG, $bodyStart);
        $body = $close === false ? substr($reply, $bodyStart) : substr($reply, $bodyStart, $close - $bodyStart);
        $rest = substr($reply, 0, $open) . ($close === false ? '' : substr($reply, $close + \strlen(self::CLOSE_TAG)));

        return [trim($body), $rest];
    }

    /**
     * A block parsed from `## Heading` lines. A `##` line naming no heading —
     * or a `###` sub-heading — stays content of the section above it; text
     * before the first heading (the {@see HEADER} line included) is dropped.
     * A body of just "none" is an empty section that WAS written.
     */
    public static function parse(string $text): self
    {
        $byLower = [];
        foreach (self::HEADINGS as $heading) {
            $byLower[strtolower($heading)] = $heading;
        }

        $sections = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*##\s+(.+?)\s*:?\s*$/u', $line, $m) === 1 && !str_starts_with(ltrim($line), '###')) {
                $heading = $byLower[strtolower($m[1])] ?? null;
                if ($heading !== null) {
                    $current = $heading;
                    $sections[$current] = '';
                    continue;
                }
            }
            if ($current === null || trim($line) === self::CONTINUE_LINE) {
                continue;
            }
            $sections[$current] .= ($sections[$current] === '' ? '' : "\n") . rtrim($line);
        }

        foreach ($sections as $heading => $body) {
            $body = trim($body);
            $sections[$heading] = self::isBlank($body) ? '' : $body;
        }

        return new self($sections);
    }

    /** Whether $content is a stored state row (with or without the row marker). */
    public static function isStateRow(string $content): bool
    {
        $body = str_starts_with($content, self::ROW_PREFIX) ? substr($content, \strlen(self::ROW_PREFIX)) : $content;

        return str_starts_with($body, self::HEADER);
    }

    /** The block a stored state row carries, or null when $content is not one. */
    public static function fromRow(string $content): ?self
    {
        return self::isStateRow($content) ? self::parse($content) : null;
    }

    /**
     * The newest state row among $rows' contents, parsed, or null.
     *
     * @param iterable<string> $rows
     */
    public static function latestIn(iterable $rows): ?self
    {
        $latest = null;
        foreach ($rows as $content) {
            $latest = self::fromRow($content) ?? $latest;
        }

        return $latest;
    }

    /**
     * The heuristic block for a stretch of {@see \SugarCraft\Crush\Context\ContextCompactor}
     * pairs — what the compactor writes when nobody supplied a block. Goal is the
     * first request, current work the last reply, and the latest request the last
     * user text verbatim; nothing is invented for the rest.
     *
     * @param array<array{user?:string,assistant?:?string,standalone?:bool}> $pairs
     */
    public static function fromPairs(array $pairs): self
    {
        $exchanges = [];
        foreach ($pairs as $pair) {
            if (($pair['standalone'] ?? false) === true) {
                continue;
            }
            $exchanges[] = ['user' => (string) ($pair['user'] ?? ''), 'assistant' => (string) ($pair['assistant'] ?? '')];
        }

        // These are the CONDENSED pairs only: their last request is not the
        // conversation's latest, which sits in the preserved exchanges after
        // this block — so the section points there rather than mislabel one.
        return self::fromExchanges($exchanges)
            ->withSection('Latest unresolved user request', self::LATEST_IN_TAIL);
    }

    /**
     * The heuristic block for a whole history: {@see fromPairs()}'s sections
     * over its agent-visible user/assistant rows, plus the files its tool rows
     * touched ({@see FilesTouched}) and its most recent tool errors verbatim —
     * the facts only the rows themselves carry.
     *
     * @param list<Message> $history
     */
    public static function fromHistory(array $history): self
    {
        $exchanges = [];
        $errors = [];
        foreach (Message::agentVisible($history) as $message) {
            if ($message->role === Role::User && !self::isStateRow($message->content)) {
                $exchanges[] = ['user' => $message->content, 'assistant' => ''];
            } elseif ($message->role === Role::Assistant && $exchanges !== [] && !str_starts_with($message->content, self::ROW_PREFIX)) {
                $exchanges[array_key_last($exchanges)]['assistant'] = $message->content;
            }
            foreach ($message->toolResults as $result) {
                if ($result->isError()) {
                    $errors[] = $result->name . ': ' . self::excerpt((string) ($result->error ?? $result->result), self::HEURISTIC_ERROR_CHARS);
                }
            }
        }

        return self::fromExchanges($exchanges)
            ->withFiles(FilesTouched::fromHistory($history))
            ->withSection('Errors and fixes', self::bullets(\array_slice($errors, -self::HEURISTIC_ERRORS)));
    }

    /**
     * @param list<array{user:string,assistant:string}> $exchanges
     */
    private static function fromExchanges(array $exchanges): self
    {
        $asked = array_values(array_filter($exchanges, static fn (array $e): bool => trim($e['user']) !== ''));
        $answered = array_values(array_filter($exchanges, static fn (array $e): bool => trim($e['assistant']) !== ''));

        $block = self::new()->withSection(
            'Progress',
            "### Done\n- " . \count($exchanges) . ' ' . (\count($exchanges) === 1 ? 'exchange' : 'exchanges')
                . " recorded (heuristic record: no model wrote this block)\n### In progress\n- " . self::EMPTY
                . "\n### Blocked\n- " . self::EMPTY,
        );

        if ($asked !== []) {
            $block = $block
                ->withSection('Goal', self::excerpt($asked[0]['user'], self::HEURISTIC_EXCERPT_CHARS))
                ->withSection('Latest unresolved user request', self::excerpt(
                    $asked[array_key_last($asked)]['user'],
                    self::LATEST_REQUEST_MAX_CHARS,
                ));
        }
        if ($answered !== []) {
            $block = $block->withSection('Current work', self::excerpt(
                $answered[array_key_last($answered)]['assistant'],
                self::HEURISTIC_EXCERPT_CHARS,
            ));
        }

        return $block;
    }

    /** @param array<string, string> $sections */
    private static function compose(array $sections): string
    {
        $out = [self::HEADER];
        foreach ($sections as $heading => $body) {
            $out[] = '## ' . $heading;
            $out[] = $body;
        }
        $out[] = self::CONTINUE_LINE;

        return implode("\n", $out);
    }

    /** @param list<string> $items */
    private static function bullets(array $items): string
    {
        return implode("\n", array_map(static fn (string $item): string => '- ' . $item, $items));
    }

    /** @return list<string> */
    private static function bulletItems(string $body): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $item = trim(preg_replace('/^\s*[-*]\s*/u', '', $line) ?? $line);
            if ($item !== '' && !self::isBlank($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function isBlank(string $body): bool
    {
        $normalised = strtolower(trim($body, " \t\n\r\0\x0B.-"));

        return $normalised === '' || $normalised === 'none' || $normalised === self::EMPTY;
    }

    private static function excerpt(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
