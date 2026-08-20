<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config;

use SugarCraft\Crush\Support\ContainedPath;

/**
 * The settings-file layers behind {@see \SugarCraft\Crush\Cli\Bootstrap::readUserConfig()}.
 *
 * Until this class there was ONE settings file — `~/.sugar-crush/config.json` —
 * and a project had no way to say anything at all. The layers now are, LOWEST
 * PRECEDENCE FIRST:
 *
 *   1. `<root>/.sugar-crush/settings.json`       project, meant to be committed
 *   2. `<root>/.sugar-crush/settings.local.json` project, meant to be ignored
 *   3. `~/.sugar-crush/settings.json`            user, hand-authored
 *   4. `~/.sugar-crush/config.json`              user, WRITTEN BY THE CLI
 *
 * Merged key by key, highest present key wins. Two rules make that ordering
 * statable in one sentence each, and both are choices with a cost:
 *
 * THE USER'S FILES OUTRANK THE PROJECT'S — the reverse of the convention most
 * editors use, where a project overrides a user. Taken deliberately: layer 1
 * ARRIVED WITH A CLONE. If the project outranked the user, a checkout could
 * change a setting the user had already made for themselves, and the user's own
 * file would look broken. Here a project can only fill in what the user left
 * unsaid, which is the whole of what a project legitimately knows.
 *
 * `config.json` OUTRANKS `settings.json` even though it is the DEPRECATED name.
 * It is the file {@see \SugarCraft\Crush\Cli\Bootstrap::writeUserConfig()}
 * writes — Ctrl+P "Switch model", `/theme` — so it must also be the file read
 * back. Ranked the other way round, a `settings.json` that names `theme` would
 * make every `/theme` appear to do nothing, with no error anywhere and nothing
 * in the UI pointing at the file responsible. A deprecated NAME that still
 * decides is a smaller problem than a control that silently does not work.
 *
 * WHAT IS NOT LAYERED, and this is the security boundary rather than a scoping
 * shortcut: any key absent from {@see LAYERED_KEYS} is answered by layer 4
 * ALONE, exactly as before this class existed. So `permissionMode`,
 * `permissionRules`, `trustedProjectHooks`, `trustedProjectMcp`,
 * `trustedProjectCommands` and {@see PROJECT_SETTINGS_TRUST_KEY} itself cannot
 * be written, weakened or self-granted from any lower layer — a project cannot
 * add itself to the list of trusted projects.
 *
 * `permissionMode`/`permissionRules` being absent from {@see LAYERED_KEYS} does
 * NOT mean they are unreadable from `~/.sugar-crush/settings.json`: they are, as
 * of Phase 6 item 4. It means they do not come through THIS class, whose reader
 * is deliberately tolerant. {@see LAYERED_KEYS} carries the full argument.
 *
 * The whitelist is also why nothing decorative can slip
 * in: every key in it is named by a real reader, cited on
 * {@see LAYERED_KEYS}, and a key nothing reads is worse than a missing one
 * because it looks configurable.
 *
 * RE-READ EVERY TURN, unlike the trust list that gates it, and the asymmetry is
 * worth knowing before relying on either. `EngineBackend` calls
 * {@see \SugarCraft\Crush\Cli\Bootstrap::readUserConfig()} once per turn, so
 * all four files are opened again each time: MEASURED, writing
 * `.sugar-crush/settings.local.json` mid-session flips `disabledSkills` on the
 * next turn. The TRUST list is frozen per process
 * ({@see \SugarCraft\Crush\Cli\Bootstrap::$trustedSettingsRoots}), so a
 * project cannot become trusted mid-session — but once it is trusted, a tool
 * call inside a turn can change what its own next turn reads. Defensible,
 * because the operator opted this repository in and the project tier cannot
 * reach `provider`, `instructions` or any permission key; stated because
 * "trusted, frozen" would otherwise read as covering both.
 *
 * `.sugar-crush/settings.local.json` GETS THE SAME GATE AS THE TRACKED FILE,
 * which is worth stating because the convention says "local" and `.gitignore`
 * says it is not committed. Neither is a property of a repository someone else
 * wrote: `.gitignore` is advice to the committer, `git add -f` overrides it,
 * and a hostile checkout can therefore ship a `settings.local.json` as readily
 * as a `settings.json`. The two differ in PRECEDENCE only. Treating "local" as
 * "mine, therefore trusted" would have been the whole gate defeated by a
 * filename.
 */
