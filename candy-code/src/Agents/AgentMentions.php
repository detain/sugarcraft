<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

/**
 * The typed-prompt door of `@researcher fix the flaky parser` — an address,
 * not a new channel (WAVE-R RS-AGENT-C2).
 *
 * The delegation machinery already shipped twice over: {@see \SugarCraft\Code\Attachments\ContextMentions}
 * resolves the reserved `@agent:<name>` keyword mention into a framed directive
 * telling the model to launch that exact preset through the Task tool, and the
 * headless host resolves the same spelling against the launch roster
 * ({@see \SugarCraft\Code\Cli\NonInteractive}, C-AGT-4.d). What no path held was
 * a way to SAY it without typing the reserved word `agent:` first. This gate is
 * that way: a line whose FIRST token is `@` and a bare name the roster holds
 * gets the token rewritten into the `@agent:<name>` spelling the existing leg
 * already resolves, and nothing else changes. The rewrite rides the ordinary
 * prose path — spend caps, compaction tiers, permission floors and the mention
 * resolver all apply to the rewritten line exactly as they would have applied
 * to the typed one.
 *
 * WHY A REWRITE AND NOT AN ATTACHMENT OR A DIRECT LAUNCH: one site is enough
 * because one funnel exists ({@see \SugarCraft\Code\Skills\SkillSlashInvocation},
 * VIS-5/C-WEB-2.b — the same lesson for `$skill` addresses). A direct launch
 * would mint a second routing topology around the fleet machinery (forked
 * child, run card, activity relays, spend shares) that Task/AgentManager
 * already owns; the rewrite keeps the directive's own escape law — "If the run
 * refuses, report that — do not substitute another agent" — and keeps every
 * gate that guards a delegation guarding this one too.
 *
 * WHY ANCHORED, BARE-NAME-ONLY POLARITY: only a line-initial `@<name>` token
 * addressed by name is routed. A colon in the token (`@agent:x`, `@diff:y`) is
 * already the reserved keyword vocabulary and is never rewritten; a dot
 * (`@example.com`) is prose; `@` mid-line is prose; a file path the file-mention
 * leg is about to attach is a path and stays one. The consequence, honestly
 * disclosed: `@known-name ...` at line start used to be text for the model and
 * is now an address — that FLIP is the feature. Lines this gate declines fall
 * through byte-identical, and the two forms an address CANNOT be told apart
 * from at the door — an unknown bare name (a refusal, and the one
 * behavior-change cost: a prose line that OPENS with a lone word prefixed `@`,
 * addressed to nobody in this run, is now refused with the roster named; put
 * any character in front of it and it is prose again, exactly as it was) and
 * the reserved head `diff`, which keeps its keyword meaning and stays prose
 * for the existing legs.
 *
 * Lookups are BYTE-EXACT — the roster's own door ({@see AgentManager::get()}).
 * This differs deliberately from the skill door's case-fold: `agent:` name
 * matching is byte-exact everywhere else (ContextMentions' roster leg and the
 * Task tool's `agent` argument both take the exact name), so the address must
 * carry the name's bytes unchanged or the delegation it triggers would refuse
 * the spelling the user never typed.
 *
 * The gate is pure static so a second door can enforce the same spelling
 * without re-deriving it — the dual-door law the skill gate learned
 * (C-WEB-2.b: {@see \SugarCraft\Code\Host\SessionHost::admit()} calls
 * SkillSlashInvocation's gate itself so a host-driven submit behaves like the
 * TUI one). Wiring SessionHost's own submit is OUT OF THIS FILE'S SHAPE and
 * recorded as a seam, not silently duplicated.
 */
