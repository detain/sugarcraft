<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Context\Utf8Scrub;
use SugarCraft\Crush\Support\Frontmatter;

/**
 * A skill loaded from a SKILL.md file.
 */
final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $userInvocable,
        public bool $disableModelInvocation,
        public ?string $allowedTools,
        public ?string $disallowedTools,
        public ?string $model,
        public string $effort,
        public string $context,
        public array $paths,
        public string $content,
        public string $sourcePath,
        public SkillSource $source = SkillSource::Native,
        // Who put the file there, as opposed to its format ($source). Defaults
        // to the least-trusted tier — see SkillOrigin::Project for why an
        // untiered skill must not be labelled anything friendlier.
        public SkillOrigin $origin = SkillOrigin::Project,
    ) {}

    /**
     * Parse a SKILL.md file and return a Skill.
     */
    public static function fromFile(string $path): self
    {
        // Check existence first so a missing path throws cleanly instead of
        // emitting a PHP warning from file_get_contents before we throw.
        if (!is_file($path)) {
            throw new \RuntimeException("Failed to read skill file: $path");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read skill file: $path");
        }

        // Require frontmatter - SKILL.md files must have valid frontmatter
        if (!preg_match('/^---\s*\n.*?\n---\s*\n/s', $content)) {
            throw new \InvalidArgumentException("Skill file must have frontmatter: $path");
        }

        // Use parent directory name as skill name (SKILL.md is always inside a skill directory)
        $skillName = basename(dirname($path));

        return self::parse($content, $skillName, $path);
    }

    /**
     * Parse SKILL.md content.
     *
     * @throws \InvalidArgumentException when a frontmatter field has the wrong
     *         type ({@see SkillFrontmatter::fromParsed()}).
     */
    public static function parse(string $content, string $name, string $sourcePath = ''): self
    {
        // Valid UTF-8 BEFORE anything reads it (audit 15d-08): the YAML parse
        // refuses invalid bytes, which skipped the whole skill, and a body that
        // got past it went into the system prompt raw and failed the JSON
        // encode of every request. The note is appended at the end of the file,
        // so it lands in the body — the part the model reads as the skill.
        $content = Utf8Scrub::announced($content, "the SKILL.md of skill \"{$name}\"");

        // Split frontmatter from content
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            $body = substr($content, strlen($matches[0]));
            $parsed = Frontmatter::parse($matches[1]);
        } else {
            $parsed = null;
            $body = $content;
        }

        // Typed at the boundary, by the same reader the Stage-1 manifest uses,
        // so a mistyped field fails here with its name rather than as a
        // TypeError out of the constructor below — see SkillFrontmatter.
        $meta = SkillFrontmatter::fromParsed($parsed, $name);

        return new self(
            name: $name,
            description: $meta->description,
            userInvocable: $meta->userInvocable,
            disableModelInvocation: $meta->disableModelInvocation,
            allowedTools: $meta->allowedTools,
            disallowedTools: $meta->disallowedTools,
            model: $meta->model,
            effort: $meta->effort,
            context: $meta->context,
            paths: $meta->paths,
            content: trim($body),
            sourcePath: $sourcePath,
        );
    }

    /**
     * Unanchored substring probe over the description: any token longer than
     * three bytes that appears anywhere in the prompt is a match.
     *
     * WHY THIS IS DELIBERATELY DORMANT, WITH THE NUMBER. Measured at b289eaf44
     * over the 12 shipped skills (239 description tokens, 52-prompt battery):
     * precision 0.162, and 24 of 25 boundary prompts false-fire (96%) — a bare
     * `port` in a description matches "airport". Whole-word anchoring only lifts
     * that to 0.214: the dominant failure is common English words living in
     * descriptions (a single `when` hijacks 9 skills), not substring bleed. So a
     * production-viable matcher needs curated frontmatter keywords fed to the
     * `KeywordTrigger` primitive — a design step, not this method. Phase 7
     * therefore closes it deliberately unwired. See the premise and its
     * artifacts under `prompt_kit/findings/P7.S4/`.
     */
    public function matchesPrompt(string $prompt): bool
    {
        // Simple keyword matching on description
        $keywords = array_filter(explode(' ', strtolower($this->description)));

        foreach ($keywords as $keyword) {
            if (strlen($keyword) > 3 && stripos($prompt, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the system prompt contribution from this skill.
     *
     * Name and body pass through {@see PromptFence::escape()} for the reason
     * {@see SkillPromptLine} exists: both are text from whoever shipped the
     * skill, and a body that could close a fence or open a `<system-reminder>`
     * would speak in the harness's voice. Enabling a skill is the user's choice,
     * which makes this the lower-risk channel of audit 15d-02, not a trusted one.
     * The name is also collapsed to one line, because it heads a markdown
     * section; the body keeps its newlines — it IS the skill's instructions.
     */
    public function systemPromptContribution(): string
    {
        return "\n\n## Skill: " . SkillPromptLine::field($this->name)
            . "\n\n" . PromptFence::escape($this->content);
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'user_invokable' => $this->userInvocable,
            'disable_model_invocation' => $this->disableModelInvocation,
            'allowed_tools' => $this->allowedTools,
            'disallowed_tools' => $this->disallowedTools,
            'model' => $this->model,
            'effort' => $this->effort,
            'context' => $this->context,
            'paths' => $this->paths,
            'source_path' => $this->sourcePath,
        ];
    }

    public function withName(string $name): self
    {
        return new self(
            name: $name,
            description: $this->description,
            userInvocable: $this->userInvocable,
            disableModelInvocation: $this->disableModelInvocation,
            allowedTools: $this->allowedTools,
            disallowedTools: $this->disallowedTools,
            model: $this->model,
            effort: $this->effort,
            context: $this->context,
            paths: $this->paths,
            content: $this->content,
            sourcePath: $this->sourcePath,
            source: $this->source,
            origin: $this->origin,
        );
    }

    /**
     * The same skill tagged with the tier its walker found it in — see
     * {@see SkillOrigin} for why the walker, and not a path prefix, decides.
     */
    public function withOrigin(SkillOrigin $origin): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            userInvocable: $this->userInvocable,
            disableModelInvocation: $this->disableModelInvocation,
            allowedTools: $this->allowedTools,
            disallowedTools: $this->disallowedTools,
            model: $this->model,
            effort: $this->effort,
            context: $this->context,
            paths: $this->paths,
            content: $this->content,
            sourcePath: $this->sourcePath,
            source: $this->source,
            origin: $origin,
        );
    }
}