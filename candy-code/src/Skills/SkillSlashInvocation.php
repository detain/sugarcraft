<?php

declare(strict_types=1);

namespace SugarCraft\Code\Skills;

use SugarCraft\Code\Commands\Specs\BuiltInCommands;

/**
 * The VIS-5 rewrite, as one pure function both front doors call (C-WEB-2.b).
 *
 * A typed `/name args` draft whose name the session's registry answers is the
 * SAME request as the `$name args` mention spelling, and {@see draft()} is the
 * one gate that decides it. The body was lifted verbatim out of
 * `Chat::skillInvocationDraft()` — which stays as a one-line delegate — so the
 * terminal and a served `session.send` cannot drift on what rewrites and what
 * stays prose:
 *
 * - `Chat::submit()` calls it after `dispatchCommand()` and the file-command
 *   branch passed on the name (the TUI's precedence: file commands, then
 *   built-ins, then skills);
 * - `Host\SessionHost::admit()` calls it at the same point of its own funnel,
 *   before the prompt is admitted.
 *
 * WHY THE PRIORITY IS THE POSITION, PLUS ONE GUARD: the caller reached this
 * gate only after the file-command and built-in branches declined the draft,
 * and the guard here re-checks the built-in spellings so a claimed name whose
 * `accepts()` gate declined the argument (`/exit now`) stays prose even if a
 * skill were ever named `exit`. The registry, not the disk manifest, gates the
 * dispatch — exactly the gate `$name` passes through one layer down inside
 * {@see SkillMentions}, which is what keeps a disabled or non-user-invocable
 * skill from dispatching by the slash door while it cannot by the mention
 * door. A null registry means no channel, so nothing rewrites.
 */
final class SkillSlashInvocation
{
    /**
     * The draft rewritten into the `$name args` mention spelling, or null when
     * $text is not that request.
     */
    public static function draft(string $text, ?SkillRegistry $registry): ?string
    {
        $token = \explode("\n", \explode(' ', $text, 2)[0])[0];
        if (\preg_match('#^/([A-Za-z][\w:-]*)$#', $token, $shape) !== 1) {
            return null;
        }

        $name = \explode(':', $shape[1], 2)[0];
        if (BuiltInCommands::forSpelling($name) !== null
            || BuiltInCommands::forSpelling(strtolower($name)) !== null
        ) {
            return null;
        }

        if ($registry === null) {
            return null;
        }

        // A colon-namespaced skill (`ns:sub`) spells its own name with a
        // colon, and the head-truncated $name above can never find it —
        // while the mention channel resolves that spelling freely (the
        // SkillMentions pattern admits colons). So the colon form is walked
        // LONGEST NAME FIRST: `/ns:sub extra` reaches ns:sub with `extra`,
        // `/ns:sub:quickly` still reaches ns:sub with the second colon read
        // as the separator the way `/shipit:review` always was, and a miss
        // all the way down ends at the head, where the old lookup lived —
        // the final candidate is exactly the former $name, so no existing
        // spelling changes meaning (w3c review m-2).
        $spelling = $name;
        $skill = null;
        $segments = \explode(':', $shape[1]);
        for ($take = \count($segments); $skill === null && $take >= 1; $take--) {
            $candidate = \implode(':', \array_slice($segments, 0, $take));
            $skill = $registry->userInvocable($candidate) ?? $registry->userInvocable(strtolower($candidate));
            if ($skill !== null) {
                $spelling = $candidate;
            }
        }
        if ($skill === null) {
            return null;
        }

        // The name ends where the matched spelling ends, so everything typed
        // after it — space form, colon form, or newline — becomes the
        // mention's argument text.
        $rest = \substr($text, 1 + \strlen($spelling));
        if (\str_starts_with($rest, ':')) {
            $rest = \ltrim(\substr($rest, 1));
            $rest = $rest === '' ? '' : ' ' . $rest;
        }

        return '$' . $skill->name . $rest;
    }
}
