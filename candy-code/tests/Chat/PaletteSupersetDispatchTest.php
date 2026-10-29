<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Message;
use SugarCraft\Code\Palette\PaletteAction;
use SugarCraft\Code\Palette\PaletteState;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * VIS-4: the Ctrl+P palette is a SUPERSET of the slash commands.
 *
 * The promoted rows do not get per-action arms — {@see Chat::dispatchPaletteCommand()}
 * dispatches each one's own slash handler with the bare `/<name>` text, the exact
 * route a typed submit takes. This test is the pair that makes the collapse safe:
 * for every palette row that falls to the generic tail, the palette route and the
 * typed route must produce the SAME appended transcript rows and the SAME
 * in-flight verdict, driven through the real `Chat::update()`/`runPaletteAction()`
 * doors, never a private handler.
 *
 * The exception roster below is the whole of the match arms that remain explicit
 * in `runRootPaletteAction()` — rows with no bare-slash meaning, second-level
 * lists, shell overlays and specific sub-command texts. The staleness arm keeps
 * it from accreting rows the generic tail could have carried.
 *
 * C-EXT-5.b widened the palette from "superset by judgement" to full superset:
 * the survivor roster below now lists rows that carry no PALETTE ACTION, not
 * rows absent from the palette — every slash-visible command reaches the root
 * list, survivors as `/<name>` PREFILL rows that fill a blank draft instead of
 * dispatching. {@see testTheRootPaletteListsEverySlashVisibleRow()} is the
 * coverage arm that proves it; the survivor census above stays honest next to
 * it because the census measures the action half, which is unchanged.
 */
final class PaletteSupersetDispatchTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * Palette rows that keep an explicit arm, name => why the generic tail
     * cannot carry them.
     */
    private const EXPLICIT_ARM_EXCEPTIONS = [
        'new' => 'the palette row starts a new session in place on a standalone chat (handlePaletteNewSession), not the typed /new folder flow; deck-hosted it hands off to OpenTabMsg instead (P-TABS.f)',
        'model' => 'second-level provider list, not a dispatch',
        'theme' => 'second-level theme list, not a dispatch',
        'mcp' => 'drives the panel with fully-formed "mcp auth list" text',
        'settings' => 'opens the shell settings view, which a typed /settings mid-turn deliberately never does',
        'layout' => 'drives the sub-command "/layout reset", not bare "/layout"',
        'exit' => 'returns Cmd::quit() directly',
    ];

    /**
     * Rows VIS-4 judged to stay WITHOUT a palette action, name => the measured
     * reason. Since C-EXT-5.b every one of them still reaches the palette as a
     * `/<name>` prefill row (coverage is the test below); what this roster
     * pins is the absence of an ACTION. The roster test below is
     * bidirectional: an unjudged promotion reddens it, and so does a survivor
     * quietly gaining a PaletteAction elsewhere.
     */
    private const SLASH_ONLY_SURVIVORS = [
        // PRV-2 (w5b): /effort stays a typed command by the judgement recorded
        // in its spec file - a palette row carries no argument, and half the
        // providers refuse the tier, so the picker would be a trap with a
        // nicer frame.
        'effort' => 'the dial takes a tier as its argument; the bare form reads, never sets',
        // C-PRV-3.b: /fast is the /effort sibling — the bare form reads the
        // lane, `on|off` IS the argument, and a provider or model without a
        // premium lane answers for itself. Same judgement as effort's spec
        // records: a picker row for a dial that can refuse this model would
        // be a trap with a nicer frame.
        'fast' => 'bare /fast reads the lane; on|off takes the argument — /effort sibling judgement',
        // OBS-2 (w5): the spec file records its own no-paletteAction call —
        // /status is a destination, not a mode, and the palette has no status
        // pane to pre-open. Bare form IS the whole read-only restatement.
        'status' => 'bare /status is the whole read surface; no palette pane exists to open',
        'pane' => 'bare /pane prints usage; the palette actions are the dock-left/dock-right rows',
        // OBS-3 (w5): same shape as /status by its spec's own judgement — a
        // typed readout of the durable ledger, not a panel the palette can
        // pre-open; the cross-launch /budget pairing rides the argument hint.
        'usage' => 'bare /usage is the whole ledger readout; no palette pane exists to open',
        // C-SES-8.d: /insights (2215 spec) carries the same judgement its own
        // spec file records — the report is a typed readout of the durable
        // store (plus a file on the export shelf), not a panel to pre-open.
        'insights' => 'bare /insights is the whole report readout; no palette pane exists to open',
        // C-CTX-3.a: /instructions (2405 spec) landed after VIS-4 cut this
        // census and no PaletteAction arm was minted for it; the bare form is
        // the list read, `open <n|path>` needs an argument. Since C-EXT-5.b
        // it reaches the palette as its prefill row.
        'instructions' => 'bare /instructions is the whole list read; the open verb needs a file argument',
        // W4 merge coupling: GIT-1 (w4c) added /review and /security-review
        // after VIS-4 cut this census — no PaletteAction arm yet (VIS-4
        // predates GIT-1), typed dispatch only. A palette row for the pair is
        // a W5-micro judgement, not a silent promotion here.
        'review' => 'needs a diff range; the bare form only answers with usage',
        'security-review' => 'needs a diff range; the bare form only answers with usage',
        // C-AGT-6.a (w16): /commit landed after VIS-4 cut this census; the
        // spec file (builtin-commands/2850-commit.php) records its own
        // judgement — a palette row would tempt an unintended whole-tree
        // commit, and no PaletteAction arm was minted. Census order follows
        // the registry, so this row sits on its 2850 spec position, before
        // pr's 2860.
        'commit' => 'honestly a write; the typed command IS the intent — no PaletteAction arm was minted',
        // C-AGT-6.d (w15): /pr landed after VIS-4 cut this census; the spec
        // file (builtin-commands/2860-pr.php) records its own judgement —
        // the push and the PR post run as gated writes inside the turn
        // through the Bash tool — and no PaletteAction arm was minted.
        // Typed dispatch only; a palette row is a per-row judgement, not a
        // silent promotion here.
        'pr' => 'honestly a write; the typed command IS the intent — no PaletteAction arm was minted',
        'bg' => 'bare /bg answers usage rather than backgrounding something unintended',
        // C-TOOL-3.d: bare /shells IS the read-only list (registry order puts
        // its 2905 spec ahead of schedules' 2910); the output/stop verbs need
        // a shell id, and the palette has no shell pane to pre-open.
        'shells' => 'bare /shells is the whole list surface; the argument verbs need a shell id',
        // AUT-1 (w5c), welded judgement: /schedules' bare form IS the
        // read-only list — a palette row that only prints a table has nothing
        // to pick; /loop needs its interval and prompt, the bare form answers
        // usage.
        'schedules' => 'bare /schedules is the whole read surface; no picker target exists',
        'loop' => 'needs an interval and a prompt; the bare form only answers with usage',
        'fork' => 'needs a prompt to run against the clone',
        'websearch' => 'needs a query; the bare form only answers with usage',
        'btw' => 'needs a question for the title model',
        'generate' => 'needs a prompt; the palette twin is the generate-form row',
        'sendto' => 'needs a PNG path to import',
        'play' => 'needs a video artifact to open',
    ];

    private string $paletteSandbox = '';

    protected function setUp(): void
    {
        $this->paletteSandbox = sys_get_temp_dir() . '/crush-palette-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->paletteSandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->paletteSandbox)) {
            exec('rm -rf ' . escapeshellarg($this->paletteSandbox) . ' 2>&1');
        }
    }

    private function paletteFixture(string $draft = '', bool $readOnly = false): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            readOnlySession: $readOnly,
        ))->withSize(100, 30);
    }

    /**
     * @param  array<int, Message>  $rows
     * @return list<string>
     */
    private function rowShapes(array $rows): array
    {
        return array_map(
            static fn(Message $m): string => $m->role->value . ':' . preg_replace('/\s+/u', ' ', $m->content),
            $rows,
        );
    }

    public function testEveryGenericPaletteRowBehavesExactlyLikeItsTypedSpelling(): void
    {
        $walked = 0;
        foreach (CommandRegistry::paletteEntries() as $spec) {
            if (!$spec->slashVisible || isset(self::EXPLICIT_ARM_EXCEPTIONS[$spec->name])) {
                continue;
            }
            ++$walked;

            [$typed] = $this->paletteFixture('/' . $spec->name)->update(new KeyMsg(KeyType::Enter));
            [$palette] = $this->paletteFixture()->runPaletteAction($spec->label());

            self::assertSame(
                $this->rowShapes(array_slice($typed->history, 2)),
                $this->rowShapes(array_slice($palette->history, 2)),
                sprintf('palette row "%s" (/%s) diverged from the typed dispatch', $spec->label(), $spec->name),
            );
            self::assertSame($typed->inFlight, $palette->inFlight, "/{$spec->name} in-flight verdict differs by surface");
            self::assertSame('', $palette->inputBuf, "the palette route for /{$spec->name} must not touch the draft");
            self::assertNull($palette->palette(), "the palette route for /{$spec->name} left the palette open");
        }

        self::assertGreaterThanOrEqual(30, $walked, 'fixture: the promoted set must be a real superset walk, not a vacuous empty loop');
    }

    public function testThePaletteIsTheSupersetAndTheSurvivorsAreExactlyTheJudgedSet(): void
    {
        $survivors = [];
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->paletteAction === null && !$spec->isFileBased()) {
                $survivors[$spec->name] = true;
            }
        }

        self::assertSame(
            array_keys(self::SLASH_ONLY_SURVIVORS),
            array_keys($survivors),
            'VIS-4 promoted every row with a meaningful bare form; this set may only change with a per-row judgement recorded in the spec file',
        );

        // Bidirectional staleness: every survivor reason is still a live row.
        foreach (self::SLASH_ONLY_SURVIVORS as $name => $reason) {
            self::assertNotSame('', $reason, $name);
        }
    }

    /**
     * C-EXT-5.b: the coverage half of the superset. Every slash-visible row
     * this session can reach — registry rows, file commands and MCP prompts
     * alike (`Chat::slashCommandRows()`) — carries a row in the root palette:
     * its {@see PaletteAction}'s label for rows with one, a `/<name>` prefill
     * row (plus the row's argument hint when it has one) for every other.
     * Derived from the live rows, not from the rosters above: an untabulated
     * promotion or a silently vanishing row reddens this, whichever way the
     * palette's own list is assembled.
     */
    public function testTheRootPaletteListsEverySlashVisibleRow(): void
    {
        $chat = (new Chat(palette: PaletteState::root()))->withSize(100, 30);
        $labels = $chat->paletteMatches();
        $walked = 0;
        $prefills = 0;

        foreach ($chat->slashCommandRows() as $spec) {
            if (!$spec->slashVisible) {
                continue;
            }
            ++$walked;

            if ($spec->paletteAction !== null) {
                self::assertContains(
                    $spec->paletteAction->label(),
                    $labels,
                    "the action row for /{$spec->name} left the palette",
                );
                continue;
            }

            ++$prefills;
            $carried = false;
            foreach ($labels as $label) {
                if ($label === '/' . $spec->name || str_starts_with($label, '/' . $spec->name . ' ')) {
                    $carried = true;
                    break;
                }
            }
            self::assertTrue($carried, "no prefill row carries /{$spec->name} in the root palette");
        }

        self::assertGreaterThanOrEqual(30, $walked, 'fixture: the coverage walk must span the real slash surface');
        self::assertGreaterThanOrEqual(
            count(self::SLASH_ONLY_SURVIVORS),
            $prefills,
            'C-EXT-5.b: every survivor row must arrive as a prefill row next to the built-ins',
        );
    }

    public function testEveryParityExceptionStillCarriesItsPaletteRow(): void
    {
        $byName = [];
        foreach (CommandRegistry::paletteEntries() as $spec) {
            $byName[$spec->name] = $spec;
        }

        foreach (self::EXPLICIT_ARM_EXCEPTIONS as $name => $reason) {
            self::assertArrayHasKey($name, $byName, "exception roster names /{$name} ($reason) but the row left the palette");
            self::assertNotSame('', $reason);
        }
    }

    /**
     * The read-only gate rides the generic tail: a palette row opening in a
     * read-only window is refused exactly as the typed spelling is — the
     * collapse of RenameSession's hand-carried `readOnlyRefusal` arm.
     */
    public function testAReadOnlyWindowRefusesTheGenericRowExactlyAsTypedDoes(): void
    {
        $rename = null;
        foreach (CommandRegistry::paletteEntries() as $spec) {
            if ($spec->name === 'rename') {
                $rename = $spec;
            }
        }
        self::assertInstanceOf(CommandSpec::class, $rename);

        [$typed] = $this->paletteFixture('/rename', readOnly: true)->update(new KeyMsg(KeyType::Enter));
        [$palette] = $this->paletteFixture(readOnly: true)->runPaletteAction($rename->label());

        self::assertSame(
            $this->rowShapes(array_slice($typed->history, 2)),
            $this->rowShapes(array_slice($palette->history, 2)),
            'the palette opened a read-only session where the typed spelling was refused',
        );
        self::assertNotSame([], array_slice($palette->history, 2), 'fixture: the read-only refusal actually appends a row');
    }

    /**
     * The collapse is real, not cosmetic: the five rows that used to hand-drive
     * their slash handlers from dedicated arms are generic-tail rows now, and
     * their actions resolve through the registry to those handlers' names.
     */
    public function testTheCollapsedArmsRideTheGenericTail(): void
    {
        $collapsed = [
            'share' => PaletteAction::ShareSession,
            'sessions' => PaletteAction::SwitchSession,
            'agents' => PaletteAction::SwitchAgent,
            'branch' => PaletteAction::BranchSession,
            'rename' => PaletteAction::RenameSession,
        ];

        foreach ($collapsed as $name => $action) {
            $spec = CommandRegistry::forPaletteAction($action);
            self::assertNotNull($spec, $action->name . ' lost its registry row');
            self::assertSame($name, $spec->name);
            self::assertFalse(isset(self::EXPLICIT_ARM_EXCEPTIONS[$name]), "/$name must be a generic-tail row");
        }
    }

    /**
     * w5j-1 (w4b review MINOR): the file-based shadow of a palette row's name.
     * submit() checks the file map BEFORE dispatchCommand() precisely so an
     * `undo.md` replaces `/undo`; a palette tail that still reached the
     * built-in handler ran the very command the operator overrode. Typed and
     * palette must now both run the file.
     */
    public function testAPaletteRowShadowedByACommandFileRunsTheFileAsTypedDoes(): void
    {
        $spec = CommandRegistry::forPaletteAction(PaletteAction::UndoTurn);
        self::assertInstanceOf(CommandSpec::class, $spec);
        self::assertSame('undo', $spec->name);

        $shadow = CommandSpec::new(
            name: 'undo',
            description: 'the operator reclaimed /undo',
            category: 'Custom',
            template: 'shadowed /undo ran',
        );

        $typed = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: '/undo',
            backend: new EchoBackend(),
            customCommands: ['undo' => $shadow],
        ))->withSize(100, 30);
        $palette = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            customCommands: ['undo' => $shadow],
        ))->withSize(100, 30);

        [$typedNext] = $typed->update(new KeyMsg(KeyType::Enter));
        [$paletteNext] = $palette->runPaletteAction($spec->label());
        [$builtInNext] = $this->paletteFixture()->runPaletteAction($spec->label());

        self::assertSame(
            $this->rowShapes(array_slice($typedNext->history, 2)),
            $this->rowShapes(array_slice($paletteNext->history, 2)),
            'the palette ran the built-in /undo while the typed spelling ran its file shadow',
        );
        self::assertSame($typedNext->inFlight, $paletteNext->inFlight);
        self::assertNotSame(
            $this->rowShapes(array_slice($paletteNext->history, 2)),
            $this->rowShapes(array_slice($builtInNext->history, 2)),
            'fixture: the shadow must change the outcome — equal rows on both sides would prove nothing',
        );
    }
}
