<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * The three ways a tool call is stopped BEFORE it runs, as a type rather than
 * as a string prefix (E239).
 *
 * WHY THIS IS NOT ON `Chat`, WHICH IS WHERE IT LIVED. The roster of
 * "error texts that mean the call never ran" was
 * `Chat::DENIED_ERROR_PREFIXES`, i.e. a constant on this application's TUI
 * model. Two surfaces classify against it — the renderer, to draw a refusal
 * struck through, and {@see \SugarCraft\Crush\Cli\NonInteractive::refusalFrom()},
 * to fill a `--output-format json` document's `refusals` array — and the
 * ENGINE, which is the party that AUTHORS every one of those texts, could not
 * read it at all: touching it from {@see \SugarCraft\Crush\Runtime::gate()}
 * would load `Chat` on the first gated tool call of every run, including the
 * `-p` one-shot path that exists partly so a run never builds one. So the
 * engine carried a pinned copy and a test enforced the coupling.
 *
 * THAT AUTOLOAD COST IS NOT HISTORY, AND IT IS WHY THIS LEAF MUST STAY
 * DEPENDENCY-FREE. MEASURED on PHP 8.3.6 at round 49 from a bare
 * `vendor/autoload.php`: `class_exists(Chat::class, false)` is FALSE after
 * {@see self::classify()} has answered, and TRUE on the very next line after
 * reading `Chat::DENIED_ERROR_PREFIXES`. Reading the roster through this enum
 * costs nothing; reading the same three strings through `Chat` still pulls in
 * the whole TUI model. Anything added to this file that needs a `use`
 * statement re-opens the objection above for every party that reads it.
 *
 * This enum is the neutral leaf that removes the reason for the copy. It has
 * no `use` statements and depends on nothing in this application, so any
 * party — engine, TUI, headless CLI — can name a KIND and let the rendering
 * happen in exactly one place.
 *
 * THE ENGINE'S COPY IS GONE (E246). WHAT THIS DOC-BLOCK SAID: that
 * `src/Runtime.php` was owned by another concurrent lane when this leaf
 * landed, so `Runtime::DENIAL_HOOK` / `DENIAL_REFUSED` / `DENIAL_UNANSWERED`
 * "are still three string literals", and that re-pointing them at these three
 * cases is the last step of E239. WHAT IS TRUE NOW: that step landed. Those
 * three constants are declared as `DenialKind::<Case>->value` constant
 * expressions — deprecated aliases that DERIVE rather than a fourth copy —
 * `src/Runtime.php` spells no denial literal at all, and
 * {@see \SugarCraft\Crush\Runtime::gate()} holds a case and renders it once
 * through {@see reason()}. WHY THIS STILL EARNS ITS PLACE: the aliases remain
 * `public const` on a class an embedder reads, so the tree publishes FOUR
 * names for three kinds, and
 * {@see \SugarCraft\Crush\Tests\DenialPrefixRosterTest} is still what keeps
 * every one of them agreeing. It gained a spelled-out pin on the three
 * BACKING VALUES when the copy went, because once both sides of its
 * membership test derive from this enum, that test can no longer see a case
 * being respelled.
 *
 * THE SPELLINGS ARE NOT FREE CHOICES. Each case's backing value is the exact
 * text a finished reason OPENS with, and
 * {@see \SugarCraft\Crush\Chat::isDeniedResult()} matches it case-sensitively
 * with `str_starts_with`. A spelling invented anywhere else that is not on
 * this list is a BLOCKED call rendered as an ordinary tool ERROR on both
 * surfaces — the model told its call failed rather than that it was refused,
 * which is a correctness failure and not a cosmetic one.
 */
enum DenialKind: string
{
    /**
     * An ASK an attached approver answered with anything other than a literal
     * `true` — the user's own decision, made about this call.
     *
     * Also what {@see \SugarCraft\Crush\Chat::answerPermission()} writes when
     * the TUI's own permission prompt is answered `n`.
     */
    case Refused = 'Permission denied:';

    /**
     * An ASK that reached the point of execution with nobody able to answer
     * it. Nobody refused this call; there was nobody to ask.
     *
     * Two producers, and they are the same event on two paths: the engine's
     * fail-closed arm for a run with no approver attached, and
     * {@see \SugarCraft\Crush\Chat::forkToolCalls()}'s invariant check for a
     * batch released while an ASK was still outstanding.
     */
    case Unanswered = 'Permission required:';

    /**
     * A hook actively objecting — a {@see \SugarCraft\Crush\Hooks\HookResult::deny()}
     * from the PreToolUse chain. The one of the three where the remedy is to
     * change a hook rather than to answer differently.
     */
    case Hook = 'Hook denied:';

    /**
     * Every prefix, in roster order.
     *
     * Ordered `Refused`, `Unanswered`, `Hook` because that is the order
     * `Chat::DENIED_ERROR_PREFIXES` has always been declared in and at least
     * one consumer iterates it. No two prefixes are a prefix of one another —
     * `Permission denied:` and `Permission required:` diverge at byte 11 — so
     * order cannot change which case {@see classify()} answers; it is kept
     * only so a reader diffing the old constant against this list sees no
     * movement.
     *
     * @return list<string>
     */
    public static function prefixes(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * The kind $error announces itself as, or null when it announces none.
     *
     * `str_starts_with` and not a substring test, deliberately: the prefix is
     * a claim about how the reason OPENS. A tool that ran and failed with
     * output quoting the words "permission denied" somewhere in the middle is
     * an ordinary error, and calling it a refusal would strike it through in
     * the TUI and tell a JSON consumer the call never ran.
     */
    public static function classify(string $error): ?self
    {
        foreach (self::cases() as $kind) {
            if (str_starts_with($error, $kind->value)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * This kind's name as a stable lowercase token, for a consumer outside
     * PHP.
     *
     * DERIVED FROM THE CASE NAME, NEVER A SECOND LIST. A `match` here would be
     * a fourth place a denial kind is written down, which is the whole defect
     * this enum exists to close; `strtolower()` over `$this->name` cannot
     * drift from the roster because there is nothing to keep in sync.
     *
     * NOT THE BACKING VALUE, AND NOT `->name` RAW. The backing value is the
     * PREFIX — `Permission denied:`, punctuation and all — which is the text a
     * human reads and a poor key for a machine to switch on. `->name` is a PHP
     * identifier, so emitting it verbatim would make renaming a case a
     * breaking change to a JSON document. This is the one rendering meant to
     * be matched by a consumer that cannot import the enum:
     * {@see \SugarCraft\Crush\Cli\NonInteractive} puts it in the `kind`
     * field of every `refusals` entry.
     */
    public function token(): string
    {
        return strtolower($this->name);
    }

    /**
     * This kind's prefix followed by $detail — the whole finished reason, as
     * every consumer sees it.
     *
     * ONE SPACE, AND IT IS LOAD-BEARING. Every roster entry ends in `:` with
     * no trailing space, and every producer in this tree wrote
     * `"<prefix> {$detail}"`. Building the string here rather than at each
     * producer is the point of the enum: a producer names a kind, and the one
     * place that knows how a denial reads is this method.
     */
    public function reason(string $detail): string
    {
        return $this->value . ' ' . $detail;
    }
}