final class LayeredSettings
{
    /**
     * The project's shared settings file, relative to the project root.
     *
     * ONE LITERAL, not `'.sugar-crush' . '/' . 'settings.json'`, and the reason
     * is an instrument rather than taste:
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest}
     * derives its census of repository-chosen dot-paths from string literals in
     * `src/`, and a path assembled from fragments is its stated blind spot. A
     * project-tier read path spelled in halves would have been invisible to the
     * one instrument that exists to enumerate project-tier read paths — the same
     * trap {@see \SugarCraft\Crush\Agents\WorktreeConfig::readConfig()} names
     * in its own comment. {@see dir()} splits it back with `dirname()`.
     */
    public const SHARED_PATH = '.sugar-crush/settings.json';

    /**
     * The project file meant to be `.gitignore`d — see the class doc-block on
     * why that is not a trust signal. Spelled in full for {@see SHARED_PATH}'s
     * reason; {@see testTheTwoProjectFilesLiveInOneDirectory()} pins that the two
     * literals name the same directory so they cannot drift apart.
     */
    public const LOCAL_PATH = '.sugar-crush/settings.local.json';

    /**
     * The user-tier hand-authored file's basename, joined to a directory by
     * {@see userLayer()} — whose choice of directory is the caller's, and in
     * production is {@see \SugarCraft\Crush\Cli\Bootstrap::userSettingsDirOrNull()}:
     * `trustedConfigDirPath()`, the home-OWNED `~/.sugar-crush`.
     *
     * NOT a dot-path literal, unlike the two above, because there is no
     * repository-chosen component in the name. The DIRECTORY is where the
     * gating lives, and the first cut of this doc-block named the wrong one: it
     * said `configDirPath()`, "rooted at `~` and already gated by the
     * home-ownership check", while `Bootstrap::readUserConfig()` was in fact
     * passing `dirname(userConfigPath())` — which follows `--config` and is
     * therefore neither rooted at `~` nor gated by anything. Two wrong halves
     * that read as one right sentence. Both are corrected: the caller now
     * resolves the home-owned directory, and this names the method it resolves
     * it with. See `Bootstrap::readUserConfig()` for the measurement.
     */
    public const USER_FILE = 'settings.json';

    /**
     * The directory both project files live in, relative to the project root —
     * `dirname()` of the literals above rather than a third constant that could
     * disagree with them.
     */
    public static function dir(): string
    {
        return \dirname(self::SHARED_PATH);
    }

    /**
     * The user-config key listing project roots whose settings files may be
     * read at all — a FOURTH key of the shape
     * {@see \SugarCraft\Crush\Cli\Bootstrap::trustedProjectRoots()} already
     * parses for hooks, MCP and command files, and separate from all three for
     * their reason: trusting a repository to start a server is not the same
     * grant as trusting it to choose a model, and one key covering both would
     * make the narrower grant unavailable.
     */
    public const PROJECT_SETTINGS_TRUST_KEY = 'trustedProjectSettings';

