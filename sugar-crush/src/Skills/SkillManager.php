<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\App\App;

/**
 * Manages skill loading, selection, and application.
 */
final class SkillManager
{
    private ForeignSkillDiscovery $foreign;

    public function __construct(
        private SkillLoader $loader,
        private SkillRegistry $registry,
        ?ForeignSkillDiscovery $foreign = null,
    ) {
        // Defaults to one sharing THIS loader rather than building its own, so
        // a caller that handed in a configured loader gets it used for both
        // the native and the foreign walk.
        $this->foreign = $foreign ?? new ForeignSkillDiscovery($loader);
    }

    /**
     * Load all skills from standard locations: the foreign imports other
     * coding CLIs leave under `.claude/skills` / `.opencode/skills`, then the
     * native built-in/user/project trees.
     *
     * Registers Stage-1 manifests only (name/description/flags) via
     * SkillLoader::loadAllManifests() -- not the previous
     * SkillLoader::loadAll(), which eagerly read every skill's full
     * SKILL.md body off disk regardless of whether it was ever used that
     * session. Fixes crush_feat.md section 7.E3.
     *
     * The body backfills lazily, on-demand, via
     * Tools\BuiltIn\SkillTool::execute(), which calls loadSkillBody() -- and
     * that tool IS registered now, in Bootstrap::tools(), on the same
     * SkillRegistry instance this manager fills. The sentence here used to end
     * "not yet registered into Bootstrap::tools()/EngineBackend, so that half of
     * the backfill path is implemented and tested, not yet production reachable
     * from bin/sugarcrush", and it outlived the wiring it described by several
     * rounds: crush_feat.md section 7 item 2 / W3.S8 landed, and
     * BinSugarcrushWiringTest::testBootstrapToolsIncludesTheModelFacingSkillTool
     * plus testSkillToolInvocationReturnsTheOnDiskSkillBody have been asserting
     * both halves since. A doc-block claiming a subsystem is unreachable while it
     * is reachable is the same defect as one claiming the reverse -- which is the
     * defect the paragraph below records.
     *
     * FOREIGN DISCOVERY IS CALLED HERE, and this is crush_code.md Phase 2
     * item 6: {@see ForeignSkillDiscovery}, its SkillSource tagging and its
     * tests all existed, and nothing ever called it — so a skill under
     * `~/.claude/skills` was invisible to a real run no matter what
     * `Bootstrap`'s doc-block claimed. HERE rather than in
     * {@see SkillLoader::loadAllManifests()} because the whole point of
     * ForeignSkillDiscovery is the {@see SkillSource} tag it stamps on each
     * result, and that tag rides on a {@see Skill} object: the loader's
     * manifest arrays have nowhere to carry it, so routing the foreign walk
     * through them would discover the skills and throw away the provenance
     * the palette badges them with. This class is also the first layer that
     * holds the registry, which is what the two-source merge below needs.
     *
     * NATIVE WINS A NAME COLLISION, which is why the foreign trees are
     * registered FIRST and the native manifests over the top of them (the
     * registry is last-write-wins, the same merge convention
     * {@see SkillLoader::loadAll()} documents). Wiring a new discovery source
     * in must not change what an existing name resolves to: with the reverse
     * order, installing another CLI — or cloning a repo that carries a
     * `.claude/skills` tree — would silently re-point a skill the user
     * already had. Additive is the only safe direction for this step. The same
     * argument decides the two directories INSIDE each foreign tree, where
     * {@see ForeignSkillDiscovery} gives the user's own copy precedence over a
     * cloned repository's.
     *
     * Between the two foreign trees the fixed call order below decides it
     * (opencode over Claude); that pair has no principled winner, so what
     * matters is that it is deterministic rather than dependent on scan order.
     * Either way the loser is not dropped silently: every replaced foreign
     * skill lands in {@see skipped()} naming the skill that took its name,
     * as the native tiers' own shadowings do (audit 15d-03).
     * That property is only true because {@see SkillLoader} follows symlinked
     * skill directories — while it did not, half of a tree could be invisible
     * and the winner of a cross-tree collision was decided by which layout the
     * machine happened to use.
     *
     * The cost, stated rather than hidden: the foreign walk reads each
     * imported SKILL.md's BODY (ForeignSkillDiscovery goes through
     * loadFromDirectory()), so those skills do not get the lazy Stage-1
     * treatment 7.E3 bought for the native ones. Giving them it needs
     * registerFromManifest() to carry a SkillSource, which is a change to the
     * registry's shape rather than to this wiring.
     */
    public function loadAll(string $projectRoot = '.'): void
    {
        // Every foreign skill registered so far, by name, so each later
        // registration that replaces one is RECORDED rather than silent (audit
        // 15d-03): the registry is last-write-wins and keeps no trace of what a
        // write displaced. Keyed exactly as the registry keys — by the skill's
        // own name, with PHP's integer-string coercion applying identically to
        // both arrays — so a hit here is a hit there.
        /** @var array<array-key, Skill> $foreign */
        $foreign = [];
        foreach ([$this->foreign->discoverClaude($projectRoot), $this->foreign->discoverOpencode($projectRoot)] as $tree) {
            foreach ($tree as $skill) {
                if (isset($foreign[$skill->name])) {
                    $this->recordShadowing($skill->name, $foreign[$skill->name], $skill->sourcePath, self::tierOf($skill));
                }
                $foreign[$skill->name] = $skill;
            }
            $this->registry->register($tree);
        }

        foreach ($this->loader->loadAllManifests($projectRoot) as $manifest) {
            // The backstop for audit 15d-01: loadSkillManifest() now types every
            // field (SkillFrontmatter), so this should not throw — but this loop
            // runs at launch on files a cloned repository chose, and the one
            // thing it must never do is take the launch down. A throw here is
            // the same event as an unparseable SKILL.md and is recorded the same
            // way, so it reaches the launch notice through skipped().
            try {
                $this->registry->registerFromManifest($manifest);
            } catch (\Throwable $e) {
                $this->loader->recordSkip(
                    (string) ($manifest['sourcePath'] ?? $manifest['name'] ?? '?'),
                    $e->getMessage(),
                );
                // A manifest that failed to register displaced nothing, so it
                // has no shadowing to report.
                continue;
            }

            if (isset($foreign[$manifest['name']])) {
                $origin = $manifest['origin'] ?? SkillOrigin::Project;
                $this->recordShadowing(
                    (string) $manifest['name'],
                    $foreign[$manifest['name']],
                    (string) $manifest['sourcePath'],
                    $origin->badge(SkillSource::Native),
                );
            }
        }
    }

