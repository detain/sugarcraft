<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers\ToolCallParser;

/**
 * Positional reader for the XML-ish markup both text-scanning tool-call
 * parsers in this namespace have to recover calls from.
 *
 * It exists to fix three defects that were shared by
 * {@see DsmlToolCallParser} and {@see MinimaxXmlFallbackToolCallParser}
 * because the first copied the second's shape. All three were measured on the
 * unfixed code, not inferred.
 *
 * ---------------------------------------------------------------------------
 * 1. A CONTAINMENT TEST CANNOT TELL AN ACTION FROM A QUOTATION.
 *
 * Both parsers gated their scan on `str_contains($content, $marker)` - the
 * marker appearing ANYWHERE meant "this turn calls a tool". Measured:
 *
 * ```
 * "Sure! To call a tool you emit markup like this:
 *
 * ```
 * <｜DSML｜tool_calls><｜DSML｜invoke name="rm_rf">…string="true">/…
 * ```
 *
 * That's the DSML format. I have not actually called anything."
 *   => 1 call - name=rm_rf args={"path":"/"}
 * ```
 *
 * The identical prose written against `<minimax:tool_call>` fabricated the
 * identical `rm_rf` call. This is not a contrived prompt: DeepSeek-V4's own
 * `render_tools` (`encoding_dsv4.py:84-94`) puts a worked DSML example INTO
 * the system prompt, so "how does tool calling work?" is answered by quoting
 * that example back. A FABRICATED call that then executes is strictly worse
 * than a missed one - it is the exact inversion of the failure these parsers
 * exist to prevent. {@see qualifies()} is the guard.
 *
 * ---------------------------------------------------------------------------
 * 2. A LAZY `(.*?)` OVER A LARGE BODY FALLS OFF `pcre.backtrack_limit`.
 *
 * Measured on the unfixed DSML parser with the default limit of 1,000,000 and
 * one `string="true"` parameter value:
 *
 * ```
 * value   900000 bytes => 1 call, content len 900000
 * value  1000000 bytes => NULL  (the call was LOST)
 * ```
 *
 * A `Write` of a 1 MB file against a 1,048,570-token context window is
 * ordinary agent traffic, not an edge case, and the failure was a cliff:
 * total, and misdiagnosed downstream. Nothing on this class's path uses PCRE -
 * `strpos()` has no backtracking and no limit - so the cliff does not exist
 * rather than being detected and reported, which is the better of the two
 * outcomes. Both parsers are now free of `preg_*` entirely, which
 * {@see \SugarCraft\Crush\Tests\Providers\ToolCallParser\MarkupScannerTest}
 * pins as a property of their source rather than of one input size.
 *
 * ---------------------------------------------------------------------------
 * 3. AN ATTRIBUTE PATTERN THAT HARD-REQUIRES ONE SPELLING DROPS THE WHOLE
 *    PARAMETER.
 *
 * `#<parameter name="(.*?)" string="(.*?)">#s` matches nothing at all when the
 * model writes `string='true'`, `string=true`, or omits the flag - so the
 * parameter vanished and the call fired with the argument MISSING: `read()`
 * with no `path`, `write()` with no `content`. {@see scanAttributes()} reads
 * attributes the way an XML reader does, so a quoting variant is recovered
 * rather than silently discarded, and a genuinely unreadable one is visible to
 * the caller as an absent key instead of being indistinguishable from "the
 * model did not send it".
 */
