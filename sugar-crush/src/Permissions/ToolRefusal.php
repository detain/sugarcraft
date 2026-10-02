<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\Events\ToolFinished;

/**
 * One tool call that was STOPPED, recovered from the lifecycle event stream:
 * which of the three ways it was stopped, what was stopped, and the text the
 * model was handed (E292, E300).
 *
 * WHY THIS EXISTS AT ALL — TWO CLASSIFIERS, ONE ROSTER, AND THE ROSTER MOVED
 * OUT FROM UNDER ONE OF THEM. Two headless surfaces answer the same question
 * about the same event and neither can see the other:
 * {@see \SugarCraft\Crush\Cli\NonInteractive::refusalFrom()} builds the
 * `refusals` array of a `--output-format json` document, and
 * {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::noticeRefusal()}
 * writes one line into a daemon's sidecar log. Both were the same shape —
 * "is this a `ToolFinished`, did it error, does its text open with a roster
 * entry" — spelled twice in two files, one of them `private`. The duplicate
 * was recorded as tolerable on the grounds that both read ONE roster, so two
 * copies of the SHAPE could not disagree about what a refusal IS. That was
 * true of the shape and false of the reader: the daemon's copy reached the
 * roster through `Chat::DENIED_ERROR_PREFIXES`, i.e. through the TUI model,
 * which is exactly the load E239 moved the roster to
 * {@see DenialKind} to avoid — so the daemon paid for a `Chat` on every
 * errored tool result while the `-p` path next to it no longer did.
 *
 * THE KIND IS THE POINT, NOT THE DEDUPLICATION (E250). `DenialKind` was a
 * type nothing consumed as one: `Runtime` computed a case, rendered it with
 * {@see DenialKind::reason()}, and from there every party re-derived the
 * classification from the rendered string. This is the seam where the enum
 * itself crosses — the classifier answers with a CASE, and a consumer that
 * needs to tell "a hook objected" from "there was nobody to ask" branches on
 * {@see $kind} instead of re-implementing `str_starts_with` against a roster
 * it would have to import anyway. {@see \SugarCraft\Crush\Cli\NonInteractive}
 * carries it out to a machine consumer as the `kind` field of a `refusals`
 * entry, which is the boundary where re-deriving it was most expensive: a
 * shell script reading that JSON cannot import an enum at all.
 *
 * NOT ON `DenialKind` ITSELF, DELIBERATELY. That enum has no `use` statements
 * and depends on nothing in this application, which is the property that let
 * the engine, the TUI and the headless CLI all name it; a static factory
 * taking a {@see ToolFinished} would put an `Events\` dependency on the leaf
 * and hand it back the coupling it was extracted to remove. This class is
 * allowed the dependency because it is not the roster — it is one reader of
 * it, and every caller of this class is already holding the event.
 *
 * AND IT NO LONGER READS THE ROSTER AT ALL (audit F-P8). The shape described
 * above — "did it error, does its text open with a roster entry" — let any
 * tool forge a refusal by printing a roster prefix. The kind now travels on
 * the result ({@see \SugarCraft\Crush\Tools\ToolResult::denial()}), stamped by
 * the party that refused the call, and {@see fromEvent()} reads only that.
 */
final readonly class ToolRefusal
{
    private function __construct(
        /** Which of the three ways the call was stopped. */
        public DenialKind $kind,
        /** The runtime tool name, as the model called it. */
        public string $tool,
        /**
         * The finished result text the model was handed. For a refusal the
         * engine built it opens with {@see $kind}'s prefix; that is a property
         * of how refusals are WRITTEN, not how they are recognised.
         */
        public string $reason,
    ) {}

    /**
     * The refusal $event announces, or null when it announces none.
     *
     * THE KIND IS READ OFF THE RESULT, NEVER OFF ITS TEXT (audit F-P8). This
     * used to hand the error text to {@see DenialKind::classify()}, and the
     * text of an errored result is whatever the tool produced: a Bash
     * `printf 'Permission denied: …'; touch marker; exit 1`, or an MCP server
     * answering `isError` with that sentence, came out of here as a policy
     * refusal — so a `--output-format json` document's `refusals` array and a
     * daemon's sidecar log reported a call as BLOCKED that had in fact run,
     * side effects and all. The party that refuses a call (the engine's gate,
     * the TUI's prompt) now stamps {@see \SugarCraft\Crush\Tools\ToolResult::denial()}
     * when it builds the result, and that field is the only thing consulted.
     * E308 closed the same hole for tools that THROW; this closes it for the
     * ones that simply fail.
     *
     * NULL FOR EVERYTHING ELSE, which is three different things: an event that
     * is not a {@see ToolFinished} at all (every other lifecycle event on the
     * stream), a call whose tool RAN AND FAILED — a `Read` on a missing path, a
     * `Bash` exiting non-zero, whatever its output says — which the model is
     * expected to act on, and a successful call.
     *
     * `object` rather than `ToolFinished` in the signature because both
     * callers are handed an untyped observer callback's argument and the
     * `instanceof` has to happen somewhere; doing it here is what removes it
     * from both of them.
     */
    public static function fromEvent(object $event): ?self
    {
        if (!$event instanceof ToolFinished || !$event->result->isError()) {
            return null;
        }

        $kind = $event->result->denial();

        return $kind === null ? null : new self($kind, $event->toolName, $event->result->content());
    }
}
