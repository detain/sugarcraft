<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use InvalidArgumentException;

/**
 * The typed reading of one SKILL.md frontmatter block — the single boundary
 * both {@see Skill::parse()} (eager, foreign trees) and
 * {@see SkillLoader::loadSkillManifest()} (Stage-1, native trees) go through.
 *
 * WHY IT EXISTS (audit 15d-01). Both readers used to hand raw YAML values
 * straight to {@see Skill}'s typed constructor. YAML types what the author
 * wrote, not what the field means: `description: 2024-01-01` is an int
 * timestamp, `paths: src/**` is a string, `context: [fork]` is a list. The
 * eager path happened to sit inside a `catch (\Throwable)`, so its skill was
 * silently dropped; the manifest path reached
 * {@see SkillRegistry::registerFromManifest()} outside any catch, and one
 * repository-shipped SKILL.md — project skills have no trust gate — killed
 * `bin/sugarcrush` at launch with an uncaught TypeError. Validating HERE turns
 * every such value into an {@see InvalidArgumentException} that names the
 * field, which both callers' existing skip recorders turn into a launch notice.
 *
 * WHAT IS COERCED AND WHAT IS REFUSED. Only the shorthands whose meaning is not
 * in doubt are coerced: a scalar `paths: src/**` is a one-element list (the
 * spelling {@see \SugarCraft\Crush\Context\Rule} already accepts for its own
 * list keys), a list of tool names under `allowed-tools`/`disallowed-tools` is
 * the comma-separated string those fields hold, and the YAML 1.1 boolean words
 * (`yes`/`no`/`on`/`off`) — which Symfony's YAML 1.2 parser returns as STRINGS —
 * mean what their author meant instead of `(bool) "no" === true`. A number or a
 * date where text belongs is refused rather than stringified: `42` or
 * `1704067200` is never the description anyone wrote, and the error tells them
 * to quote it.
 *
 * REQUIREMENTS GATE THE SKILL HERE TOO (roadmap 5.14k). A skill may declare
 * what the host needs for it to work: `requires: {bins, anyBins, env}` and
 * `os`. The same block is also read from `metadata.openclaw` /
 * `metadata.nanobot`, the vendor bags those agents' SKILL.md files carry, when
 * the top-level keys are absent. A skill whose requirements this host does
 * not meet is refused with a reason naming every missing piece
 * ({@see SkillRegistry::unmetRequirements()}). Both readers already turn a
 * refusal into a recorded skip, so an unavailable skill never reaches the
 * prompt listing, and the reason is on `SkillManager::skipped()` instead of
 * the model finding out halfway through a task that `gh` is not installed. The
 * check runs once per launch per skill, and only for a skill that declares
 * requirements: the rest pay nothing.
 *
 * TYPED IS NOT HONOURED. `allowed-tools`, `disallowed-tools`, `model`,
 * `effort` and `context: fork` are typed here so a wrong shape is caught, and
 * nothing on a live path acts on them. That list is
 * {@see \SugarCraft\Crush\Support\FrontmatterKeyAudit::INERT}, not this
 * comment: the launch names every skill that sets one (or a key this class
 * does not read at all), and the step that wires a field deletes its entry
 * there.
 */
