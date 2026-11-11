<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;

/**
 * The `cmd.` / `media.` catalogue (cc-review-1111 NIT n22, COMP-C recovery
 * lane): the guard-genre hole the review named — `cli.` is policed by
 * {@see \SugarCraft\Code\Tests\Cli\CliLangCatalogueTest}, `chat.`/`host.` by
 * {@see \SugarCraft\Code\Tests\Chat\ChatHostLangCatalogueTest} and
 * `tui.`/`palette.` by {@see \SugarCraft\Code\Tests\Tui\TuiLangTest}, but
 * nothing asked whether a `cmd.` or `media.` entry is ever read. The review's
 * fingerprint — `media.voice.not_recording` — is one member of the hole; the
 * live census this file runs measures the population at the commit it was
 * written against as 163 defined but never-read entries, 162 of them the
 * `cmd.{name}.label|hint|description` and `cmd.category.{name}` families the
 * slash/palette metadata left behind when spec files took that text over
 * (CommandSpec::displayField() reads it off the spec, and no src call names
 * the catalogue row). Deleting them is lang-pack work this test lane does not
 * own; this guard CLOSES THE POPULATION at its measured size — every further
 * defined-but-unread cmd./media. key reddens unless a row here adjudicates
 * it, and every row here reddens the moment its warrant dies (key removed —
 * lane F-n20n21 is deleting media.voice.not_recording as this was written —
 * or a reader appears), the bidirectional roster idiom the family rosters use.
 *
 * The dynamic-read escape this genre needed and the four older guards never
 * had to write: `cmd.` keys also reach Lang::t() through string expressions a
 * literal scan cannot name — "cmd.context.category.{$this->value}" and
 * "cmd.mcp-auth.{$verb}.{usage,no-client,unknown}". Each such expression is
 * rebuilt into a pattern (static runs quoted, every interpolated run a
 * name-class wildcard) and a defined key is READ when a literal names it
 * whole or a pattern matches it whole. Expressions qualify only when their
 * FIRST static run starts with the `cmd.` or `media.` block — an unknown
 * prefix cannot acquit anything (fail-closed direction).
 *
 * The exists half runs the family rule, not the raw shape: a quoted literal
 * counts as a catalogue reference only when it is itself defined or its
 * two-segment stem (`cmd.agents`, `media.voice`) is a live family. Without
 * it the scan would accuse keybinding ids and wire field names that merely
 * share the dotted shape — `media.open_form` (KeyBindingRegistry),
 * `media.call_id`/`media.progress`/`media.eta_seconds` (OtlpEncoder), and
 * `media.fetch` (MethodSchemas) — exactly the collision TuiLangTest's
 * "palette.move" paragraph warns about. Those five are pinned as the known
 * foreign-shape set, so the exemption is a declared boundary with a name, not
 * silence, and a NEW no-live-family dotted literal must join the table rather
 * than wave through unexamined. A typo under a wholly undefined family is the
 * blind spot this admits; it renders the raw key at runtime and would surface
 * in whatever test prints that line.
 *
 * Scope of the walk is src/ and bin/ (Session/SqliteConnectCensusTest
 * precedent for the bin/ leg: the entrypoint names lang keys a src-only scan
 * would miss). Keys quoted only under tests/ are NOT a read — a dead entry
 * cannot be kept alive by its own pin. Heredoc interpolation is outside the
 * pattern class (no cmd./media. heredoc exists today; one would land in the
 * dead census and be adjudicated when it appears).
 */
final class CmdMediaLangCatalogueTest extends TestCase
{
    private const CATALOGUE_SRC = __DIR__ . '/../../src';

    private const BIN = __DIR__ . '/../../bin';

    private const ENGLISH = __DIR__ . '/../../lang/en.php';

