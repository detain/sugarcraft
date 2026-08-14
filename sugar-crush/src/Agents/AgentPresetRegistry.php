<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use Symfony\Component\Yaml\Yaml;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * Registry for loading and resolving agent presets from the filesystem.
 * Presets are Markdown files with YAML frontmatter stored under search paths.
 *
 * Mirrors charmbracelet/crush preset discovery and description-matching logic.
 */
final class AgentPresetRegistry
{
    public function __construct(private readonly array $searchPaths) {}

    /**
     * Load a preset by name from the search paths.
     * Searches for <name>.md in each search path in order.
     */
    public function load(string $name): AgentPreset
    {
        foreach ($this->searchPaths as $path) {
            $filePath = $path . '/' . $name . '.md';
            $realPath = realpath($filePath);
            $realSearchPath = realpath($path);
            if ($realPath === false || $realSearchPath === false) {
                continue;
            }
            // Normalize with a trailing separator so a sibling directory whose
            // name merely starts with the same string (e.g. "agents-secrets"
            // vs "agents") cannot pass a raw string-prefix check.
            $normalizedSearchPath = rtrim($realSearchPath, '/') . '/';
            if (!str_starts_with($realPath, $normalizedSearchPath)) {
                continue;
            }
            if (file_exists($filePath)) {
                return $this->parsePresetFile($filePath);
            }
        }

        throw new \RuntimeException("Preset '{$name}' not found in search paths.");
    }

    /**
     * List all available presets from all search paths.
     *
     * Applies the same containment check {@see load()} does, and for the same
     * reason: a `.md` entry that resolves outside its search path — a symlink
     * such as `agents/link.md -> /elsewhere/stolen.md` — is not a preset this
     * directory declares. The two methods share one trust boundary, and this
     * is the one {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresets()}
     * actually calls, so leaving it open made load()'s check decorative.
     *
     * @return array<string, AgentPreset> Map of preset name => AgentPreset
     */
    public function list(): array
    {
        $presets = [];

        foreach ($this->searchPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $realSearchPath = realpath($path);
            if ($realSearchPath === false) {
                continue;
            }
            // Trailing separator for the same reason as load(): a sibling
            // directory whose name merely starts with the same string
            // ("agents-secrets" vs "agents") must not pass a raw prefix check.
            $normalizedSearchPath = rtrim($realSearchPath, '/') . '/';

            $files = glob($path . '/*.md');
            foreach ($files as $file) {
                $realFile = realpath($file);
                if ($realFile === false || !str_starts_with($realFile, $normalizedSearchPath)) {
                    continue;
                }

                $name = basename($file, '.md');
                // First search path takes precedence on name conflicts
                if (!isset($presets[$name])) {
                    $presets[$name] = $this->parsePresetFile($file);
                }
            }
        }

        return $presets;
    }

    /**
     * Resolve a preset by matching a task description against preset descriptions.
     * Uses keyword overlap scoring — returns the preset whose description shares
     * the most keywords with the task description, enabling auto-delegation.
     */
    public function resolve(string $taskDescription): ?AgentPreset
    {
        $taskWords = $this->extractKeywords($taskDescription);
        if ($taskWords === []) {
            return null;
        }

        $bestPreset = null;
        $bestScore = 0;

        foreach ($this->list() as $preset) {
            $descWords = $this->extractKeywords($preset->description);
            $overlap = array_intersect($taskWords, $descWords);
            $score = count($overlap);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPreset = $preset;
            }
        }

