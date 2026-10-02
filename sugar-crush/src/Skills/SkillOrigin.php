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