    /**
     * Every cmd./media. entry lang/en.php defines that nothing in src/ or bin/
     * reads, measured at commit 59057d341 and dated by family.
     *
     * 162 rows are the slash/palette spec-migration residue: the label, hint,
     * description and category strings the command metadata used to ask the
     * catalogue for, now owned by the per-command spec files. Their removal
     * (all twelve locales, plus the tallies that move with it) is REPORTED to
     * closeout, not taken here. The last row is the review's fingerprint key,
     * owned by lane F-n20n21.
     *
     * @var list<string>
     */
    private const KNOWN_UNREAD_CMD_MEDIA = [
        'cmd.agents.description',
        'cmd.agents.hint',
        'cmd.agents.label',
        'cmd.bg.description',
        'cmd.bg.hint',
        'cmd.branch.description',
        'cmd.branch.label',
        'cmd.btw.description',
        'cmd.btw.hint',
        'cmd.budget.description',
        'cmd.budget.hint',
        'cmd.category.agents',
        'cmd.category.app',
        'cmd.category.appearance',
        'cmd.category.instructions',
        'cmd.category.layout',
        'cmd.category.mcp',
        'cmd.category.memory',
        'cmd.category.model',
        'cmd.category.rules',
        'cmd.category.session',
        'cmd.category.tools',
        'cmd.category.workflow',
        'cmd.clear.description',
        'cmd.close-tab.description',
        'cmd.close-tab.label',
        'cmd.commit.description',
        'cmd.commit.hint',
        'cmd.compact.description',
        'cmd.compact.hint',
        'cmd.compress.description',
        'cmd.compress.hint',
        'cmd.context.description',
        'cmd.context.hint',
        'cmd.copy.description',
        'cmd.copy.hint',
        'cmd.decompress.description',
        'cmd.decompress.hint',
        'cmd.diff.description',
        'cmd.diff.hint',
        'cmd.docs.description',
        'cmd.docs.label',
        'cmd.doctor.description',
        'cmd.editor.description',
        'cmd.editor.hint',
        'cmd.effort.description',
        'cmd.effort.hint',
        'cmd.exit.description',
        'cmd.exit.label',
        'cmd.export.description',
        'cmd.export.hint',
        'cmd.export.label',
        'cmd.fast.description',
        'cmd.fast.hint',
        'cmd.fork.description',
        'cmd.fork.hint',
        'cmd.generate.description',
        'cmd.generate.form.description',
        'cmd.generate.form.label',
        'cmd.generate.hint',
        'cmd.goal.description',
        'cmd.goal.hint',
        'cmd.grind.description',
        'cmd.grind.hint',
        'cmd.handoff.description',
        'cmd.handoff.hint',
        'cmd.help.description',
        'cmd.hooks.description',
        'cmd.hooks.hint',
        'cmd.init.description',
        'cmd.init.hint',
        'cmd.insights.description',
        'cmd.insights.hint',
        'cmd.instructions.description',
        'cmd.instructions.hint',
        'cmd.keys.description',
        'cmd.keys.hint',
        'cmd.layout.description',
        'cmd.layout.hint',
        'cmd.layout.label',
        'cmd.loop.description',
        'cmd.loop.hint',
        'cmd.mcp.description',
        'cmd.mcp.hint',
        'cmd.mcp.label',
        'cmd.memory.description',
        'cmd.model.description',
        'cmd.model.hint',
        'cmd.model.label',
        'cmd.new.description',
        'cmd.new.hint',
        'cmd.new.label',
        'cmd.newpicker.description',
        'cmd.newpicker.label',
        'cmd.newrule.description',
        'cmd.newrule.hint',
        'cmd.notices.description',
        'cmd.output-style.description',
        'cmd.output-style.hint',
        'cmd.pane-dock-left.description',
        'cmd.pane-dock-left.label',
        'cmd.pane-dock-right.description',
        'cmd.pane-dock-right.label',
        'cmd.pane.description',
        'cmd.pane.hint',
        'cmd.permissions.description',
        'cmd.permissions.hint',
        'cmd.play.description',
        'cmd.play.hint',
        'cmd.pr.description',
        'cmd.pr.hint',
        'cmd.pruning.description',
        'cmd.pruning.hint',
        'cmd.recap.description',
        'cmd.recompress.description',
        'cmd.recompress.hint',
        'cmd.redo.description',
        'cmd.rename.description',
        'cmd.rename.hint',
        'cmd.rename.label',
        'cmd.rendermode.description',
        'cmd.rendermode.hint',
        'cmd.review.description',
        'cmd.review.hint',
        'cmd.rewind.description',
        'cmd.rewind.hint',
        'cmd.rules.description',
        'cmd.rules.hint',
        'cmd.schedules.description',
        'cmd.schedules.hint',
        'cmd.security-review.description',
        'cmd.security-review.hint',
        'cmd.sendto.description',
        'cmd.sendto.hint',
        'cmd.session-delete.description',
        'cmd.session-delete.label',
        'cmd.session-pin.description',
        'cmd.session-pin.label',
        'cmd.sessions.description',
        'cmd.sessions.hint',
        'cmd.sessions.label',
        'cmd.settings.description',
        'cmd.settings.hint',
        'cmd.settings.label',
        'cmd.share.description',
        'cmd.share.hint',
        'cmd.share.label',
        'cmd.shells.description',
        'cmd.shells.hint',
        'cmd.skills.description',
        'cmd.skills.hint',
        'cmd.status.description',
        'cmd.sweep.description',
        'cmd.sweep.hint',
        'cmd.theme.description',
        'cmd.theme.label',
        'cmd.undo.description',
        'cmd.usage.description',
        'cmd.usage.hint',
        'cmd.websearch.description',
        'cmd.websearch.hint',
        'cmd.workflow.description',
    ];