    /**
     * Report a foreign skill that a later registration replaced, through the
     * loader's one shadowing record ({@see SkillLoader::recordShadowing()}) so
     * it reaches {@see skipped()} alongside the native tiers' own.
     */
    private function recordShadowing(string $name, Skill $loser, string $winnerPath, string $winnerTier): void
    {
        $this->loader->recordShadowing($name, $loser->sourcePath, self::tierOf($loser), $winnerPath, $winnerTier);
    }

    private static function tierOf(Skill $skill): string
    {
        return $skill->origin->badge($skill->source);
    }

    /**
     * Every SKILL.md the load gave up on, keyed by path — the diagnostic
     * {@see SkillLoader::recordSkip()} keeps instead of writing to stderr, so
     * a caller with somewhere safe to show it (a doctor report, a debug pane)
     * can reach it without the TUI paying for it on every launch.
     *
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->loader->skipped();
    }

    /**
     * Every skills DIRECTORY the load refused to enter, keyed by path.
     *
     * A different event from {@see skipped()} and reported as one: that array
     * counts files this loader could not parse, and this one names a whole tree
     * a repository had moved out of the checkout
     * ({@see SkillLoader::refusedDirectories()}). Read at launch by
     * {@see \SugarCraft\Crush\Cli\Bootstrap::reportProjectTierRefusals()}.
     *
     * Reaches the foreign walks as well as the native ones for the reason the
     * constructor shares one loader by default; a caller that hands in a
     * {@see ForeignSkillDiscovery} built on a DIFFERENT loader gets only the
     * native half, exactly as it already does for {@see skipped()}.
     *
     * @return array<string, string>
     */
    public function refusedDirectories(): array
    {
        return $this->loader->refusedDirectories();
    }

    /**
     * Get skills for a specific task.
     *
     * @return array<Skill>
     */
    public function getSkillsForTask(string $task): array
    {
        return $this->registry->findForPrompt($task);
    }

    /**
     * Get skills matching file paths.
     *
     * @param array<string> $paths
     * @return array<Skill>
     */
    public function getSkillsForPaths(array $paths): array
    {
        return $this->registry->getForPaths($paths);
    }

    /**
     * Apply skills to an app.
     *
     * @param array<string> $skillNames
     */
    public function applyToApp(App $app, array $skillNames): App
    {
        $skills = [];

        foreach ($skillNames as $name) {
            $skill = $this->registry->get($name);
            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        return $app->withEnabledSkills($skills);
    }

    /**
     * Enable a skill by name.
     */
    public function enable(App $app, string $skillName): App
    {
        $current = $app->enabledSkills;
        $skill = $this->registry->get($skillName);

        if ($skill === null) {
            return $app;
        }

        foreach ($current as $s) {
            if ($s->name === $skillName) {
                return $app;  // Already enabled
            }
        }

        return $app->withEnabledSkills([...$current, $skill]);
    }

    /**
     * Disable a skill by name.
     */
    public function disable(App $app, string $skillName): App
    {
        $current = $app->enabledSkills;

        return $app->withEnabledSkills(
            array_filter($current, fn($s) => $s->name !== $skillName)
        );
    }

    /**
     * Get all user-invocable skills.
     *
     * @return array<Skill>
     */
    public function getUserInvocable(): array
    {
        return $this->registry->getUserInvocable();
    }

    /**
     * Disable skills listed in config.
     *
     * @param array<string> $disabled
     */
    public function disableFromConfig(array $disabled): void
    {
        $this->registry->disableMultiple($disabled);
    }
}
