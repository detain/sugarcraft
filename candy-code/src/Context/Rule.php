<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use InvalidArgumentException;
use SugarCraft\Code\Context\Triggers\IntentTrigger;
use SugarCraft\Code\Context\Triggers\KeywordTrigger;
use SugarCraft\Code\Context\Triggers\PathTrigger;
use SugarCraft\Code\Context\Triggers\Trigger;
use SugarCraft\Code\Support\Frontmatter;

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
 * trigger keys `paths:` and `keywords:`, plus the two INT-2 foreign-tree keys
 * that map ONTO existing fields rather than minting new ones — `globs:` is read
 * as an alias of `paths:` (Cursor's spelling, and a comma-separated scalar
 * spells several patterns in one line, so it splits), and `alwaysApply:` is
 * Cursor's applicability declaration: `false` BESIDE path patterns leaves the
 * rule enabled and path-scoped, and only `false` with no patterns at all folds
 * into `enabled` with AND semantics (Claude's pure opt-out) — the reason that
 * fold records itself in {@see $disabledBy}. The frontmatter grammar itself
 * (the `---` fence, scalar coercion, list-of-scalars) is {@see Frontmatter},
 * reached through the same single fenced-block read {@see \SugarCraft\Code\Commands\CommandSpec::fromFile()}
 * uses, so a rules file and a command file cannot disagree about what a block
 * of YAML means. Of these, `models` is acted on outside this class: since
 * ISS-068 {@see RuleLoader::matchesSessionModel()} judges it against the
 * session model at emission; `keywords` reaches {@see $triggers} and awaits
 * the wiring named below, its inertness announced at launch by
 * {@see \SugarCraft\Code\Support\FrontmatterKeyAudit}.
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
 * GLOB DIALECT - RESOLVED, and this class is not where it is defined. The
 * divergence this paragraph used to record (F-PATHDIALECT) is closed: the older
 * matcher in {@see \SugarCraft\Code\Skills\SkillRegistry::compilePathPattern()}
 * and {@see PathTrigger} both compile through
 * {@see \SugarCraft\Code\Util\PathGlob}, one compiler, one dialect, and the
 * dialect is the `fnmatch()`-style one that the skill channel has always
 * answered with - `*` crosses `/`, `?` is any single character, `[…]` is a
 * character class, a trailing `/**` makes its own separator optional. That
 * class's doc-block states the whole dialect to the character and carries the
 * four measured reasons it won over the stricter segment-scoped reading, so this
 * paragraph does not restate it and no downstream consumer of a rule's `paths:`
 * needs a flavour of its own.
 *
 * WHAT REMAINS DIFFERENT between the two matchers is a POLICY and not a
 * dialect: on a glob whose compiled regex PCRE refuses to execute, the skill
 * channel answers from {@see \SugarCraft\Code\Skills\SkillRegistry::legacyPathMatch()}
 * and a rule trigger answers no match. {@see PathTrigger} owns no older
 * predicate to answer from, and firing a rule on an answer that was never
 * computed is worse than not firing it.
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
 * share - which is exactly why {@see \SugarCraft\Code\Context\RuleLoader} sorts
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
     * Same single-fence read {@see \SugarCraft\Code\Commands\CommandSpec::FRONTMATTER_PATTERN}
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
     * THREE, AND P6.S3 DID NOT ADD A FOURTH FOR RULEBOOKS. `~/.candy-code/rulebooks/`
     * is a fourth DIRECTORY, but a tier here records WHO WROTE the bytes, because
     * that is the only thing the prompt's framing can act on: the tier picks the
     * fence and the authority preamble, and nothing else. A rulebook is written
     * by the operator of this machine in their own home directory, which is
     * exactly what `user` already means, so a `rulebook` value would be a
     * directory name masquerading as an authority level - and it would render
     * into a fence whose preamble lied about it. `user` is therefore the correct
     * tier for a pack, not a shortcut: see the provenance paragraph on
     * {@see \SugarCraft\Code\Context\RuleLoader::loadUserRulebooks()}.
     */
    private const TIERS = ['user', 'project', 'root'];

    /**
     * Which directory family a loaded file came from: {@see ORIGIN_NATIVE} for
     * this port's own `~/.candy-code` trees, {@see ORIGIN_CLAUDE} and
     * {@see ORIGIN_CURSOR} for the foreign trees INT-2 folds in.
     *
     * PROVENANCE, NOT AUTHORITY - the rulebooks argument, reused verbatim where
     * it applies. A foreign directory does NOT mint a tier: the bytes under
     * `~/.claude/rules` were written by the operator of this machine, exactly
     * like the bytes under `~/.candy-code/rules`, so the tier stays `user` and
     * the prompt's framing stays honest. What origin carries is the answer to
     * "which directory family did this come from", the one fact the `/rules`
     * Source column and a toggle notice must show - see {@see badge()}.
     */
    public const ORIGIN_NATIVE = 'native';
    public const ORIGIN_CLAUDE = 'claude';
    public const ORIGIN_CURSOR = 'cursor';

    /** @var list<string> */
    public const ORIGINS = [self::ORIGIN_NATIVE, self::ORIGIN_CLAUDE, self::ORIGIN_CURSOR];

    /**
     * The machine-readable {@see $disabledBy} reason recorded when a rule's own
     * `alwaysApply: false` — and nothing else — is what switched it off at the
     * parse boundary, i.e. the key arrived with no path patterns to make it
     * Cursor's apply-on-match declaration (C-CTX-1.b). A rule disabled by its
     * native `enabled: false` carries no reason: that key is its own explanation.
     */
    public const DISABLED_ALWAYS_APPLY = 'alwaysApply';

    /**
     * @param string           $name        Stable identifier: the frontmatter `name:` when present, else the loader's derived filename stem.
     * @param string           $body        The prompt text (everything after the closing `---`), un-trimmed.
     * @param string           $path        The resolved absolute path this rule was read from.
     * @param string           $tier        One of {@see TIERS}.
     * @param string|null      $description The one-line self-description, or null when the file carried none.
     * @param bool             $enabled     Effective enable bit after the INT-2/C-CTX-1.b fold — `enabled:` AND-ed with `alwaysApply:` ONLY when the file carries no path patterns; a globbed `alwaysApply: false` stays enabled (Cursor's apply-on-match). Absent keys are enabled.
     * @param list<string>     $models      Model identifiers from `models:` (empty when the key is absent).
     * @param list<Trigger>    $triggers    The trigger value objects built from this file's frontmatter, in canonical order.
     * @param string           $key         The loader's pack identity for this file - never derived from frontmatter, so a `name:` cannot move what `/rules` types.
     * @param string           $origin      One of {@see ORIGINS} - which directory family the loader walked this file from; provenance for the `/rules` Source column, never an authority level.
     * @param string|null      $disabledBy  Why the PARSE switched this rule off - {@see DISABLED_ALWAYS_APPLY} for an imported `alwaysApply: false` with no patterns, null when it is enabled or its file's own `enabled: false` is the whole story. Set at {@see new()} only; {@see withEnabled()} never writes it, so a session toggle cannot masquerade as a frontmatter disable.
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
        public readonly string $origin = self::ORIGIN_NATIVE,
        public readonly ?string $disabledBy = null,
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
     * to its tier root, mirroring how {@see \SugarCraft\Code\Commands\CommandLoader}
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
     *         `enabled`/`alwaysApply`, or an out-of-range `$tier`/`$origin` operand.
     */
    public static function new(string $path, string $tier, string $content, string $fallbackName, ?string $key = null, string $origin = self::ORIGIN_NATIVE): self
    {
        if (!in_array($tier, self::TIERS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Rule tier must be one of %s, "%s" given (%s).',
                implode(', ', self::TIERS),
                $tier,
                $path,
            ));
        }
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Rule origin must be one of %s, "%s" given (%s).',
                implode(', ', self::ORIGINS),
                $origin,
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
        // C-CTX-1.b: the path patterns are resolved BEFORE the enable fold, and
        // through the glob-list reader - Cursor writes `globs:` as a
        // comma-separated scalar, and one line then spells several patterns.
        $paths = self::globListField($meta, 'paths', $path);
        if ($paths === []) {
            // INT-2: foreign trees spell the path trigger `globs:` (Cursor's
            // key); it is an ALIAS of `paths:`, and `paths:` wins when one file
            // somehow declares both.
            $paths = self::globListField($meta, 'globs', $path);
        }
        $enabledFlag = self::boolField($meta, 'enabled', $path) ?? true;
        $alwaysApply = self::boolField($meta, 'alwaysApply', $path);
        // INT-2/C-CTX-1.b: `alwaysApply: false` (Claude/Cursor spelling of the
        // opt-out) folds into `enabled` with AND semantics ONLY when the file
        // carries no path patterns. Beside patterns it is Cursor's "apply when a
        // match fires" declaration: the rule stays enabled and rides the
        // path-scoped channel ({@see RulePathNudge}), never the standing splice.
        // When the fold IS what switched a rule off, the reason is recorded so
        // the `/rules` listing can explain a row whose file never said
        // `enabled: false` - a mis-map here would leave an imported rule
        // standing in every prompt, the exact hazard FrontmatterKeyAudit exists
        // to catch.
        $enabled = $enabledFlag && (($alwaysApply ?? true) || $paths !== []);
        $disabledBy = $enabledFlag && $alwaysApply === false && $paths === []
            ? self::DISABLED_ALWAYS_APPLY
            : null;
        $models = self::stringListField($meta, 'models', $path);

        return new self(
            name: $name,
            body: $body,
            path: $path,
            tier: $tier,
            description: $description,
            enabled: $enabled,
            models: $models,
            triggers: self::buildTriggers($meta, $description, $path, $paths),
            key: $key ?? $fallbackName,
            origin: $origin,
            disabledBy: $disabledBy,
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
     * @param list<string>         $paths The path patterns already resolved at the fold (C-CTX-1.b): `paths:` with `globs:` as its alias, comma scalars split. Passed in, not re-read, so the trigger and the enable decision can never disagree about what counted as a pattern.
     *
     * @return list<Trigger>
     */
    private static function buildTriggers(array $meta, ?string $description, string $path, array $paths): array
    {
        $triggers = [];

        $keywords = self::stringListField($meta, 'keywords', $path);
        if ($keywords !== []) {
            $triggers[] = KeywordTrigger::new($keywords);
        }

        // INT-2: foreign trees spell the path trigger `globs:` (Cursor's key); it
        // is an ALIAS of `paths:` and `paths:` wins when one file declares both -
        // both facts are already folded into $paths by the caller. C-CTX-1.b adds
        // that a comma-packed scalar names SEVERAL patterns, so a lone
        // `globs: "*.js, *.ts"` builds one PathTrigger over two patterns rather
        // than one never-matching string.
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
     * {@see \SugarCraft\Code\Context\RulesState::effectiveRule()} is the one
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
     * The `/rules` Source-column badge for this rule's origin: `''` for the
     * native directories (where the directory name itself is the label the
     * column prints), else `[claude]` / `[cursor]` - the same bracket spelling
     * {@see \SugarCraft\Code\Skills\SkillSource::badge()} uses for imported
     * skills, so the two provenance vocabularies read alike.
     */
    public function badge(): string
    {
        return $this->origin === self::ORIGIN_NATIVE ? '' : '[' . $this->origin . ']';
    }

    /**
     * Reconstruct with selected fields replaced, trusting already-parsed state.
     * Only the fields that have a `with*()` builder are operands here; every
     * other field carries through unchanged, and the ones a builder cannot
     * touch (identity `name` and pack identity `key`, source `path`/`tier`/`origin`, and the read-only
     * `description`/`models`/`disabledBy` parsed once from the file) have no parameter to
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
            origin: $this->origin,
            disabledBy: $this->disabledBy,
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

    /**
     * A list-of-glob-patterns key: {@see stringListField()} plus Cursor's comma
     * habit. `globs: src/main.php, lib/util.php` is ONE scalar string carrying
     * TWO patterns, and a list entry may pack commas the same way, so every
     * item is comma-split and trimmed before it reaches a trigger. Splitting is
     * naive by design: an fnmatch-style `[…]` class spelling a literal comma
     * would mis-split here, and the dialect that decides what a pattern means
     * ({@see \SugarCraft\Code\Util\PathGlob}) is not consulted at parse - it
     * never sees a comma-pack, because by then there is one pattern per entry.
     *
     * @param array<string, mixed> $meta
     *
     * @return list<string>
     */
    private static function globListField(array $meta, string $key, string $path): array
    {
        $out = [];
        foreach (self::stringListField($meta, $key, $path) as $item) {
            foreach (explode(',', $item) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            }
        }

        return $out;
    }
}
