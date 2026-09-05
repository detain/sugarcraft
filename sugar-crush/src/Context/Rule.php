<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use InvalidArgumentException;
use SugarCraft\Crush\Context\Triggers\IntentTrigger;
use SugarCraft\Crush\Context\Triggers\KeywordTrigger;
use SugarCraft\Crush\Context\Triggers\PathTrigger;
use SugarCraft\Crush\Context\Triggers\Trigger;
use SugarCraft\Crush\Support\Frontmatter;

/**
 * One loaded rules file, parsed into a name, a prompt body, and its triggers.
 *
 * DESIGN SOURCE: prompt_plan.md Phase 6 P6.S2 (plan lines 2084-2115) and
 * prompt_expand.md §9.13. This is a SugarCraft architecture type, not a port -
 * charmbracelet/crush has no `Rule` symbol to mirror, so the repo's
 * "Mirrors charmbracelet/..." convention does not apply here.
 *
 * FRONTMATTER KEYS this class reads, exactly the set the plan names for the
 * rules tier: `name`, `description`, `enabled`, `models`, plus the two P6.S1
 * trigger keys `paths:` and `keywords:`. The frontmatter grammar itself
 * (the `---` fence, scalar coercion, list-of-scalars) is {@see Frontmatter},
 * reached through the same single fenced-block read {@see \SugarCraft\Crush\Commands\CommandSpec::fromFile()}
 * uses, so a rules file and a command file cannot disagree about what a block
 * of YAML means.
 *
 * THE TRIGGERS ARE BUILT, NOT FIRED. P6.S1 shipped {@see KeywordTrigger},
 * {@see PathTrigger} and {@see IntentTrigger} unwired precisely so this class
 * is their first consumer, and this class's job is only to turn the parsed
 * frontmatter arrays into those value objects - `paths:` -> {@see PathTrigger},
 * `keywords:` -> {@see KeywordTrigger}, `description` -> {@see IntentTrigger}.
 * Deciding whether a rule FIRES for a given prompt, path, or turn is NOT here:
 * keyword/whole-word matching is P7.S4's wiring, and glob-scoped rules actually
 * reaching the assembled prompt is P6.S5. That split is why a rule with no
 * trigger keys still loads with an empty {@see $triggers} list: it is a
 * standing rule, always eligible, gated on nothing.
 *
 * GLOB DIALECT - a divergence recorded rather than silently resolved
 * (F-PATHDIALECT). {@see PathTrigger} compiles `*` as segment-scoped
 * ([^/] - it does not cross a `/`), documented at
 * {@see \SugarCraft\Crush\Context\Triggers\PathTrigger::pattern()}. The older
 * matcher in {@see \SugarCraft\Crush\Skills\SkillRegistry::compilePathPattern()}
 * maps the same `*` to `.*` (it does cross `/`) and its own docblock marks the
 * two UNVERIFIED against each other. This class does not re-implement matching
 * and does not pick a dialect of its own: it stores a {@see PathTrigger} built
 * from the raw glob list, so the segment-scoped dialect is what any downstream
 * consumer of a rule's `paths:` will see. The reconciliation of that split is
 * flagged for the orchestrator, not decided here.
 *
 * NAME VERSUS KEY - two identifiers, because they answer two different
 * questions and only one of them is stable. {@see $name} is what the file chose
 * to be called (its `name:` frontmatter, falling back to the derived stem);
 * {@see $key} is what the LOADER called it, always - the path relative to the
 * tier directory minus `.md`. They are the same string for a file that names
 * itself after nothing, and different the moment an operator writes
 * `name: Terse Replies` into `rulebooks/terse.md`. The distinction is load-bearing
 * from P6.S3 on: a pack is toggled by {@see $key} (`/rules terse`), because the
 * thing the user types at a command has to be derivable from the directory
 * listing alone, whereas {@see $name} is display text two files may legitimately
 * share - which is exactly why {@see \SugarCraft\Crush\Context\RuleLoader} sorts
 * on the key and not the name. Nothing here re-derives either: the loader
 * computes the key once through `ruleKeyFor()` and threads it in.
 *
 * IMMUTABILITY. The public shape is read-only properties (bare - there is no
 * `get*`); every `with*()` returns a NEW Rule through {@see mutate()}. Parsing
 * and validation happen ONCE, at {@see new()}, the boundary; {@see mutate()}
 * reconstructs from already-valid state (The 5 Laws: parse at the boundary,
 * trust internally), which is why changing e.g. {@see withEnabled()} cannot
 * resurrect a malformed rule.
 *
 * @phpstan-type TriggerList list<Trigger>
 */