    /**
     * Every key a lower layer may contribute, each with the reader that makes
     * it real. Nothing is listed here speculatively.
     *
     *  - `provider`   {@see \SugarCraft\Crush\Cli\Bootstrap::selectedProviderName()},
     *                 and `backend()`'s persisted-provider tier.
     *  - `theme`      `Bootstrap::chat()`, as `Chat`'s `themeName`.
     *  - `titleModel` `Bootstrap::titleBackend()` via `toollessBackend()`.
     *  - `summaryModel` `Bootstrap::summaryBackend()`, same helper.
     *  - `instructions` {@see \SugarCraft\Crush\Cli\Bootstrap::forcedInstructions()}.
     *  - `disabledSkills` `Bootstrap::skillRegistry()`.
     *  - `parallelToolCalls` / `parallelToolDeadlineSeconds`
     *                 {@see \SugarCraft\Crush\Backend\EngineBackend}'s per-turn
     *                 dispatch settings, which read through `readUserConfig()`.
     *  - `allowedTools` / `disabledTools`
     *                 {@see \SugarCraft\Crush\Cli\Bootstrap::tools()}, which
     *                 filters the model-facing tool set before any of its three
     *                 `withTools()` call sites sees it.
     *
     * `allowedTools` / `disabledTools`, NOT a nested `tools: {allow, deny}`,
     * and the shape is forced rather than chosen: {@see merge()} is KEY-WISE,
     * so a single `tools` key would be replaced whole by whichever layer set it
     * — and the two halves belong to DIFFERENT TIERS
     * ({@see PROJECT_TIER_KEYS} takes `disabledTools` and refuses
     * `allowedTools`). Nested under one key, a project's `tools.deny` and a
     * user's `tools.allow` could not coexist, and gating one half without the
     * other would be unexpressible. The spelling follows `disabledSkills`,
     * which is the same idea one layer up.
     *
     * NO PERMISSION KEY HERE, and this is the one omission a reader of Phase 6
     * item 4 will come looking for. `permissionMode` and `permissionRules` ARE
     * readable from `~/.sugar-crush/settings.json` as of that item — but NOT
     * through this class, because this class's reader is TOLERANT by contract
     * (a malformed file is the absence of a layer, {@see readFile()}) and the
     * permission path may not be: `Bootstrap::permissionGate()` refuses to
     * start on a policy file it cannot parse, precisely so a stray comma cannot
     * silently downgrade a session to the permissive default. Routing the
     * permission keys through {@see merge()} would have handed them that
     * tolerance. They are read instead by
     * {@see \SugarCraft\Crush\Cli\Bootstrap::permissionSettingsLayer()},
     * which applies the strict reader to the same user-tier file and no project
     * file at all, and whose result `Bootstrap::permissionConfigLayers()` puts
     * BENEATH `config.json`. (`permissionPolicy()` is what this line said
     * before, and no such method has ever existed — a `{@see}` naming a symbol
     * that is not there is the same defect as a decorative config key, one
     * layer up: it reads as a reader and is not one.) Listing these keys here
     * would have been the opposite of the no-decorative-surface rule: a key
     * with a reader that ignores it.
     *
     * NO `model` KEY, deliberately, and it is the one name a reader expects to
     * find here. Nothing reads a top-level `model` out of the user config:
     * `Subcommands::models()`'s `$config['model']` is a PROVIDER config out of
     * `Bootstrap::availableProviders()`, and the two model-shaped user keys that
     * do exist are the two named above. Adding `model` would have been surface
     * with no reader — configurable-looking and inert.
     *
     * @var list<string>
     */
    public const LAYERED_KEYS = [
        'provider',
        'theme',
        'titleModel',
        'summaryModel',
        'instructions',
        'disabledSkills',
        'parallelToolCalls',
        'parallelToolDeadlineSeconds',
        'allowedTools',
        'disabledTools',
    ];