final readonly class SkillFrontmatter
{
    /** YAML 1.1 boolean words, lower-cased. Symfony parses YAML 1.2, which keeps only `true`/`false`. */
    private const BOOLEAN_WORDS = [
        'true' => true, 'yes' => true, 'on' => true,
        'false' => false, 'no' => false, 'off' => false,
    ];

    /** Keys a top-level `requires:` block may hold, mapped to the normalized key. */
    private const REQUIRES_KEYS = [
        'bins' => 'bins', 'anyBins' => 'anyBins', 'any-bins' => 'anyBins', 'env' => 'env',
    ];

    /** The vendor bags under `metadata:` whose `requires`/`os` are honoured, in precedence order. */
    private const METADATA_VENDORS = ['openclaw', 'nanobot'];

    /**
     * Platform names `os:` accepts, mapped to {@see SkillRegistry::currentPlatform()}'s
     * spelling (Node's `process.platform`, which both upstream formats use).
     */
    public const OS_NAMES = [
        'linux' => 'linux', 'darwin' => 'darwin', 'macos' => 'darwin', 'win32' => 'win32',
        'windows' => 'win32', 'freebsd' => 'freebsd', 'openbsd' => 'openbsd',
        'netbsd' => 'netbsd', 'sunos' => 'sunos',
    ];

    /**
     * @param list<string> $paths
     * @param array{bins?:list<string>,anyBins?:list<string>,env?:list<string>,os?:list<string>} $requires
     *        Empty when the skill declares no requirement.
     */
    private function __construct(
        public string $description,
        public bool $userInvocable,
        public bool $disableModelInvocation,
        public ?string $allowedTools,
        public ?string $disallowedTools,
        public ?string $model,
        public string $effort,
        public string $context,
        public array $paths,
        public array $requires,
    ) {}

    /**
     * Read a {@see \SugarCraft\Crush\Support\Frontmatter::parse()} result.
     *
     * That parser returns whatever YAML gives back — null for an empty block, a
     * scalar for a bare value, a list for a sequence — so the shape is checked
     * before any key is read: null is "no fields", anything other than a
     * mapping is refused.
     *
     * @param string $name The skill's name, for the `description` default.
     *
     * @throws InvalidArgumentException naming the offending field.
     * @throws \RuntimeException when the skill declares requirements this host
     *         does not meet, naming each one.
     */
    public static function fromParsed(mixed $parsed, string $name): self
    {
        if ($parsed === null) {
            $parsed = [];
        }
        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw new InvalidArgumentException(sprintf(
                'SKILL.md frontmatter must be a YAML mapping of fields, %s given.',
                is_array($parsed) ? 'list' : get_debug_type($parsed),
            ));
        }

        $meta = new self(
            description: self::stringField($parsed, 'description') ?? "Skill: $name",
            userInvocable: self::boolField($parsed, 'user-invocable') ?? true,
            disableModelInvocation: self::boolField($parsed, 'disable-model-invocation') ?? false,
            allowedTools: self::toolListField($parsed, 'allowed-tools'),
            disallowedTools: self::toolListField($parsed, 'disallowed-tools'),
            model: self::stringField($parsed, 'model'),
            effort: self::stringField($parsed, 'effort') ?? 'medium',
            context: self::stringField($parsed, 'context') ?? 'thread',
            paths: self::pathsField($parsed),
            requires: self::requiresField($parsed),
        );

        // After every field is typed, so a mistyped skill reports the typo
        // (fixable) rather than a missing binary.
        $unmet = SkillRegistry::unmetRequirements($meta->requires);
        if ($unmet !== []) {
            throw new \RuntimeException(SkillRegistry::unavailableReason($name, $unmet));
        }

        return $meta;
    }

    /**
     * The skill's `requires`/`os` declaration, normalized: only the non-empty
     * lists appear, so a skill with no requirement reads as `[]`.
     *
     * Top-level keys win. Only when neither `requires` nor `os` is written at
     * the top level is the first `metadata.<vendor>` bag that has either read
     * instead, and there unknown `requires` keys (OpenClaw's `config`, which
     * names that agent's own config paths) are ignored rather than refused:
     * the bag is another tool's format, and its extras are not typos here.
     *
     * @param array<mixed> $meta
     *
     * @return array{bins?:list<string>,anyBins?:list<string>,env?:list<string>,os?:list<string>}
     */
    private static function requiresField(array $meta): array
    {
        $prefix = '';
        $strict = true;
        if (!array_key_exists('requires', $meta) && !array_key_exists('os', $meta)) {
            $bag = self::vendorMetadata($meta);
            if ($bag === null) {
                return [];
            }
            [$prefix, $meta] = $bag;
            $strict = false;
        }

        $out = [];
        $requires = $meta['requires'] ?? null;
        if ($requires !== null) {
            if (!is_array($requires) || ($requires !== [] && array_is_list($requires))) {
                throw self::refuse($prefix . 'requires', 'a mapping of bins / anyBins / env', $requires);
            }
            foreach ($requires as $key => $value) {
                $normalized = self::REQUIRES_KEYS[$key] ?? null;
                if ($normalized === null) {
                    if ($strict) {
                        throw new InvalidArgumentException(sprintf(
                            'SKILL.md frontmatter "requires" has an unknown key "%s"; expected bins, anyBins or env.',
                            $key,
                        ));
                    }
                    continue;
                }
                $list = self::stringListField($value, "{$prefix}requires.{$key}");
                if ($list !== []) {
                    $out[$normalized] = array_values(array_unique([...($out[$normalized] ?? []), ...$list]));
                }
            }
        }

        $os = self::stringListField($meta['os'] ?? null, $prefix . 'os');
        if ($os !== []) {
            $platforms = [];
            foreach ($os as $i => $platform) {
                $known = self::OS_NAMES[strtolower(trim($platform))] ?? null;
                if ($known === null) {
                    throw new InvalidArgumentException(sprintf(
                        'SKILL.md frontmatter "%sos[%d]" names an unknown platform "%s"; expected one of %s.',
                        $prefix,
                        $i,
                        $platform,
                        implode(', ', array_keys(self::OS_NAMES)),
                    ));
                }
                $platforms[] = $known;
            }
            $out['os'] = array_values(array_unique($platforms));
        }

        return $out;
    }

    /**
     * The first `metadata.<vendor>` bag declaring `requires` or `os`, with the
     * key prefix its errors are reported under.
     *
     * @param array<mixed> $meta
     *
     * @return array{0: string, 1: array<mixed>}|null
     */
    private static function vendorMetadata(array $meta): ?array
    {
        $metadata = $meta['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }
        foreach (self::METADATA_VENDORS as $vendor) {
            $bag = $metadata[$vendor] ?? null;
            if (is_array($bag) && (array_key_exists('requires', $bag) || array_key_exists('os', $bag))) {
                return ["metadata.{$vendor}.", $bag];
            }
        }

        return null;
    }

    /**
     * A string (a one-element list) or a list of non-empty strings.
     *
     * @return list<string>
     */
    private static function stringListField(mixed $value, string $key): array
    {
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw self::refuse($key, 'a string or a list of strings', $value);
        }
        foreach ($value as $i => $item) {
            if (!is_string($item) || trim($item) === '') {
                throw self::refuse("{$key}[{$i}]", 'a non-empty string', $item);
            }
        }

        return array_map('trim', $value);
    }

    /**
     * @param array<mixed> $meta
     */
    private static function stringField(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw self::refuse($key, 'a string', $value);
    }

    /**
     * @param array<mixed> $meta
     */
    private static function boolField(array $meta, string $key): ?bool
    {
        $value = $meta[$key] ?? null;
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $word = strtolower(trim($value));
            if (array_key_exists($word, self::BOOLEAN_WORDS)) {
                return self::BOOLEAN_WORDS[$word];
            }
        }

        throw self::refuse($key, 'a boolean (true/false, yes/no, on/off)', $value);
    }

    /**
     * `allowed-tools: Read, Grep` and `allowed-tools: [Read, Grep]` are both
     * spellings in the wild; the field holds the comma-separated form.
     *
     * @param array<mixed> $meta
     */
    private static function toolListField(array $meta, string $key): ?string
    {
        $value = $meta[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $i => $item) {
                if (!is_string($item)) {
                    throw self::refuse("{$key}[{$i}]", 'a string', $item);
                }
            }

            return implode(', ', $value);
        }

        throw self::refuse($key, 'a string or a list of strings', $value);
    }

    /**
     * Every entry must be a string because {@see SkillRegistry::pathMatches()}
     * takes one: a non-string entry used to register fine and then throw on
     * the first Edit/Write the nudge ran for — AFTER the file was written.
     *
     * @param array<mixed> $meta
     *
     * @return list<string>
     */
    private static function pathsField(array $meta): array
    {
        $value = $meta['paths'] ?? null;
        if ($value === null) {
            return [];
        }
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw self::refuse('paths', 'a glob string or a list of glob strings', $value);
        }
        foreach ($value as $i => $item) {
            if (!is_string($item)) {
                throw self::refuse("paths[{$i}]", 'a string', $item);
            }
        }

        return $value;
    }

    private static function refuse(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'SKILL.md frontmatter "%s" must be %s, %s given%s.',
            $key,
            $expected,
            is_array($value) ? (array_is_list($value) ? 'list' : 'map') : get_debug_type($value),
            // An unquoted date is the one case whose given type misleads: YAML
            // turned `2024-01-01` into an int, and the author never wrote one.
            is_int($value) || is_float($value) ? ' (quote the value if it is meant as text)' : '',
        ));
    }
}