final readonly class MarkupScanner
{
    /**
     * A Markdown code-fence delimiter. Three backticks, matched anywhere
     * rather than only at a line start, because a model writing a fence inline
     * is the same quotation signal and the toggle stays balanced either way.
     */
    private const FENCE = '```';

    private const WHITESPACE = " \t\r\n\0\x0B";

    /**
     * @param string $tagPrefix Sits between `<` and the tag name: `｜DSML｜`
     *        for DeepSeek-V4, the empty string for MiniMax's plain XML.
     * @param bool $requireStartToken Whether an envelope must ALSO sit at a
     *        position that reads as an action rather than a quotation - see
     *        {@see qualifies()}, which is where the two protocols' guards
     *        deliberately differ and why.
     */
    public function __construct(
        private string $tagPrefix,
        private bool $requireStartToken,
    ) {}

    /**
     * Default root factory, per repo convention.
     */
    public static function new(string $tagPrefix, bool $requireStartToken): self
    {
        return new self($tagPrefix, $requireStartToken);
    }

    /**
     * The envelope spans that qualify as ACTIONS, in document order.
     *
     * An envelope ends at its close tag or at end-of-content; unlike
     * {@see elements()} there is deliberately NO "next sibling opens" rule
     * here, because a tool call's own payload may legitimately contain the
     * literal envelope marker (a `write` of a document that quotes it), and
     * terminating on that would split one call into two.
     *
     * @return list<array{body: string, offset: int, closed: bool}>
     */
    public function envelopes(string $content, string $tag): array
    {
        $open = '<' . $this->tagPrefix . $tag;
        $close = '</' . $this->tagPrefix . $tag . '>';

        $spans = [];
        $pos = 0;
        // Carried forward rather than recomputed, so the walk stays linear.
        $fenceScannedTo = 0;
        $inFence = false;
        $acceptedEnd = 0;

        while (($at = $this->findTag($content, $open, $pos)) !== null) {
            $inFence = $this->fenceStateAt($content, $fenceScannedTo, $at, $inFence);
            $fenceScannedTo = $at;

            if ($inFence || !$this->qualifies($content, $at, $acceptedEnd)) {
                // Prose quoting the protocol. Skip ONLY the open tag: the text
                // after it - including the fence that closes the quotation, and
                // any genuine envelope beyond - still has to be walked.
                $pos = $at + \strlen($open);

                continue;
            }

            $bodyStart = $this->scanAttributes($content, $at + \strlen($open))[1];

            if ($bodyStart === null) {
                // An open tag with no `>` at all; there is no body to read and
                // nothing can follow it.
                break;
            }

            $closeAt = strpos($content, $close, $bodyStart);

            if ($closeAt === false) {
                $spans[] = ['body' => substr($content, $bodyStart), 'offset' => $at, 'closed' => false];

                break;
            }

            $spans[] = [
                'body' => substr($content, $bodyStart, $closeAt - $bodyStart),
                'offset' => $at,
                'closed' => true,
            ];

            $pos = $acceptedEnd = $closeAt + \strlen($close);

            // An ACCEPTED envelope's payload is skipped wholesale and the fence
            // cursor jumps past it. That is what makes the fence guard safe on
            // real traffic: a genuine `write` whose file content holds an odd
            // number of ``` fences would otherwise flip the toggle and suppress
            // the NEXT genuine envelope.
            $fenceScannedTo = $pos;
        }

        return $spans;
    }

    /**
     * Every `<$tag …>` element inside an already-accepted body, in document
     * order. No quotation guard applies here: the enclosing envelope has
     * already been judged an action, and its interior is markup by definition.
     *
     * `terminator` says HOW the element ended, because the callers' recovery
     * policy turns on exactly that:
     *
     * - `close`   - a matching close tag. Well formed.
     * - `sibling` - another `<$tag` opened first. Either the generation was cut
     *               short and restarted, or the model nested the element;
     *               neither is well formed and the body is incomplete.
     * - `eof`     - the content ran out. Truncated.
     * - `open`    - the open tag itself has no `>`. Nothing is readable.
     *
     * @return list<array{attributes: array<string, string>, body: string, offset: int, terminator: string}>
     */
    public function elements(string $content, string $tag): array
    {
        $open = '<' . $this->tagPrefix . $tag;
        $close = '</' . $this->tagPrefix . $tag . '>';

        $found = [];
        $pos = 0;

        while (($at = $this->findTag($content, $open, $pos)) !== null) {
            [$attributes, $bodyStart] = $this->scanAttributes($content, $at + \strlen($open));

            if ($bodyStart === null) {
                $found[] = ['attributes' => $attributes, 'body' => '', 'offset' => $at, 'terminator' => 'open'];

                break;
            }

            $closeAt = strpos($content, $close, $bodyStart);
            $siblingAt = $this->findTag($content, $open, $bodyStart);

            if ($closeAt !== false && ($siblingAt === null || $closeAt < $siblingAt)) {
                $end = $closeAt;
                $terminator = 'close';
                $pos = $closeAt + \strlen($close);
            } elseif ($siblingAt !== null) {
                $end = $siblingAt;
                $terminator = 'sibling';
                $pos = $siblingAt;
            } else {
                $end = \strlen($content);
                $terminator = 'eof';
                $pos = $end;
            }

            $found[] = [
                'attributes' => $attributes,
                'body' => substr($content, $bodyStart, $end - $bodyStart),
                'offset' => $at,
                'terminator' => $terminator,
            ];
        }

        return $found;
    }

    /**
     * Whether an envelope open tag at `$offset` reads as an ACTION.
     *
     * THE TWO PROTOCOLS GET DIFFERENT GUARDS, AND THE ASYMMETRY IS
     * EVIDENCE-SHAPED RATHER THAN AESTHETIC.
     *
     * DeepSeek-V4's start token literally embeds two newlines -
     * `f"\n\n<{dsml_token}{tool_calls_block_name}"` (`enc.py:726`) - so the
     * model is trained to emit them and requiring them costs no genuine call.
     * MiniMax's XML has NO documented positional convention, and this class
     * will not invent one for it: a `\n\n` rule there would be a rule with no
     * upstream backing whose false negatives - genuine calls dropped - are
     * unbounded precisely because there is no spec to bound them against. So
     * MiniMax gets the fence guard alone, which is weaker. That is the honest
     * consequence of having weaker evidence, not an oversight.
     *
     * Three positions qualify under `$requireStartToken`:
     *
     * 1. Only whitespace separates this tag from the end of the previous
     *    ACCEPTED envelope (or from the start of the content). A completion
     *    that opens with the envelope has no preceding prose for a separator
     *    to separate, and markup adjacent to markup already judged an action
     *    is a continuation of that action, not a quotation of it.
     * 2. `\n\n` immediately precedes it - the documented start token.
     * 3. Nothing else.
     *
     * WHICH WAY THIS LEANS, STATED ON PURPOSE. Every increment of strictness
     * trades a fabricated call against a missed one, and this leans STRICT.
     * The justification is specific to this path rather than general: a
     * text-scanning parser is reached only when `tool_calls[]` was absent,
     * i.e. only on a server launched without a `--tool-call-parser` flag. A
     * missed call there degrades to "the agent does nothing", which is that
     * deployment's behaviour with no fallback armed at all - recoverable, and
     * visible to the operator. A fabricated call EXECUTES. The costs are not
     * symmetric, so the guard is not balanced.
     *
     * KNOWN AND DELIBERATE GAP: prose that quotes an envelope after a blank
     * line and outside a code fence still qualifies. Nothing positional can
     * separate that from a real call; only the tool schema could, and this
     * class is not handed one.
     */
    private function qualifies(string $content, int $offset, int $acceptedEnd): bool
    {
        if (!$this->requireStartToken) {
            return true;
        }

        if (trim(substr($content, $acceptedEnd, $offset - $acceptedEnd)) === '') {
            return true;
        }

        return $offset >= 2 && substr($content, $offset - 2, 2) === "\n\n";
    }

    /**
     * `strpos` for an open tag, rejecting a longer tag that merely starts with
     * it: `<invoke` must not match `<invoker`.
     */
    private function findTag(string $content, string $open, int $from): ?int
    {
        $length = \strlen($content);

        while ($from <= $length && ($at = strpos($content, $open, $from)) !== false) {
            $next = $at + \strlen($open);

            if ($next >= $length || strpbrk($content[$next], self::WHITESPACE . '>/') !== false) {
                return $at;
            }

            $from = $next;
        }

        return null;
    }

    /**
     * Reads an open tag's attributes the way an XML reader does, starting just
     * past the tag name.
     *
     * TOLERANT ON PURPOSE, because the alternative was measured and it was
     * silent data loss: a single-quoted, unquoted or absent `string=` flag made
     * the reference pattern fail to match at all, and the parameter - with its
     * value - disappeared while the tool call still fired. Recovering the value
     * the model actually sent and letting the caller decide about a missing
     * FLAG is strictly more information than dropping both.
     *
     * A quoted value may contain `>`; the terminator is only recognised outside
     * quotes.
     *
     * @return array{0: array<string, string>, 1: int|null} Attributes, and the
     *         offset just past the `>` that ends the open tag - or null when
     *         there is no such `>`.
     */
    private function scanAttributes(string $content, int $at): array
    {
        $attributes = [];
        $length = \strlen($content);

        while ($at < $length) {
            $at += \strspn($content, self::WHITESPACE, $at);

            if ($at >= $length) {
                break;
            }

            if ($content[$at] === '>') {
                return [$attributes, $at + 1];
            }

            if ($content[$at] === '/' && ($content[$at + 1] ?? '') === '>') {
                return [$attributes, $at + 2];
            }

            $nameEnd = \strcspn($content, self::WHITESPACE . '=>/', $at) + $at;
            $name = substr($content, $at, $nameEnd - $at);

            if ($name === '') {
                // A stray character where an attribute name was expected.
                // Stepping over it guarantees the loop terminates.
                ++$at;

                continue;
            }

            $at = $nameEnd + \strspn($content, self::WHITESPACE, $nameEnd);

            if (($content[$at] ?? '') !== '=') {
                // A valueless attribute. Recorded as present-but-empty so a
                // caller can tell it from one that was never written.
                $attributes[$name] = '';

                continue;
            }

            ++$at;
            $at += \strspn($content, self::WHITESPACE, $at);
            $quote = $content[$at] ?? '';

            if ($quote === '"' || $quote === "'") {
                $end = strpos($content, $quote, $at + 1);

                if ($end === false) {
                    // An unterminated quoted value swallows the rest of the
                    // content; there is no `>` to be found.
                    return [$attributes, null];
                }

                $attributes[$name] = substr($content, $at + 1, $end - $at - 1);
                $at = $end + 1;

                continue;
            }

            $valueEnd = \strcspn($content, self::WHITESPACE . '>', $at) + $at;
            $attributes[$name] = substr($content, $at, $valueEnd - $at);
            $at = $valueEnd;
        }

        return [$attributes, null];
    }

    /**
     * Advances the fence toggle from `$from` to `$to` and reports the state at
     * `$to`.
     */
    private function fenceStateAt(string $content, int $from, int $to, bool $inFence): bool
    {
        $pos = $from;

        while (($fence = strpos($content, self::FENCE, $pos)) !== false && $fence < $to) {
            $inFence = !$inFence;
            $pos = $fence + \strlen(self::FENCE);
        }

        return $inFence;
    }
}
