<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * Session-lifetime tracker that turns a skill's `paths:` frontmatter into a
 * live auto-scoping signal (crush_feat.md section 7 E4).
 *
 * Before this, `paths:` was static metadata: {@see SkillRegistry::getForPaths()}
 * was correct and unit-tested but had no production caller, so a skill scoped
 * to `**\/*.php` never announced itself when the agent actually opened a PHP
 * file. Read/Edit/Glob now hand every path they resolve to this tracker, and it
 * emits a system-reminder-style nudge on the tool result — mirroring Claude
 * Code's "skills load on first read/edit inside that subdirectory".
 *
 * Deliberately kept OFF the hot path: matching is pure in-memory `fnmatch()`
 * against frontmatter already loaded at bootstrap — no filesystem scan of the
 * skills tree and no per-skill syscall on a tool call — and once every
 * path-scoped skill has been announced the tracker short-circuits to null
 * before matching at all, so a long session pays nothing per tool call.
 */
final class SkillPathNudge
{
    /**
     * Names already surfaced this session. The nudge fires on the FIRST touch
     * of a matching path only; repeating it on every subsequent Read would
     * burn context re-telling the model something it was already told.
     *
     * @var array<string, true>
     */
    private array $announced = [];

    public function __construct(private readonly SkillRegistry $registry) {}

    /**
     * Build a tracker over the session's one populated {@see SkillRegistry}.
     */
    public static function new(SkillRegistry $registry): self
    {
        return new self($registry);
    }

    /**
     * Nudge text for a single touched file, or null when nothing new matches.
     */
    public function forPath(string $path): ?string
    {
        return $this->forPaths([$path]);
    }

    /**
     * Nudge text for a batch of touched files (Glob resolves many at once), or
     * null when no path-scoped skill newly matches.
     *
     * @param list<string> $paths
     */
    public function forPaths(array $paths): ?string
    {
        if ($paths === [] || !$this->hasPending()) {
            return null;
        }

        $lines = [];
        foreach ($this->registry->getForPaths($paths) as $skill) {
            if (isset($this->announced[$skill->name])) {
                continue;
            }

            // A disable-model-invocation skill is user-invocable only; nudging
            // the model about a skill it is forbidden to call would just invite
            // a failed Skill tool call.
            if (!$this->registry->isAutoInvocable($skill->name)) {
                continue;
            }

            $this->announced[$skill->name] = true;
            $lines[] = "- {$skill->name}: {$skill->description}";
        }

        if ($lines === []) {
            return null;
        }

        return "<system-reminder>\n"
            . "These skills are scoped to paths you just touched. Invoke one with the Skill tool if it applies:\n"
            . implode("\n", $lines)
            . "\n</system-reminder>";
    }

    /**
     * Skill names already nudged this session, in announcement order.
     *
     * `strval` over the raw keys because PHP coerces a decimal-integer string
     * array key to `int` on insertion: a skill named `123` was stored as
     * `"123"` and would come back out of `array_keys()` as `int(123)`. See
     * {@see markAnnounced()} for what that costs on the far side.
     *
     * @return list<string>
     */
    public function announced(): array
    {
        return array_map(strval(...), array_keys($this->announced));
    }

    /**
     * Union $names into the announced set, so the "announce once" rule
     * survives a fork.
     *
     * A tool run inside one of {@see \SugarCraft\Crush\Runtime}'s forked tool
     * children announces into the CHILD's copy of this tracker; without this
     * the mark dies with the child and the same skill is re-announced on the
     * next call. A union, never a replacement: concurrent children report
     * overlapping sets in no defined order, each starting from a copy of this
     * tracker's own state. See {@see \SugarCraft\Crush\Tools\CarriesSessionState}.
     *
     * CAST, not a type filter: {@see announced()} is `array_keys()` over a
     * string-keyed array, and PHP coerces a decimal-integer string key to
     * `int` on the way IN — so a skill literally named `123` comes back as
     * `int(123)`, which an `is_string()` filter here would silently drop, and
     * that skill would then re-announce on every forked Read/Glob for the rest
     * of the session. Casting round-trips it to the same key it went in as.
     *
     * @param list<string|int> $names
     */
    public function markAnnounced(array $names): void
    {
        foreach ($names as $name) {
            if (is_string($name) || is_int($name)) {
                $name = (string) $name;
                if ($name !== '') {
                    $this->announced[$name] = true;
                }
            }
        }
    }

    /**
     * True while at least one enabled path-scoped skill has yet to be
     * announced. Guards the match loop so the steady state of a long session
     * (everything already announced) costs one array walk, not a glob match
     * per skill per tool call.
     */
    private function hasPending(): bool
    {
        foreach ($this->registry->all() as $skill) {
            if ($skill->paths !== [] && !isset($this->announced[$skill->name])) {
                return true;
            }
        }

        return false;
    }
}
