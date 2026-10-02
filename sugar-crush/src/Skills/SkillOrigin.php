<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * WHO put a skill's SKILL.md where sugar-crush found it — the provenance tier,
 * as opposed to {@see SkillSource}, which is the file's FORMAT (native, Claude
 * Code, opencode).
 *
 * The two are independent axes: `~/.claude/skills/x` is a user-tier skill in a
 * foreign format, `<repo>/.sugar-crush/skills/x` a project-tier skill in the
 * native one. The tier is what matters for trust — a project skill arrived
 * with whatever repository was cloned, with no trust gate — so it is the half
 * the system-prompt listing badges (audit 15d-02, fix step 3): every listing
 * line opens with a tag such as `[project]` or `[user, foreign: claude]`, so a
 * one-line repository description cannot pass for something the operator or
 * the harness shipped.
 *
 * Set at LOAD time by the walker that knows which directory it is reading
 * ({@see SkillLoader::loadAllManifests()}, {@see SkillLoader::loadAll()},
 * {@see ForeignSkillDiscovery}), never re-derived from `sourcePath` by prefix
 * matching: the walkers already hold the tier as a fact, while a path prefix
 * would have to agree with them about symlinks, `realpath()` and `$HOME`.
 *
 * This is a SugarCraft provenance type, not a port — charmbracelet/crush has
 * no skill tiers to mirror.
 */
enum SkillOrigin: string
{
    /** Shipped inside sugar-crush itself (`src/Skills/BuiltIn`). */
    case BuiltIn = 'built-in';

    /** The operator's own home tree (`~/.sugar-crush/skills`, `~/.claude/skills`, …). */
    case User = 'user';

    /**
     * Inside the checkout (`.sugar-crush/skills`, `.claude/skills`,
     * `.opencode/skills`) — and the DEFAULT for a skill nobody tiered, such as
     * one an embedder registers directly. Unknown provenance is labelled with
     * the least-trusted tier on purpose: the badge exists to stop a line from
     * being over-trusted, and an unlabelled origin claiming `user` or
     * `built-in` would be the one wrong answer that does harm.
     */
    case Project = 'project';

    /**
     * Every tier, LOWEST precedence first — the one statement of which tier
     * wins a skill-name collision, read by every merge that can shadow a skill
     * ({@see SkillLoader::loadAll()}, {@see SkillLoader::manifestTiers()},
     * {@see ForeignSkillDiscovery}, {@see SkillManager::loadAll()}). The merges
     * are last-write-wins, so the last tier listed is the one that keeps a name.
     *
     * built-in < project < user: THE USER BEATS THE PROJECT (audit 15d-03(b)).
     * This used to be built-in < user < project, so a cloned repository's
     * `.sugar-crush/skills/deploy` silently replaced the `deploy` in the
     * user's own `~/.sugar-crush/skills` — the opposite of the rule the
     * foreign trees already followed, and of `LayeredSettings`' "the user's
     * files outrank the project's". A project skill arrives with whatever was
     * cloned; it may ADD a name, and it may still replace a built-in (that is
     * the tier below it, and the replacement is reported), but it may not
     * re-point a skill the operator wrote. The tier is the first key of the
     * order; the file's format ({@see SkillSource}) only breaks a tie inside
     * one tier.
     *
     * @return list<self>
     */
    public static function precedence(): array
    {
        return [self::BuiltIn, self::Project, self::User];
    }

    /**
     * The text inside a listing line's brackets: the tier, plus the foreign
     * format when the file is another tool's (`project, foreign: claude`).
     * A native skill names its tier only — the format adds nothing there.
     */
    public function badge(SkillSource $format): string
    {
        return $format === SkillSource::Native
            ? $this->value
            : $this->value . ', foreign: ' . $format->value;
    }
}