    /**
     * The five dotted literals that share the catalogue's shape but name
     * something else. Pinned positives: the family rule must keep NOT
     * accusing them, and each must stay outside lang/en.php — if one ever
     * became a real key it would arrive here with a row to delete.
     *
     * @var array<string, string> key => what it actually is
     */
    private const FOREIGN_SHAPED_LITERALS = [
        'media.open_form' => 'src/Commands/KeyBindingRegistry.php: a keybinding id, not a catalogue key',
        'media.call_id' => 'src/Observability/OtlpEncoder.php: an OTLP attribute name',
        'media.progress' => 'src/Observability/OtlpEncoder.php: an OTLP attribute name',
        'media.eta_seconds' => 'src/Observability/OtlpEncoder.php: an OTLP attribute name',
        'media.fetch' => 'src/Protocol/Schema/MethodSchemas.php: a protocol method name',
    ];

    public function testEveryCatalogueShapedLiteralWithALiveFamilyHasAnEnglishEntry(): void
    {
        $defined = self::definedCmdMediaKeys();
        $missing = [];
        $seen = 0;
        foreach (self::cmdMediaLiteralsAcrossSources() as $key => $file) {
            if (!self::familyIsLive($key, $defined)) {
                continue;
            }
            ++$seen;
            if (!isset($defined[$key])) {
                $missing[] = "{$key} ({$file})";
            }
        }

        self::assertGreaterThan(100, $seen, 'the family-rule scan named too few catalogue literals to be trusted');
        self::assertSame([], $missing, 'cmd./media. literals under a live family with no lang/en.php entry');
    }

    public function testTheForeignShapedLiteralsAreExactlyTheKnownOnes(): void
    {
        $literals = self::cmdMediaLiteralsAcrossSources();
        $defined = self::definedCmdMediaKeys();
        foreach (self::FOREIGN_SHAPED_LITERALS as $key => $what) {
            self::assertArrayHasKey($key, $literals, "{$key} is no longer a literal anywhere in src/ or bin/ - {$what}");
            self::assertArrayNotHasKey($key, $defined, "{$key} became a defined catalogue key - drop its FOREIGN_SHAPED_LITERALS row");
        }

        $undeclared = [];
        foreach (array_keys($literals) as $key) {
            if (!isset($defined[$key]) && !self::familyIsLive($key, $defined) && !isset(self::FOREIGN_SHAPED_LITERALS[$key])) {
                $undeclared[] = $key . ' (' . $literals[$key] . ')';
            }
        }

        self::assertSame([], $undeclared, 'dotted literals with NO live family and no FOREIGN_SHAPED_LITERALS row - a new shape the exemption rule would wave through silently');
    }