    /**
     * The subset of {@see LAYERED_KEYS} a PROJECT may contribute, even a trusted
     * one. Narrower than the user tier's, because "I trust this repository" and
     * "this repository may choose that particular thing" are different
     * questions and the second one has a different answer per key:
     *
     *  - `theme` is pixels.
     *  - `titleModel` / `summaryModel` name a model WITHIN the provider the user
     *    already chose, on the user's own credential and endpoint. The cost of a
     *    hostile value is tokens and a wrong title.
     *  - `disabledSkills` REMOVES a capability rather than adding one, and "this
     *    repo has no use for the terraform skill" is the archetypal thing a
     *    repository knows and a user does not. Gated all the same, because
     *    removing a capability can also mean removing a check the user relies on.
     *  - `parallelToolCalls` / `parallelToolDeadlineSeconds` are throughput. A
     *    repository whose test suite serialises badly has a real reason.
     *  - `disabledTools` names tools to REMOVE from the set
     *    {@see \SugarCraft\Crush\Cli\Bootstrap::tools()} built, which is the
     *    `disabledSkills` argument one layer down: "there is no MCP server in
     *    this checkout, stop offering WebSearch" is a thing a repository knows.
     *    Gated for `disabledSkills`' reason too — removing a capability can
     *    remove a check — and it CANNOT widen anything, because that set is the
     *    ceiling and both tool keys only ever shrink it.
     *
     * `allowedTools` IS ABSENT, and it is the interesting one, because on
     * capability alone it looks as safe as its sibling: a whitelist intersected
     * with an existing set cannot add a tool either. Two reasons it is still
     * user-tier only.
     *
     * First, a whitelist's effect is defined by what it OMITS, so it is the one
     * shape in which a small, innocuous-looking value deletes almost
     * everything. `allowedTools: ["Bash"]` removes `Read`, `Edit`, `Write`,
     * `Grep` and `Glob` in one line — and what the model does next is not "less
     * work", it is the SAME work through `Bash`, which reaches the permission
     * gate as opaque shell text instead of as a reviewable path. That is a
     * privilege escalation by degradation: strictly fewer tools, strictly
     * coarser review. `disabledTools` can express the same attack, but only by
     * naming every tool it removes, which is a value an operator reading the
     * file can see.
     *
     * Second, a whitelist is what a user reaches for when they want a CEILING,
     * and a ceiling a checkout can rewrite is not one. What makes that hold when
     * both keys are in play is not an ordering:
     * {@see \SugarCraft\Crush\Cli\Bootstrap::filterToolSet()} keeps a tool iff
     * the allow-list admits it AND the deny-list does not name it — one
     * conjunction, no stages — so there is no later step in which a project's
     * `disabledTools` could re-admit what the user's `allowedTools` excluded.
     *
     * @var list<string>
     */
    public const PROJECT_TIER_KEYS = [
        'theme',
        'titleModel',
        'summaryModel',
        'disabledSkills',
        'parallelToolCalls',
        'parallelToolDeadlineSeconds',
        'disabledTools',
    ];

    /**
     * {@see LAYERED_KEYS} minus {@see PROJECT_TIER_KEYS} — the keys no project
     * file may contribute, at any trust level.
     *
     * DERIVED, not written out, so the two lists above cannot drift apart into a
     * third list that agrees with neither. Today it is `provider`,
     * `instructions` and `allowedTools` — the third one's argument is on
     * {@see PROJECT_TIER_KEYS}, next to the sibling key that IS allowed, since
     * that is where the two have to be compared:
     *
     * `provider` is not "which of my accounts". The value is a NAME, and
     * {@see \SugarCraft\Crush\Providers\ProviderFactory::defaultConfig()}
     * resolves a name it does not recognise against the `providers` map of a
     * `config.dev.json` — a map whose entries carry `base_uri`. So the set a
     * project-chosen name resolves against is not one the user enumerated, and
     * the miss path is a file read. Switching provider also switches which API
     * key is spent and which host every prompt in the session is sent to, which
     * is the one decision that should never be made by a file that arrived with
     * a clone.
     *
     * `instructions` is a list of globs whose FILE CONTENTS
     * ({@see \SugarCraft\Crush\Context\InstructionFileLoader::loadForced()})
     * become part of the system prompt. Containment keeps those globs inside the
     * checkout, so a project value cannot read outside it — the harm is not a
     * file-read primitive, it is that "forced" means the user declared this text
     * authoritative. A project value would let a repository force any file it
     * ships, under any name, which is precisely the review a user performs by
     * looking at the instruction files they know about.
     *
     * NO `src/` CONSUMER, DELIBERATELY, and it is a seam rather than dead code.
     * {@see projectLayer()} filters on {@see PROJECT_TIER_KEYS} — the positive
     * list — because that is the direction a filter has to run in; the
     * COMPLEMENT is what the security argument is stated over, in this
     * doc-block, in `sugar-crush/README.md` and in
     * {@see \SugarCraft\Crush\Tests\Config\LayeredSettingsTest::testTheUserTierOnlyKeysAreExactlyTheLayeredKeysNoProjectMaySet()}.
     * Deriving it rather than writing a third list is what stops the two
     * constants drifting into a claim that matches neither. A reader
     * (`/doctor`, a settings pane) that wants to tell a user why their
     * project's `provider` was ignored has the answer here already.
     *
     * @return list<string>
     */
    public static function userTierOnlyKeys(): array
    {
        return array_values(array_diff(self::LAYERED_KEYS, self::PROJECT_TIER_KEYS));
    }