final class Rule
{
    /**
     * Same single-fence read {@see \SugarCraft\Crush\Commands\CommandSpec::FRONTMATTER_PATTERN}
     * uses: a leading `---` line, a YAML block, a closing `---` line, and the
     * rest as body. Group 1 is handed to {@see Frontmatter::parse()}; the whole
     * match is trimmed off the content to leave the prompt body.
     */
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';

    /**
     * The three tiers, in load order (user -> project -> root). Kept as a
     * private const list so {@see new()} can parse-don't-validate the tier
     * operand at the boundary rather than trusting any string a caller spells.
     *
     * THREE, AND P6.S3 DID NOT ADD A FOURTH FOR RULEBOOKS. `~/.sugar-crush/rulebooks/`
     * is a fourth DIRECTORY, but a tier here records WHO WROTE the bytes, because
     * that is the only thing the prompt's framing can act on: the tier picks the
     * fence and the authority preamble, and nothing else. A rulebook is written
     * by the operator of this machine in their own home directory, which is
     * exactly what `user` already means, so a `rulebook` value would be a
     * directory name masquerading as an authority level - and it would render
     * into a fence whose preamble lied about it. `user` is therefore the correct
     * tier for a pack, not a shortcut: see the provenance paragraph on
     * {@see \SugarCraft\Crush\Context\RuleLoader::loadUserRulebooks()}.
     */
    private const TIERS = ['user', 'project', 'root'];

    /**
     * @param string           $name        Stable identifier: the frontmatter `name:` when present, else the loader's derived filename stem.
     * @param string           $body        The prompt text (everything after the closing `---`), un-trimmed.
     * @param string           $path        The resolved absolute path this rule was read from.
     * @param string           $tier        One of {@see TIERS}.
     * @param string|null      $description The one-line self-description, or null when the file carried none.
     * @param bool             $enabled     Frontmatter `enabled:`; a rule with the key absent is enabled.
     * @param list<string>     $models      Model identifiers from `models:` (empty when the key is absent).
     * @param list<Trigger>    $triggers    The trigger value objects built from this file's frontmatter, in canonical order.
     * @param string           $key         The loader's pack identity for this file - never derived from frontmatter, so a `name:` cannot move what `/rules` types.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $body,
        public readonly string $path,
        public readonly string $tier,
        public readonly ?string $description,
        public readonly bool $enabled,
        public readonly array $models,
        public readonly array $triggers,
        public readonly string $key,
    ) {
    }

    /**
     * Build one rule by parsing a rules document.
     *
     * `::new()` is this value object's root factory (the same shape the P6.S1
      * triggers use), and it doubles as the parse-and-validate boundary: the
      * operands are the resolved path, the tier, the raw file bytes, and the
      * fallback name. This is where every frontmatter operand is checked, so the
      * rest of the object can be trusted. `$fallbackName` is what a name-less
     * file falls back to (the loader derives it from the file's path relative
     * to its tier root, mirroring how {@see \SugarCraft\Crush\Commands\CommandLoader}
     * names a command from its path); it is required because a rule with
     * neither a `name:` nor any way to derive one cannot be de-duplicated or
     * reported on.
     *
     * `$key` is the same derived string, kept as a separate operand because the
     * name is allowed not to be it: `name:` is display text, the key is the
     * identity a toggle addresses, and collapsing them would let an operator's
     * `name: Terse Replies` silently move what `/rules terse` means. Null is a
     * caller with no tier walk to derive an identity from - an embedder, a test
     * - and gets the fallback, the one identifier this method already insists
     * every rule has.
     *
     * @throws InvalidArgumentException on a non-mapping frontmatter block, a
     *         non-string `name`/`description`/`models` entry, a non-boolean
     *         `enabled`, or an out-of-range `$tier` operand.
     */
    public static function new(string $path, string $tier, string $content, string $fallbackName, ?string $key = null): self
    {
        if (!in_array($tier, self::TIERS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Rule tier must be one of %s, "%s" given (%s).',
                implode(', ', self::TIERS),
                $tier,
                $path,
            ));
        }
        if (trim($fallbackName) === '') {
            throw new InvalidArgumentException(sprintf('Rule needs a fallback name to derive an identifier from (%s).', $path));
        }
        if ($key !== null && trim($key) === '') {
            throw new InvalidArgumentException(sprintf('Rule pack identity must not be blank when a caller supplies one (%s).', $path));
        }