    public function testEveryDefinedKeyIsReadBySrcOrBinOrListedAsKnownResidue(): void
    {
        $readByLiteral = self::cmdMediaLiteralsAcrossSources();
        $dynamic = self::dynamicKeyExpressionsAcrossSources();
        $roster = array_flip(self::KNOWN_UNREAD_CMD_MEDIA);
        $orphans = [];
        $acquittedNow = 0;
        foreach (array_keys(self::definedCmdMediaKeys()) as $key) {
            if (isset($readByLiteral[$key]) || isset($roster[$key])) {
                continue;
            }
            if (self::matchedByAny($key, $dynamic)) {
                ++$acquittedNow;
                continue;
            }
            $orphans[] = $key;
        }

        self::assertSame([], $orphans, 'cmd./media. keys in lang/en.php that nothing in src/ or bin/ asks for and KNOWN_UNREAD_CMD_MEDIA does not adjudicate');
        self::assertGreaterThan(100, count($readByLiteral), 'the literal census found too few asks to be trusted');
        self::assertGreaterThanOrEqual(1, $acquittedNow, 'the dynamic escape acquitted nothing - either it broke or its last consumer was literalised');
    }

    public function testTheKnownResidueRosterIsExactlyTheMeasuredOrphans(): void
    {
        $defined = self::definedCmdMediaKeys();
        $read = self::cmdMediaLiteralsAcrossSources();
        $dynamic = self::dynamicKeyExpressionsAcrossSources();

        $stale = [];
        $seen = [];
        foreach (self::KNOWN_UNREAD_CMD_MEDIA as $row) {
            self::assertTrue(!isset($seen[$row]), "roster lists {$row} twice");
            $seen[$row] = true;
            if (!isset($defined[$row])) {
                $stale[] = "{$row} - deleted from lang/en.php; the row's warrant died with it";
                continue;
            }
            if (isset($read[$row])) {
                $stale[] = "{$row} - {$read[$row]} now asks for it; the row is dead weight";
                continue;
            }
            if (self::matchedByAny($row, $dynamic)) {
                $stale[] = "{$row} - a dynamic key expression now reaches it";
            }
        }
        self::assertSame([], $stale, 'KNOWN_UNREAD_CMD_MEDIA rows whose adjudication no longer holds');

        $unlisted = [];
        foreach (array_keys($defined) as $key) {
            if (!isset($read[$key]) && !isset($seen[$key]) && !self::matchedByAny($key, $dynamic)) {
                $unlisted[] = $key;
            }
        }
        self::assertSame([], $unlisted, 'cmd./media. entries defined and never read that KNOWN_UNREAD_CMD_MEDIA does not list - the n22 hole reopened');
    }

    public function testTheDynamicEscapeAcquittalsAreNamedAndReal(): void
    {
        $read = self::cmdMediaLiteralsAcrossSources();
        $dynamic = self::dynamicKeyExpressionsAcrossSources();
        self::assertNotEmpty($dynamic, 'no dynamic key expression resolves - the escape cannot be acquitting anything');

        foreach (['cmd.context.category.mcp-tools', 'cmd.context.category.system-prompt', 'cmd.mcp-auth.enable.usage', 'cmd.mcp-auth.disable.unknown'] as $key) {
            self::assertArrayNotHasKey($key, $read, "{$key} became a whole literal - a vacuity leg here needs re-deriving");
            self::assertTrue(self::matchedByAny($key, $dynamic), "{$key} is read only through an interpolated expression and the escape no longer acquits it");
        }

        $acquitted = 0;
        foreach (array_keys(self::definedCmdMediaKeys()) as $key) {
            if (!isset($read[$key]) && self::matchedByAny($key, $dynamic)) {
                ++$acquitted;
            }
        }
        self::assertSame(17, $acquitted, 'the dynamic-only acquitted population moved off 17 - re-derive this figure from the live census and the class docblock with it, never by bumping it blind');
    }

    // ── derivation ──────────────────────────────────────────────────────