    /**
     * The merged view: `$userConfig` with {@see LAYERED_KEYS} backfilled from
     * the lower layers wherever `$userConfig` is silent about them.
     *
     * `$userConfig` arrives ALREADY READ and is passed through UNFILTERED, which
     * is what makes this safe to drop under an existing reader: for every key
     * this class does not name, the return value is `$userConfig` itself.
     *
     * @param array<string, mixed> $userConfig layer 4, the CLI-written file
     * @param array<string, mixed> $userSettings layer 3, already filtered by {@see userLayer()}
     * @param array<string, mixed> $projectSettings layers 1+2, already filtered by {@see projectLayer()}
     * @return array<string, mixed>
     */
    public static function merge(array $userConfig, array $userSettings, array $projectSettings): array
    {
        // array_merge, not `+`: later wins on collision, which is the direction
        // stated in the class doc-block. Written lowest-first so the reading
        // order of this line is the precedence order.
        return array_merge($projectSettings, $userSettings, $userConfig);
    }

    /**
     * Layer 3 — `<userConfigDir>/settings.json`, filtered to {@see LAYERED_KEYS}.
     *
     * FILTERED even though this is the user's OWN file, for a reason that is not
     * defence: `permissionRules` and the `trustedProject*` keys are read by
     * {@see \SugarCraft\Crush\Cli\Bootstrap}'s permission path, which opens
     * `config.json` directly and does not come through here at all. Accepting
     * them in `settings.json` would produce a key that parses, validates, sits
     * in the file the user is looking at, and does nothing.
     *
     * @return array<string, mixed>
     */
    public static function userLayer(string $userConfigDir): array
    {
        return self::only(
            self::readFile(rtrim($userConfigDir, '/') . '/' . self::USER_FILE),
            self::LAYERED_KEYS,
        );
    }