        $meta = [];
        $body = $content;

        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches) === 1) {
            $parsed = Frontmatter::parse($matches[1]);
            if ($parsed !== null && !is_array($parsed)) {
                throw new InvalidArgumentException(sprintf('Rule frontmatter must be a mapping of keys, %s given (%s).', get_debug_type($parsed), $path));
            }
            $meta = is_array($parsed) ? $parsed : [];
            $body = substr($content, strlen($matches[0]));
        }

        $name = self::stringField($meta, 'name', $path) ?? $fallbackName;
        if (trim($name) === '') {
            throw new InvalidArgumentException(sprintf('Rule name resolved to blank (%s).', $path));
        }
        $description = self::stringField($meta, 'description', $path);
        $enabled = self::boolField($meta, 'enabled', $path) ?? true;
        $models = self::stringListField($meta, 'models', $path);

        return new self(
            name: $name,
            body: $body,
            path: $path,
            tier: $tier,
            description: $description,
            enabled: $enabled,
            models: $models,
            triggers: self::buildTriggers($meta, $description, $path),
            key: $key ?? $fallbackName,
        );
    }

    /**
     * Build the trigger list from parsed frontmatter, in canonical order.
     *
     * The order is fixed - keyword, then path, then intent - independent of the
     * order the keys appear in the file, so two files with the same triggers in
     * different written order produce an identical list and the golden bytes
     * downstream do not depend on key spelling order.
     *
     * @param array<string, mixed> $meta
     * @param string|null          $description Reused as the {@see IntentTrigger} text (the plan maps `description` -> IntentTrigger).
     *
     * @return list<Trigger>
     */
    private static function buildTriggers(array $meta, ?string $description, string $path): array
    {
        $triggers = [];

        $keywords = self::stringListField($meta, 'keywords', $path);
        if ($keywords !== []) {
            $triggers[] = KeywordTrigger::new($keywords);
        }

        $paths = self::stringListField($meta, 'paths', $path);
        if ($paths !== []) {
            $triggers[] = PathTrigger::new($paths);
        }

        // A blank description must not reach IntentTrigger, which throws on it;
        // an absent-or-blank description is simply no intent trigger, not an
        // error, because `description:` is optional frontmatter.
        if ($description !== null && trim($description) !== '') {
            $triggers[] = IntentTrigger::new($description);
        }

        return $triggers;
    }

    // -- Immutable with*() builders ------------------------------------------

    /**
     * The same rule toggled enabled/disabled.
     *
     * P6.S3 adopted this as the runtime rulebook toggle:
     * {@see \SugarCraft\Crush\Context\RulesState::effectiveRule()} is the one
     * production caller, and it is the only way session state reaches a rule's
     * eligibility. That matters for what the method may NOT be: a caller with a
     * set of turned-off pack names could equally filter its rule list and skip
     * this primitive, which would work for the splice and leave the `/rules`
     * listing without any effective state to print. Going through here means the
     * prompt and the listing read the same `enabled` bit, so the two cannot
     * disagree about what the model is about to receive.
     */
    public function withEnabled(bool $enabled): self
    {
        return $this->mutate(enabled: $enabled);
    }

    /**
     * The same rule with a different prompt body.
     */
    public function withBody(string $body): self
    {
        return $this->mutate(body: $body);
    }

    /**
     * The same rule with its trigger list replaced wholesale. Every element
     * must be a {@see Trigger}; anything else throws before the new instance
     * is built (fail fast - a partial trigger list would silently narrow what a
     * later matcher sees).
     *
     * @param list<Trigger> $triggers
     */
    public function withTriggers(array $triggers): self
    {
        foreach ($triggers as $trigger) {
            if (!$trigger instanceof Trigger) {
                throw new InvalidArgumentException(sprintf(
                    'Rule::withTriggers() expects a list of Trigger instances, %s given (%s).',
                    get_debug_type($trigger),
                    $this->path,
                ));
            }
        }

        return $this->mutate(triggers: $triggers);
    }

    /**
     * Reconstruct with selected fields replaced, trusting already-parsed state.
     * Only the fields that have a `with*()` builder are operands here; every
     * other field carries through unchanged, and the ones a builder cannot
     * touch (identity `name` and pack identity `key`, source `path`/`tier`, and the read-only
     * `description`/`models` parsed once from the file) have no parameter to
     * get out of sync.
     */
    private function mutate(
        ?string $body = null,
        ?bool $enabled = null,
        ?array $triggers = null,
    ): self {
        return new self(
            name: $this->name,
            body: $body ?? $this->body,
            path: $this->path,
            tier: $this->tier,
            description: $this->description,
            enabled: $enabled ?? $this->enabled,
            models: $this->models,
            triggers: $triggers ?? $this->triggers,
            key: $this->key,
        );
    }

    // -- Frontmatter field readers (parse-don't-validate at the boundary) ----

    /**
     * @param array<string, mixed> $meta
     */
    private static function stringField(array $meta, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $meta) || $meta[$key] === null) {
            return null;
        }
        $value = $meta[$key];
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('Rule "%s" must be a string or scalar, %s given (%s).', $key, get_debug_type($value), $path));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function boolField(array $meta, string $key, string $path): ?bool
    {
        if (!array_key_exists($key, $meta) || $meta[$key] === null) {
            return null;
        }
        $value = $meta[$key];
        if (is_bool($value)) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf('Rule "%s" must be a boolean, %s given (%s).', $key, get_debug_type($value), $path));
    }

    /**
     * A list-of-scalars key: an array of string/int/float becomes a trimmed
     * list of non-blank strings; a bare scalar becomes a one-element list (the
     * `models: gpt-4` shorthand); absent or null is the empty list. Anything
     * else - a nested map, a boolean, an object - throws rather than guessing.
     *
     * @param array<string, mixed> $meta
     *
     * @return list<string>
     */
    private static function stringListField(array $meta, string $key, string $path): array
    {
        if (!array_key_exists($key, $meta) || $meta[$key] === null) {
            return [];
        }
        $value = $meta[$key];

        if (is_string($value) || is_int($value) || is_float($value)) {
            $value = [(string) $value];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Rule "%s" must be a list of scalars, %s given (%s).', $key, get_debug_type($value), $path));
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }

                continue;
            }

            throw new InvalidArgumentException(sprintf('Rule "%s" entries must be scalars, %s given (%s).', $key, get_debug_type($item), $path));
        }

        return $out;
    }
}