final class AgentMentions
{
    /**
     * A line-initial address token: `@` then a bare name, then nothing else in
     * the token. `[\w-]` admits the hyphen/underscore preset names (the
     * `.claude/agents` family) while excluding the colon (reserved keyword
     * vocabulary), the dot (domains, paths), and any second `@` (an
     * `a@b.com`-shaped token is an email, and prose starting at column zero of
     * a line that merely contains one is not addressed to anybody).
     */
    private const ADDRESS = '#^@([A-Za-z][\w-]*)#';

    /** Where {@see ADDRESS} matches, the token ends: whitespace or end of string. */
    private const TOKEN_END = '/[\s]/u';

    /** The reserved keyword heads of the mention channel. */
    private const RESERVED_HEADS = ['agent', 'diff', 'mcp', 'pr', 'session'];

    /**
     * Head(s) that stay valid keyword mentions with NO colon, so a bare
     * line-initial address of that spelling is the keyword's, not an address.
     */
    private const COLONLESS_HEADS = ['diff'];

    /**
     * Not instantiable — a door, not a thing.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Rewrite a line-initial `@<name>` address into the `@agent:<name>`
     * spelling the existing mention leg resolves, or hand back null to say
     * "this line is not an address" — and null means the line travels byte-
     * identical from here.
     *
     * `$roster` is the run's yes-or-no for a preset name, the same closure
     * shape the mention resolver and the headless host hold
     * (`fn (string $name): bool`); a roster of null means the delegation
     * channel is not open in this run, and a bare name stays the prose it
     * was. A roster closure that THROWS answers as "no" — fail closed, one
     * bad closure must not take the submit down, exactly the law the
     * keyword leg learned ({@see \SugarCraft\Code\Attachments\ContextMentions}::agent()).
     *
     * @param ?\Closure(string): bool $roster
     */
    public static function draft(string $text, ?\Closure $roster): ?string
    {
        $name = self::addressedName($text);

        if ($name === null || $roster === null || !self::routes($name, $roster)) {
            return null;
        }

        // '@' + the name, then the rest of the line verbatim — the same
        // substr-after-the-token math the skill gate uses, minus its
        // canonical-casing walk: the name is copied byte-for-byte.
        return '@agent:'.$name.\substr($text, 1 + \strlen($name));
    }

    /**
     * Whether $name is an addressable agent: a roster is open, the spelling is
     * not a reserved keyword head, and the roster holds it. The refusal notice
     * reads the SAME truth value from the SAME two predicates, so the door
     * that rewrites and the door that refuses cannot drift.
     *
     * @param \Closure(string): bool $roster
     */
    public static function routes(string $name, \Closure $roster): bool
    {
        if (self::isReservedHead($name)) {
            return false;
        }

        try {
            return $roster($name) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The bare name ONLY a line-initial address token carries; null whenever
     * the first token of the line is not `@`-plus-a-name — mid-line mentions,
     * reserved keyword colons, domains and emails, a bare `@`, and a token
     * that opens the line but continues past the name all answer null here,
     * and their lines keep every byte they arrived with.
     */
    public static function addressedName(string $text): ?string
    {
        if (!\preg_match(self::ADDRESS, $text, $match)) {
            return null;
        }

        // The name is the WHOLE first token: end of the line (the bare
        // address — '@coder' alone, which rewrites exactly as the skill gate
        // rewrites a bare '/shipit': the addressee was chosen, the empty ask
        // is the user's own line) or whitespace. '@coder:' is the reserved-
        // word vocabulary's shape (a colon, whatever follows it, is the
        // keyword leg's business); '@coder.md' is a path; '@a@b.com' is
        // nobody's name; none of them end at whitespace right after the name.
        $rest = \substr($text, 1 + \strlen($match[1]));

        return $rest === '' || \preg_match(self::TOKEN_END, $rest) === 1 ? $match[1] : null;
    }

    /**
     * Whether the bare name collides with the mention channel's reserved
     * heads, and so is never an address whatever the roster holds.
     */
    public static function isReservedHead(string $name): bool
    {
        return \in_array(\strtolower($name), self::RESERVED_HEADS, true);
    }
}
