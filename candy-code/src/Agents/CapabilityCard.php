<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use InvalidArgumentException;
use RuntimeException;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\SkillSource;

use function array_is_list;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function strlen;
use function substr;

/**
 * A published capability manifest for one agent preset — the "who can do X"
 * half of a2a-style discovery (RS-AGENT-C4, wave-1106).
 *
 * The card is a PURE derivation from an {@see AgentPreset} or {@see Agent}:
 * it names what the agent can be pointed at (model, tool allow/deny lists,
 * MCP servers, skills, background/isolation support, permission floor) and
 * nothing else. Four preset fields are deliberately EXCLUDED, and the
 * consumer-census + shape tests pin that exclusion:
 *  - prompt/initialPrompt — task content, not capability, and often private;
 *  - sourcePath — a filesystem location, never a capability;
 *  - color — decoration;
 *  - mode — selection-only vocabulary that does not travel to the Agent.
 *
 * Serialization is byte-deterministic for identical inputs (fixed key
 * order, stable flags): two cards derived from the same preset at the same
 * derivedAt are the same bytes, which is what makes the published store
 * diffable and auditable, mirroring the prompt-cache byte-stability law.
 *
 * READING IS NEVER TRUSTING. {@see fromArray()} fails closed on any shape
 * deviation, and every consumer treats card content as DISPLAY-only: a
 * foreign manifest can name tools and privileges, but nothing in candy-code
 * widens a grant, launches an agent, or routes mail from card bytes. The
 * capability card is data, not permission — in particular the permission
 * mode it reports for a foreign-sourced preset mirrors Agent::fromPreset()'s
 * gate (raised modes never travel), so the card cannot overstate either.
 *
 * V1 SCOPE BOUNDARY (loud): discovery here is LOCAL and CROSS-SESSION only —
 * the shared ~/.candy-code home store read at query time. There is NO
 * network a2a transport, no RPC, and no protocol surface in this slice;
 * machine-to-machine discovery would need the frozen Protocol layer and is
 * reported as a seam, not built.
 */
final class CapabilityCard
{
    /** Manifest format version — a mismatched reader refuses, never adapts. */
    public const SCHEMA_VERSION = 1;

    /**
     * Same vocabulary the run-card identity law uses: a card name must be a
     * safe single path segment. Deliberately carries no glob characters.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    /** Description byte ceiling — longer text is truncated by derivation. */
    public const MAX_DESCRIPTION_BYTES = 4096;

    /** Entries per capability list — beyond this a manifest is junk. */
    public const MAX_LIST_ENTRIES = 64;

    /** Byte ceiling per list entry. */
    public const MAX_ENTRY_BYTES = 128;

    /**
     * @param list<string> $tools
     * @param list<string> $disallowedTools
     * @param list<string> $mcpServers
     * @param list<string> $skills
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $model,
        public readonly bool $inheritsModel,
        public readonly array $tools,
        public readonly array $disallowedTools,
        public readonly array $mcpServers,
        public readonly array $skills,
        public readonly bool $background,
        public readonly ?string $isolation,
        public readonly string $permissionMode,
        public readonly ?string $memory,
        public readonly ?string $effort,
        public readonly ?int $maxTurns,
        public readonly string $source,
        public readonly int $derivedAt,
    ) {
        // Fail-fast choke: a card that cannot be a safe file name does not
        // exist. Derivation and the fail-closed reader are the only paths
        // that get here, so this is prophylaxis against future callers.
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Capability card name ' . $name . ' is not a safe manifest segment.');
        }
    }

    /**
     * Derive from a preset, stamping the caller's clock into derivedAt (the
     * card itself never reads a clock — determinism law).
     */
    public static function fromPreset(AgentPreset $preset, int $derivedAt): self
    {
        // Mirror Agent::fromPreset(): a raised permission mode only travels
        // for natively authored presets; foreign-sourced agents are pinned
        // to the default floor, and the card must not overstate them.
        $permissionMode = $preset->source === SkillSource::Native
            ? $preset->permissionMode
            : PermissionMode::Default;
        $inheritsModel = $preset->model === '' || $preset->model === 'inherit';

        return new self(
            name: $preset->name,
            description: self::clip($preset->description),
            model: self::entry($preset->model),
            inheritsModel: $inheritsModel,
            tools: array_map([self::class, 'entry'], array_slice($preset->tools, 0, self::MAX_LIST_ENTRIES)),
            disallowedTools: array_map([self::class, 'entry'], array_slice($preset->disallowedTools, 0, self::MAX_LIST_ENTRIES)),
            mcpServers: array_map([self::class, 'entry'], array_slice($preset->mcpServers, 0, self::MAX_LIST_ENTRIES)),
            skills: array_map([self::class, 'entry'], array_slice($preset->skills, 0, self::MAX_LIST_ENTRIES)),
            background: $preset->background,
            isolation: $preset->isolation?->value,
            permissionMode: $permissionMode->value,
            memory: $preset->memory?->value,
            effort: $preset->effort?->value,
            maxTurns: $preset->maxTurns,
            source: $preset->source->value,
            derivedAt: $derivedAt,
        );
    }