    /**
     * Layers 1+2 — the project's two settings files, filtered to
     * {@see PROJECT_TIER_KEYS}, or `[]` when this project may not contribute.
     *
     * `$trusted` IS A REQUIRED ARGUMENT AND NOT AN INTERNAL LOOKUP, so that a
     * caller cannot reach the project tier without having answered the trust
     * question. The check itself belongs to
     * {@see \SugarCraft\Crush\Cli\Bootstrap}, which is where the user's config
     * and the home-ownership gate live; this class must not grow a second,
     * differently-behaved copy of it. Passing `false` returns `[]` rather than
     * throwing: "not trusted" is an ordinary state, and it is the state a fresh
     * install is in.
     *
     * TWO CONTAINMENT BOUNDARIES, the same pair every repository-chosen read in
     * this package carries and for the same reason
     * ({@see \SugarCraft\Crush\Agents\WorktreeConfig::readConfig()} is the
     * closest sibling):
     *
     *  - `<root>/.sugar-crush` must resolve STRICTLY inside `<root>`
     *    ({@see ContainedPath::below()}), so a committed
     *    `.sugar-crush -> /elsewhere` relocates the whole settings directory
     *    instead of tripping the per-file check;
     *  - each settings file must resolve inside that directory
     *    ({@see ContainedPath::within()}), so `settings.json -> ~/.ssh/config`
     *    is refused on its own even when the directory is genuine.
     *
     * A refused file is the ABSENCE of a layer, not an error. There is no
     * channel to report through — the caller is a tolerant config read that
     * `EngineBackend` performs once per turn — and every key here has a working
     * answer without it. Silence in the safe direction; the noisy direction
     * would be a warning per turn.
     *
     * @param string $projectRoot the repository being operated on
     * @param bool $trusted whether the operator listed this root under
     *        {@see PROJECT_SETTINGS_TRUST_KEY}
     * @return array<string, mixed>
     */
    public static function projectLayer(string $projectRoot, bool $trusted): array
    {
        if (!$trusted || trim($projectRoot) === '') {
            return [];
        }

        $dir = rtrim($projectRoot, '/') . '/' . self::dir();
        if (!ContainedPath::below($dir, $projectRoot)) {
            return [];
        }

        $layers = [];
        // Shared first, local second: local is the higher of the two, and the
        // order of this array IS that precedence.
        foreach ([self::SHARED_PATH, self::LOCAL_PATH] as $relative) {
            $path = rtrim($projectRoot, '/') . '/' . $relative;
            if (!ContainedPath::within($path, $dir)) {
                continue;
            }

            $layers[] = self::only(self::readFile($path), self::PROJECT_TIER_KEYS);
        }

        return array_merge(...[[], ...$layers]);
    }

    /**
     * A settings file decoded, or `[]` for anything this class may not use — a
     * missing file, an unreadable one, invalid JSON, or valid JSON that is not
     * an object.
     *
     * `@`-silenced for {@see \SugarCraft\Crush\Cli\Bootstrap::readUserConfig()}'s
     * reason: the `false` branch below IS the handling for an unreadable file,
     * and without the silence a chmod'ed-away settings file leaks a
     * `Permission denied` into the middle of the TUI's own output — and fails
     * any suite running with `failOnWarning`.
     *
     * @return array<string, mixed>
     */
    private static function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        // A top-level JSON array is not a settings file, and this refuses it
        // HERE rather than relying on the filter downstream.
        //
        // REDUNDANT TODAY, AND SAID SO BECAUSE A MUTATION PROVED IT. Removing
        // this clause changes no observable behaviour: a JSON list decodes to
        // integer keys, {@see only()} keeps only the named string keys, so a
        // list already contributes nothing. The first draft of this comment
        // claimed the list "would merge by integer key into the settings map",
        // which was simply false — nothing merges before `only()` runs. Kept
        // anyway, and not as decoration: `only()`'s whitelist is a scoping
        // decision that a future round could widen (a "pass unknown keys
        // through" change is exactly the kind that gets made), and this clause
        // is the one that does not depend on it. What must not happen is a test
        // written to "cover" it — no test can distinguish the two versions
        // through this class's public surface, and one that appeared to would be
        // pinning `only()`.
        return \is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /**
     * `$data` restricted to `$keys`, preserving ABSENCE.
     *
     * `array_key_exists`, not `isset`/`??`: a key present with a `null` value is
     * a statement ("I do not want a forced instruction list") and has to
     * outrank a lower layer the same way any other value does. Filling it in
     * from below would make `null` mean "unset", and then no layer could ever
     * turn a lower one off.
     *
     * @param array<string, mixed> $data
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function only(array $data, array $keys): array
    {
        $kept = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $data)) {
                $kept[$key] = $data[$key];
            }
        }

        return $kept;
    }
}
