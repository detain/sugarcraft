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
        return array_values(array_filter(
            $this->all(),
            fn($skill) => $skill->userInvocable
        ));
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
