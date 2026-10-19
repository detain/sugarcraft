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
 */
final class PaletteSupersetDispatchTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * Palette rows that keep an explicit arm, name => why the generic tail
     * cannot carry them.
     */
    private const EXPLICIT_ARM_EXCEPTIONS = [
        'new' => 'the palette row starts a new session in place (handlePaletteNewSession), not the typed /new folder flow',
        'model' => 'second-level provider list, not a dispatch',
        'theme' => 'second-level theme list, not a dispatch',
        'mcp' => 'drives the panel with fully-formed "mcp auth list" text',
        'settings' => 'opens the shell settings view, which a typed /settings mid-turn deliberately never does',
        'layout' => 'drives the sub-command "/layout reset", not bare "/layout"',
        'exit' => 'returns Cmd::quit() directly',
    ];

    /**
     * Rows VIS-4 judged to stay slash-only, name => the measured reason. The
     * roster test below is bidirectional: an unjudged promotion reddens it, and
     * so does a survivor quietly gaining a palette row elsewhere.
     */
    private const SLASH_ONLY_SURVIVORS = [
        'pane' => 'bare /pane prints usage; the palette actions are the dock-left/dock-right rows',
        'bg' => 'bare /bg answers usage rather than backgrounding something unintended',
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
            exec('rm -rf ' . escapeshellarg($this->paletteSandbox));
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
}