    /**
     * Derive from a live Agent (what AgentManager enumerates). The Agent has
     * already passed fromPreset()'s gate, so its permissionMode is truthful.
     */
    public static function fromAgent(Agent $agent, int $derivedAt): self
    {
        return new self(
            name: $agent->name,
            description: self::clip($agent->description),
            model: self::entry($agent->model),
            inheritsModel: $agent->inheritsModel,
            tools: array_map([self::class, 'entry'], array_slice($agent->tools, 0, self::MAX_LIST_ENTRIES)),
            disallowedTools: array_map([self::class, 'entry'], array_slice($agent->disallowedTools, 0, self::MAX_LIST_ENTRIES)),
            mcpServers: array_map([self::class, 'entry'], array_slice($agent->mcpServers, 0, self::MAX_LIST_ENTRIES)),
            skills: array_map([self::class, 'entry'], array_slice($agent->skillNames, 0, self::MAX_LIST_ENTRIES)),
            background: $agent->background,
            isolation: $agent->isolation?->value,
            permissionMode: $agent->permissionMode->value,
            memory: $agent->memory?->value,
            effort: $agent->effort?->value,
            maxTurns: $agent->maxTurns,
            source: $agent->source->value,
            derivedAt: $derivedAt,
        );
    }

    /**
     * Fixed key order, stable flags — byte-deterministic for equal inputs.
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'name' => $this->name,
            'description' => $this->description,
            'model' => $this->model,
            'inheritsModel' => $this->inheritsModel,
            'tools' => $this->tools,
            'disallowedTools' => $this->disallowedTools,
            'mcpServers' => $this->mcpServers,
            'skills' => $this->skills,
            'background' => $this->background,
            'isolation' => $this->isolation,
            'permissionMode' => $this->permissionMode,
            'memory' => $this->memory,
            'effort' => $this->effort,
            'maxTurns' => $this->maxTurns,
            'source' => $this->source,
            'derivedAt' => $this->derivedAt,
        ];
    }

    public function serialize(): string
    {
        // Deterministic: no JSON_THROW_ON_ERROR here — the flags below are
        // total, and the false-check keeps the failure message ours.
        $json = json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($json === false) {
            throw new RuntimeException('Capability card ' . $this->name . ' could not be encoded.');
        }

        return $json;
    }

    /**
     * Parse foreign bytes fail-closed: any shape deviation yields null and
     * the caller skips the manifest. Nothing here coerces or repairs.
     */
    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::fromArray($decoded) : null;
    }

    public static function fromArray(array $data): ?self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA_VERSION
            || !isset($data['name']) || !is_string($data['name'])
            || preg_match(self::NAME_PATTERN, $data['name']) !== 1
            || !isset($data['description']) || !is_string($data['description'])
            || strlen($data['description']) > self::MAX_DESCRIPTION_BYTES
            || !isset($data['model']) || !is_string($data['model'])
            || strlen($data['model']) > self::MAX_ENTRY_BYTES
            || !isset($data['inheritsModel']) || !is_bool($data['inheritsModel'])
            || !self::isNameList($data['tools'] ?? null)
            || !self::isNameList($data['disallowedTools'] ?? null)
            || !self::isNameList($data['mcpServers'] ?? null)
            || !self::isNameList($data['skills'] ?? null)
            || !isset($data['background']) || !is_bool($data['background'])
            || !self::isValidEnum($data['permissionMode'] ?? null, PermissionMode::class)
            || !self::isValidEnum($data['source'] ?? null, SkillSource::class)
            || !self::isValidOptionalEnum($data['isolation'] ?? null, Isolation::class)
            || !self::isValidOptionalEnum($data['memory'] ?? null, MemoryScope::class)
            || !self::isValidOptionalEnum($data['effort'] ?? null, Effort::class)
            || !self::isOptionalNonNegativeInt($data['maxTurns'] ?? null)
            || !isset($data['derivedAt']) || !is_int($data['derivedAt'])
        ) {
            return null;
        }

        return new self(
            name: $data['name'],
            description: $data['description'],
            model: $data['model'],
            inheritsModel: $data['inheritsModel'],
            tools: $data['tools'],
            disallowedTools: $data['disallowedTools'],
            mcpServers: $data['mcpServers'],
            skills: $data['skills'],
            background: $data['background'],
            isolation: $data['isolation'],
            permissionMode: $data['permissionMode'],
            memory: $data['memory'],
            effort: $data['effort'],
            maxTurns: $data['maxTurns'],
            source: $data['source'],
            derivedAt: $data['derivedAt'],
        );
    }

    /**
     * One-line capability digest for roster rows — derived only from fields
     * this class already bounds, so it is display-safe length.
     */
    public function summary(): string
    {
        $parts = [$this->inheritsModel ? 'model:inherit' : 'model:' . $this->model];
        $parts[] = count($this->tools) > 0 ? count($this->tools) . ' tools' : 'all tools';
        if ($this->disallowedTools !== []) {
            $parts[] = count($this->disallowedTools) . ' denied';
        }
        if ($this->mcpServers !== []) {
            $parts[] = count($this->mcpServers) . ' mcp';
        }
        if ($this->skills !== []) {
            $parts[] = count($this->skills) . ' skills';
        }
        if ($this->background) {
            $parts[] = 'background';
        }
        if ($this->isolation !== null && $this->isolation !== Isolation::None->value) {
            $parts[] = 'isolation:' . $this->isolation;
        }
        $parts[] = 'mode:' . $this->permissionMode;
        if ($this->effort !== null) {
            $parts[] = 'effort:' . $this->effort;
        }
        if ($this->maxTurns !== null) {
            $parts[] = 'cap:' . $this->maxTurns;
        }

        return implode(' ', $parts);
    }

    private static function clip(string $description): string
    {
        if (strlen($description) <= self::MAX_DESCRIPTION_BYTES) {
            return $description;
        }

        // Byte clip at a safe boundary: the card is UTF-8 prose and the
        // display path sanitizes anyway; a truncated tail beats a reject.
        return substr($description, 0, self::MAX_DESCRIPTION_BYTES);
    }

    private static function entry(string $value): string
    {
        return strlen($value) > self::MAX_ENTRY_BYTES ? substr($value, 0, self::MAX_ENTRY_BYTES) : $value;
    }

    private static function isNameList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_LIST_ENTRIES) {
            return false;
        }
        foreach ($value as $entry) {
            if (!is_string($entry) || strlen($entry) > self::MAX_ENTRY_BYTES || $entry === '') {
                return false;
            }
        }

        return true;
    }

    private static function isValidEnum(mixed $value, string $enumClass): bool
    {
        return is_string($value) && $enumClass::tryFrom($value) !== null;
    }

    private static function isValidOptionalEnum(mixed $value, string $enumClass): bool
    {
        return $value === null || self::isValidEnum($value, $enumClass);
    }

    private static function isOptionalNonNegativeInt(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 0);
    }
}
