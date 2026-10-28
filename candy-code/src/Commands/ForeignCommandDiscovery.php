<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Skills\SkillSource;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * Discovers slash-command files from OTHER coding-CLI conventions — Claude
 * Code's `.claude/commands` and opencode's `command`/`commands` pair, user
 * and project — mirroring what
 * {@see \SugarCraft\Code\Skills\ForeignSkillDiscovery} proves for skills:
 * the class is a DIRECTORY LIST plus a merge order, and every containment
 * decision stays in {@see CommandLoader::loadFromDirectory()} so this port
 * has exactly one implementation of "may this path be read" rather than a
 * second one tuned to foreign paths.
 *
 * WHY THE FLOOR IS WHERE IT IS (plan_comparison.md INT-3, ruling R7 —
 * OWNER-APPROVED 2026-10-18). {@see CommandLoader::loadAll()} merges every
 * row produced here BELOW even the built-in registry: a cloned repository's
 * `.claude/commands/exit.md`, and a user's own `~/.claude/commands/compact.md`,
 * simply LOSE their name to the shipped command — silently, with no refusal
 * notice and no {@see CommandLoader::overrides()} row, because they were
 * never the merge winner. The skill side can afford foreign-above-project
 * (adding a SKILL.md changes what the model may consult); a command changes
 * what a keystroke DISPATCHES, and every import-shadowing argument this repo
 * has ever adjudicated (audit 15d-03, ISS-036) ends the same way: content
 * that arrives with a `git clone` does not redefine what the operator typed.
 * STRICT-BOTTOM is that rule taken to its floor — imported names cannot
 * redefine anything the port already answers, and no `trustedProjectCommands`
 * grant changes that, because the rows never reach the loop the grant gates.
 *
 * WHAT THE ROWS STILL ARE: tier-stamped. A project-tree foreign row carries
 * `tier: 'project'` exactly as a native one does, so its `` !`…` `` forms
 * face the same trust gate ({@see \SugarCraft\Code\Chat}), and it carries
 * `source:` so the popup can badge WHOSE FORMAT produced the row.
 *
 * NO USER TIER AT ALL when {@see HomeDirectory::owned()} is null — the same
 * refutation of an unvouched `$HOME` that
 * {@see \SugarCraft\Code\Skills\ForeignSkillDiscovery::tiers()} records as
 * measured, applied here by calling the same accessor.
 *
 * Losses between two FOREIGN claimants (same name in `.claude/commands` and
 * `.opencode/command`) are likewise silent: the skills side records them
 * through `SkillLoader::recordShadowing()` because a skill's body can differ
 * from its twin while both stay live-ish in listings; a foreign row losing to
 * another foreign row leaves exactly one live row with no user-visible twin
 * to compare, and inventing a collector for it would be the first
 * {@see CommandLoader} state this class adds. The containment refusals that
 * DO cost real files (symlink escapes) are reported — they land on the
 * loader instance Bootstrap already drains, because this class walks through
 * THE LOADER IT WAS CONSTRUCTED WITH.
 */
final class ForeignCommandDiscovery
{
    public function __construct(private readonly CommandLoader $loader = new CommandLoader()) {}

    /**
     * Claude Code's trees: `~/.claude/commands` and
     * `{projectRoot}/.claude/commands`, tagged
     * {@see SkillSource::Claude}. Project before user, the walk last-write-wins,
     * so the operator's own copy wins a name inside this convention
     * (PROJECT < USER) — the OPPOSITE bias {@see CommandLoader::loadAll()}
     * draws between the native tiers, where the project file out-ranks the
     * user one; a native project command is the operator committing an
     * override to the repo, while a cloned foreign tree is someone else's
     * format arriving by `git clone`, which must not redefine what the
     * operator hand-wrote at home.
     *
     * @return array<string, CommandSpec>
     */
    public function discoverClaude(string $projectRoot): array
    {
        return $this->walk(self::claudeDirs($projectRoot));
    }

    /**
     * opencode's trees: BOTH of its spellings — the singular `command`
     * directory {@see \SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook} names
     * alongside the plural one, since reading only `commands` would silently
     * drop the files opencode itself reads on the machines that follow its
     * docs — in the legacy `~/.opencode` pair and the XDG
     * `~/.config/opencode` pair, plus both project trees. Tagged
     * {@see SkillSource::Opencode}; later directories win inside the
     * convention (plural beats singular, XDG beats legacy), the ordering
     * rule inherited from {@see \SugarCraft\Code\Skills\ForeignSkillDiscovery::opencodeTiers()}.
     *
     * @return array<string, CommandSpec>
     */
    public function discoverOpencode(string $projectRoot): array
    {
        return $this->walk(self::opencodeDirs($projectRoot));
    }

    /**
     * Every foreign tree, tier-first: both conventions' PROJECT dirs in
     * convention order (Claude, opencode — the same cross-format tie inside a
     * tier that {@see \SugarCraft\Code\Skills\SkillManager::loadAll()} draws
     * at spec < claude < opencode), then the same trio with bundle trees FIRST
     * inside each tier (see {@see bundleDirs()}), USER dirs in that same order.
     * The walk order IS the precedence: last write wins, so
     * opencode-user outranks everything foreign, and the whole result
     * outranks nothing native — see the class doc-block for why being the
     * floor is the point.
     *
     * @return array<string, CommandSpec>
     */
    public function discover(string $projectRoot, array $bundles = []): array
    {
        $bundle = self::bundleDirs($bundles);
        $claude = self::claudeDirs($projectRoot);
        $opencode = self::opencodeDirs($projectRoot);

        return $this->walk(array_merge(
            self::tier($bundle, 'project'),
            self::tier($claude, 'project'),
            self::tier($opencode, 'project'),
            self::tier($bundle, 'user'),
            self::tier($claude, 'user'),
            self::tier($opencode, 'user'),
        ));
    }