        // Require at least 2 overlapping words to avoid spurious matches
        return $bestScore >= 2 ? $bestPreset : null;
    }

    /**
     * Extract lowercase keywords from a string (filters short/common words).
     *
     * @return array<string> List of meaningful word tokens
     */
    private function extractKeywords(string $text): array
    {
        $stopWords = [
            'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to',
            'for', 'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are',
            'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
            'will', 'would', 'could', 'should', 'may', 'might', 'can',
            'this', 'that', 'these', 'those', 'it', 'its',
        ];

        $words = preg_split('/\s+/', strtolower($text));
        $keywords = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopWords, true));

        return array_values($keywords);
    }

    /**
     * Parse a preset Markdown file with YAML frontmatter into an AgentPreset.
     */
    private function parsePresetFile(string $filePath): AgentPreset
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Could not read preset file: {$filePath}");
        }

        // Extract YAML frontmatter block
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            throw new \RuntimeException("No YAML frontmatter found in: {$filePath}");
        }

        $data = Yaml::parse($matches[1]);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid YAML frontmatter in: {$filePath}");
        }

        // Everything after the closing `---` is the preset's prompt. $matches[0]
        // is the whole delimited block, so slicing by its length is what leaves
        // the body and nothing else.
        return $this->arrayToPreset($data, $filePath, trim(substr($content, strlen($matches[0]))));
    }

    /**
     * Build an AgentPreset from a parsed YAML array.
     *
     * @param string $filePath Path of the preset file the data was parsed from,
     *                         used as the name fallback when no `name:` key is set.
     * @param string $body     The markdown after the frontmatter, used as the
     *                         preset's prompt when no `initialPrompt:` key is
     *                         set. An explicit `initialPrompt:` WINS: it is the
     *                         more specific statement of intent, and a file
     *                         carrying both is asking for the declared one.
     */
    private function arrayToPreset(array $data, string $filePath, string $body = ''): AgentPreset
    {
        return new AgentPreset(
            name: $data['name'] ?? basename($filePath, '.md'),
            description: $data['description'] ?? '',
            tools: $data['tools'] ?? [],
            disallowedTools: $data['disallowedTools'] ?? [],
            model: $data['model'] ?? 'inherit',
            permissionMode: $this->parsePermissionMode($data['permissionMode'] ?? 'default'),
            maxTurns: isset($data['maxTurns']) ? (int) $data['maxTurns'] : null,
            skills: $data['skills'] ?? [],
            mcpServers: $data['mcpServers'] ?? [],
            memory: $this->parseMemoryScope($data['memory'] ?? 'user'),
            background: (bool) ($data['background'] ?? false),
            effort: $this->parseEffort($data['effort'] ?? 'medium'),
            isolation: $this->parseIsolation($data['isolation'] ?? null),
            color: $data['color'] ?? null,
            initialPrompt: self::resolveInitialPrompt($data['initialPrompt'] ?? null, $body),
        );
    }

    /**
     * The preset's prompt: a declared `initialPrompt:` if there is one, else
     * the markdown body.
     *
     * The body is where Claude Code and opencode both put a subagent's prompt,
     * so a `reviewer.md` written to either convention used to register with an
     * empty prompt here — the agent arrived carrying nothing but its
     * environment block. Null (rather than '') when neither is present, so
     * AgentPreset's own "no prompt" value is preserved.
     */
    private static function resolveInitialPrompt(mixed $declared, string $body): ?string
    {
        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return $body === '' ? null : $body;
    }

    private function parsePermissionMode(string $value): PermissionMode
    {
        return match (strtolower($value)) {
            'accept-edits' => PermissionMode::AcceptEdits,
            'plan' => PermissionMode::Plan,
            'auto' => PermissionMode::Auto,
            'dont-ask' => PermissionMode::DontAsk,
            'bypass-permissions' => PermissionMode::BypassPermissions,
            default => PermissionMode::Default,
        };
    }

    private function parseMemoryScope(string $value): MemoryScope
    {
        return match (strtolower($value)) {
            'project' => MemoryScope::Project,
            'local' => MemoryScope::Local,
            default => MemoryScope::User,
        };
    }

    private function parseEffort(string $value): Effort
    {
        return match (strtolower($value)) {
            'low' => Effort::Low,
            'high' => Effort::High,
            'xhigh' => Effort::XHigh,
            'max' => Effort::Max,
            default => Effort::Medium,
        };
    }

    private function parseIsolation(?string $value): ?Isolation
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'worktree' => Isolation::Worktree,
            'none' => Isolation::None,
            default => null,
        };
    }
}
