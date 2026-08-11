<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

final class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    /** @var array<string, true> disabled skills */
    private array $disabledSkills = [];

    /**
     * Register skills from an array.
     *
     * @param array<string, Skill> $skills
     */
    public function register(array $skills): void
    {
        foreach ($skills as $name => $skill) {
            $this->skills[$name] = $skill;
        }
    }

    /**
     * Get a skill by name.
     */
    public function get(string $name): ?Skill
    {
        if ($this->isDisabled($name)) {
            return null;
        }

        return $this->skills[$name] ?? null;
    }

    /**
     * Get all enabled skills.
     *
     * @return array<string, Skill>
     */
    public function all(): array
    {
        return array_filter(
            $this->skills,
            fn($name) => !$this->isDisabled($name),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Find skills matching a prompt.
     *
     * @return array<Skill>
     */
    public function findForPrompt(string $prompt): array
    {
        $matches = [];

        foreach ($this->all() as $skill) {
            // A skill with disable-model-invocation: true is only reachable via
            // explicit user invocation (getUserInvocable()) — it must never be
            // surfaced for auto-triggering off a free-text prompt, even if its
            // description keywords match. Route through isAutoInvocable() itself
            // (rather than re-inlining the disableModelInvocation check here) so
            // this stays in lockstep with any future change to what "auto-invocable"
            // means.
            if (!$this->isAutoInvocable($skill->name)) {
                continue;
            }

            if ($skill->matchesPrompt($prompt)) {
                $matches[] = $skill;
            }
        }

        // Sort by relevance (exact matches first)
        usort($matches, function (Skill $a, Skill $b) use ($prompt) {
            $aMatches = substr_count(strtolower($a->description), strtolower($prompt));
            $bMatches = substr_count(strtolower($b->description), strtolower($prompt));
            return $bMatches <=> $aMatches;
        });

        return $matches;
    }

    /**
     * Get user-invokable skills.
     *
     * @return array<Skill>
     */
    public function getUserInvocable(): array
    {
        // Route through isUserInvocable() (rather than re-inlining the
        // $skill->userInvocable check here) so this stays in lockstep with
        // any future change to what "user-invocable" means — mirrors the
        // same rationale findForPrompt() follows via isAutoInvocable().
        return array_values(array_filter(
            $this->all(),
            fn($skill) => $this->isUserInvocable($skill->name)
        ));
    }

    /**
     * Check if a skill is auto-invocable (not disabled for model invocation).
     */
    public function isAutoInvocable(string $skillName): bool
    {
        $skill = $this->skills[$skillName] ?? null;

        if ($skill === null) {
            return false;
        }

        return !$skill->disableModelInvocation;
    }

    /**
     * Check if a skill is user-invokable.
     */
    public function isUserInvocable(string $skillName): bool
    {
        $skill = $this->skills[$skillName] ?? null;

        if ($skill === null) {
            return false;
        }

        return $skill->userInvocable;
    }

    /**
     * Check if a skill runs in fork context (spawned sub-agent).
     */
    public function isContextFork(string $skillName): bool
    {
        $skill = $this->skills[$skillName] ?? null;

        if ($skill === null) {
            return false;
        }

        return $skill->context === 'fork';
    }

    /**
     * Register a skill from a manifest array (e.g., from SkillLoader::loadSkillManifest).
     *
     * paths must come through from the manifest -- it drives path-based
     * auto-scoping (getForPaths()) and, unlike content, is cheap frontmatter
     * data the Stage-1 manifest already carries; hardcoding it to [] here
     * would silently break every path-scoped skill loaded via the lazy
     * manifest path (crush_feat.md section 7 E3/E4).
     *
     * @param array{name:string,description:string,disableModelInvocation:bool,userInvocable:bool,context:string,paths:array<string>,sourcePath:string} $manifest
     */
    public function registerFromManifest(array $manifest): void
    {
        $skill = new Skill(
            name: $manifest['name'],
            description: $manifest['description'],
            userInvocable: $manifest['userInvocable'],
            disableModelInvocation: $manifest['disableModelInvocation'],
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: $manifest['context'],
            paths: $manifest['paths'],
            content: '',
            sourcePath: $manifest['sourcePath'],
        );

        $this->skills[$manifest['name']] = $skill;
    }

    /**
     * Get skills that match file paths.
     *
     * @param array<string> $paths
     * @return array<Skill>
     */
    public function getForPaths(array $paths): array
    {
        $matches = [];

        foreach ($this->all() as $skill) {
            foreach ($skill->paths as $pattern) {
                // Try direct match first
                $patternMatched = false;
                foreach ($paths as $path) {
                    if (fnmatch($pattern, $path)) {
                        $patternMatched = true;
                        break;
                    }
                }

                // If direct match failed, try converting glob ** to fnmatch patterns
                if (!$patternMatched && str_contains($pattern, '**')) {
                    // Convert /**/ to /*/ (matches one directory level)
                    $pattern1 = str_replace('/**/', '/*/', $pattern);
                    // Convert /** at end to /* (matches one directory or zero)
                    $pattern2 = str_replace('/**', '/*', $pattern);
                    // Also try without the ** entirely (matches zero directories)
                    $pattern3 = str_replace('/**', '', $pattern);

                    foreach ($paths as $path) {
                        if (fnmatch($pattern1, $path) || fnmatch($pattern2, $path) || fnmatch($pattern3, $path)) {
                            $patternMatched = true;
                            break;
                        }
                    }
                }

                if ($patternMatched) {
                    $matches[] = $skill;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Disable a skill.
     */
    public function disable(string $name): void
    {
        $this->disabledSkills[$name] = true;
    }

    /**
     * Enable a disabled skill.
     */
    public function enable(string $name): void
    {
        unset($this->disabledSkills[$name]);
    }

    /**
     * Check if a skill is disabled.
     */
    public function isDisabled(string $name): bool
    {
        return isset($this->disabledSkills[$name]);
    }

    /**
     * Disable multiple skills.
     *
     * @param array<string> $names
     */
    public function disableMultiple(array $names): void
    {
        foreach ($names as $name) {
            $this->disable($name);
        }
    }

    /**
     * Get skill names.
     *
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->skills);
    }
}