    /**
     * The command trees the given bundles contribute, in the same
     * directory => [anchor, tier, badge, bundle-name] shape
     * {@see claudeDirs()} builds (where the fourth slot is null).
     * A bundle is anchored at ITS OWN directory — the containment boundary a
     * bundle's contents may never cross, whichever tier installed it — and
     * badged {@see SkillSource::Bundle} so the popup can say where the row
     * came from.
     *
     * The fourth slot carries the bundle's NAME so {@see walk()} can thread it
     * to the loader, which namespaces that bundle's rows as
     * `<bundle>:<file>` (C-EXT-4.c): two bundles shipping `review.md` keep two
     * distinct rows instead of one silently replacing the other in the merge
     * below, which is what "last-write-wins" measured as when both rows
     * carried the same bare key.
     *
     * Bundles are walked FIRST inside their tier (PLG-1, plan_comparison W6):
     * the plain foreign trees are ones the operator assembled by hand at
     * those exact conventional paths, while a bundle is a package someone
     * else packed — the same "content that arrives does not redefine the
     * operator's setup" argument this class draws against the native tiers,
     * applied one level down.
     *
     * @param list<\SugarCraft\Code\Bundles\Bundle> $bundles
     * @return array<string, array{0: string, 1: string, 2: SkillSource, 3: ?string}>
     */
    public static function bundleDirs(array $bundles): array
    {
        $dirs = [];

        foreach ($bundles as $bundle) {
            foreach ($bundle->commandsPaths() as $dir) {
                $dirs[$dir] = [$bundle->dir, $bundle->tier, SkillSource::Bundle, $bundle->name];
            }
        }

        return $dirs;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: SkillSource, 3: null}>
     *         directory => [the tree the directory itself must stay inside,
     *         the tier its rows are stamped with, the convention badging them,
     *         the bundle name namespaceing them — null: these are plain trees]
     */
    private static function claudeDirs(string $projectRoot): array
    {
        $dirs = [
            $projectRoot . '/.claude/commands' => [$projectRoot, 'project', SkillSource::Claude, null],
        ];

        $home = HomeDirectory::owned();
        if ($home !== null) {
            $dirs[$home . '/.claude/commands'] = [$home, 'user', SkillSource::Claude, null];
        }

        return $dirs;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: SkillSource, 3: null}>
     */
    private static function opencodeDirs(string $projectRoot): array
    {
        $dirs = [
            $projectRoot . '/.opencode/command' => [$projectRoot, 'project', SkillSource::Opencode, null],
            $projectRoot . '/.opencode/commands' => [$projectRoot, 'project', SkillSource::Opencode, null],
        ];

        $home = HomeDirectory::owned();
        if ($home !== null) {
            $dirs[$home . '/.opencode/command'] = [$home, 'user', SkillSource::Opencode, null];
            $dirs[$home . '/.opencode/commands'] = [$home, 'user', SkillSource::Opencode, null];
            $dirs[$home . '/.config/opencode/command'] = [$home, 'user', SkillSource::Opencode, null];
            $dirs[$home . '/.config/opencode/commands'] = [$home, 'user', SkillSource::Opencode, null];
        }

        return $dirs;
    }

    /**
     * @param array<string, array{0: string, 1: string, 2: SkillSource, 3: ?string}> $dirs
     * @return array<string, array{0: string, 1: string, 2: SkillSource, 3: ?string}>
     */
    private static function tier(array $dirs, string $tier): array
    {
        return array_filter($dirs, static fn (array $entry): bool => $entry[1] === $tier);
    }

    /**
     * Read every listed directory through the shared loader, merged
     * last-write-wins. Rows arrive from
     * {@see CommandLoader::loadFromDirectory()} already tier- and
     * source-stamped and sorted; re-sorting the union keeps the popup's
     * order machine-independent for the same reason the loader sorts each
     * directory.
     *
     * THE ONE EXCEPTION TO LAST-WRITE-WINS (C-EXT-4.c): bundle rows are keyed
     * `<bundle>:<file>` and never collide with each other; their FLAT
     * spellings are candidates the loader staged, admitted here only after
     * every directory has merged — a flat name survives precisely when no
     * other bundle's file claimed it and no plain foreign row of any tier did
     * either. Judging after the walk (rather than in load order) is what makes
     * "the operator's hand-assembled tree wins" true in BOTH directions,
     * whichever band the plain row was read in.
     *
     * @param array<string, array{0: string, 1: string, 2: SkillSource, 3: ?string}> $dirs
     * @return array<string, CommandSpec>
     */
    private function walk(array $dirs): array
    {
        $this->loader->resetBundleAliasRows();

        $commands = [];
        foreach ($dirs as $dir => [$anchoredIn, $tier, $source, $bundle]) {
            foreach ($this->loader->loadFromDirectory($dir, $anchoredIn, $tier, $source, $bundle) as $name => $spec) {
                $commands[$name] = $spec;
            }
        }

        foreach ($this->loader->bundleAliasRows() as $bare => $owners) {
            // Two bundles claiming one flat name cancel each other's alias;
            // so does any row already in the map under that name.
            if (count($owners) !== 1 || isset($commands[$bare])) {
                continue;
            }
            $qualified = (string) array_key_first($owners);
            // The qualified row could have been refused (unreadable file)
            // after staging; an alias may not outlive its row.
            if (isset($commands[$qualified])) {
                $commands[$bare] = $owners[$qualified];
            }
        }

        ksort($commands);

        return $commands;
    }
}