    /** @return array<string, true> every cmd./media. key lang/en.php defines */
    private static function definedCmdMediaKeys(): array
    {
        $out = [];
        foreach (array_keys(require self::ENGLISH) as $key) {
            if (str_starts_with($key, 'cmd.') || str_starts_with($key, 'media.')) {
                $out[(string) $key] = true;
            }
        }

        return $out;
    }

    /**
     * A literal is under a live family when it is itself defined or some
     * defined key shares its first two dot-segments.
     *
     * @param array<string, true> $defined
     */
    private static function familyIsLive(string $key, array $defined): bool
    {
        if (isset($defined[$key])) {
            return true;
        }
        $stem = implode('.', array_slice(explode('.', $key), 0, 2)) . '.';
        foreach (array_keys($defined) as $definedKey) {
            if (str_starts_with($definedKey, $stem)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> whole quoted cmd./media. literal => first file naming it */
    private static function cmdMediaLiteralsAcrossSources(): array
    {
        $keys = [];
        foreach (self::walkedSources() as $path => $tokens) {
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = substr($token[1], 1, -1);
                    if (preg_match('/^(?:cmd|media)\.[A-Za-z0-9_.\-]+$/D', $value) === 1) {
                        $keys[$value] ??= $path;
                    }
                }
            }
        }

        return $keys;
    }

    /**
     * Every interpolated string whose first static run starts a catalogue
     * block, rebuilt into a full-key pattern: static runs quoted, interpolated
     * runs a name-class wildcard. Consecutive interpolated tokens (the curly
     * form's T_CURLY_OPEN/T_VARIABLE/.../'}' chain) each add a wildcard
     * segment; that is harmless to the match and cheaper than merging them.
     *
     * @return list<string>
     */
    private static function dynamicKeyExpressionsAcrossSources(): array
    {
        $patterns = [];
        foreach (self::walkedSources() as $tokens) {
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                if ($tokens[$i] !== '"') {
                    continue;
                }
                $segments = [];
                $hasDynamic = false;
                $closed = false;
                for ($j = $i + 1; $j < $n; $j++) {
                    $token = $tokens[$j];
                    if ($token === '"') {
                        $closed = true;
                        break;
                    }
                    if (is_array($token) && $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                        $segments[] = ['s', $token[1]];
                    } else {
                        $hasDynamic = true;
                        $segments[] = ['d', ''];
                    }
                }
                if ($closed) {
                    $i = $j;
                }

                if (!$closed || !$hasDynamic || $segments === [] || $segments[0][0] !== 's') {
                    continue;
                }
                $first = $segments[0][1];
                if (!str_starts_with($first, 'cmd.') && !str_starts_with($first, 'media.')) {
                    continue;
                }
                $regex = '';
                foreach ($segments as [$kind, $text]) {
                    $regex .= $kind === 's' ? preg_quote($text, '/') : '[A-Za-z0-9_.\-]+';
                }
                $patterns[] = '/^' . $regex . '$/D';
            }
        }

        return $patterns;
    }

    /** @param list<string> $patterns */
    private static function matchedByAny(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokenized walk of src/ plus every bin/ entrypoint, keyed by path under
     * the package root. Fail-fast: a file whose tokens come back empty is an
     * unreadable source, not a source that asked for nothing.
     *
     * @return array<string, list<array{0: int, 1: string, 2: int}|string>>
     */
    private static function walkedSources(): array
    {
        $out = [];
        $root = dirname(self::CATALOGUE_SRC);
        foreach ([self::CATALOGUE_SRC, self::BIN] as $directory) {
            if (!is_dir($directory)) {
                throw new \RuntimeException("catalogue guard: {$directory} is not a directory - the walk's domain moved");
            }
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($walk as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if ($file->getExtension() !== 'php' && !str_starts_with($name, 'candy-code')) {
                    continue;
                }
                $tokens = token_get_all((string) file_get_contents($file->getPathname()));
                if ($tokens === []) {
                    throw new \RuntimeException("token_get_all returned nothing for {$file->getPathname()}");
                }
                $out[substr($file->getPathname(), strlen($root) + 1)] = $tokens;
            }
        }
        ksort($out);

        return $out;
    }
}
