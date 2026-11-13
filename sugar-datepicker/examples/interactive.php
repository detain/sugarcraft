<?php

declare(strict_types=1);

/**
 * Runnable interactive path for sugar-datepicker.
 *
 * Unlike basic.php (imperative view() walk) and constraints.php (min/max
 * bounds), this example mounts the TEA Model inside a real sugar-core
 * Program loop and feeds it a fixed script of KeyMsgs — the only place a
 * reader can see how a live keystroke travels through update(), which
 * transitions the EventStore records, and how the host quits itself when
 * the script is exhausted. The renderer is disabled and no cursor/unicode
 * escapes are emitted, so the run is byte-deterministic even piped to a
 * file; the drained EventStore transcript and the final grid are printed
 * once the loop has stopped.
 *
 * Input: the loop reads from one end of a socket pair that is never written
 * to and never closed, NOT from STDIN. Program treats a real stdin EOF as a
 * cooperative QuitMsg (sugar-core-006), so a piped run against a closed
 * stdin would stop the loop after the first couple of scripted steps and
 * print a truncated transcript. A silent open stream keeps the loop alive
 * for the full script; the run ends through the demo's own QuitMsg.
 *
 * Transcript honesty: every unlabelled `label -> type payload` line is a
 * byte for byte EventStore event. The only non-event line is the host's
 * range-mode switch, prefixed `[host]` so no reader mistakes it for a
 * fifth wire event the store does not emit. Steps that record nothing
 * simply print nothing.
 *
 * Determinism: fixed seed date 2026-05-01 (also pinned as the "today"
 * reference so no wall-clock cell can drift) and a fixed EventStore clock.
 *
 * Run: php examples/interactive.php
 */
require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model as CoreModel;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\Subscriptions;
use SugarCraft\DatePicker\DatePicker;
use SugarCraft\DatePicker\EventStore;
use SugarCraft\DatePicker\Model as DatePickerModel;

/**
 * Host-side command: flip the wrapped picker into range mode between the
 * single-mode and range-mode legs of the script. Not a KeyMsg, so the
 * picker's reducer ignores it; the demo model intercepts it and rebinds
 * through Model::withPicker() — the store instance travels with the model,
 * the demo never hand-threads a new DatePickerModel.
 */
final class RangeModeMsg implements Msg
{
}

/**
 * TEA host program for the demo: holds the DatePicker model, walks a
 * fixed message script through the real Program event loop, collects one
 * transcript line per EventStore event (the host's range-mode switch is
 * the only other line, `[host]`-prefixed), and quits the loop itself when
 * the script is done.
 */
final class InteractiveDemo implements CoreModel
{
    /** Fixed clock shared by the EventStore and the transcript display. */
    public const FIXED_CLOCK = 1_777_636_800; // 2026-05-01 12:00:00 UTC

    /** Fixed seed: the picker always opens on May 2026 aimed at the 1st. */
    public const SEED_DATE = '2026-05-01';

    /** @var list<array{label: string, msg: Msg}> */
    private readonly array $script;

    /**
     * @param list<array{label: string, msg: Msg}>|null $script
     * @param list<string> $transcript
     */
    public function __construct(
        private readonly DatePickerModel $dp,
        private readonly EventStore $store,
        private readonly int $step = 0,
        private readonly array $transcript = [],
        ?array $script = null,
    ) {
        $this->script = $script ?? self::buildScript();
    }

    public static function create(): self
    {
        $store = new EventStore(clock: static fn (): int => self::FIXED_CLOCK);
        $picker = DatePicker::new(new DateTimeImmutable(self::SEED_DATE))
            ->withToday(new DateTimeImmutable(self::SEED_DATE));

        return new self(new DatePickerModel($picker, $store), $store);
    }

    /**
     * The script: three single-mode legs (two cursor moves, a selection),
     * Escape clearing it, a range-mode flip, then a range leg that produces
     * range_started and a completed date_selected — all four wire events.
     *
     * @return list<array{label: string, msg: Msg}>
     */
    private static function buildScript(): array
    {
        return [
            ['label' => 'Down',       'msg' => new KeyMsg(KeyType::Down)],
            ['label' => 'Right',      'msg' => new KeyMsg(KeyType::Right)],
            ['label' => 'Enter',      'msg' => new KeyMsg(KeyType::Enter)],
            ['label' => 'Escape',     'msg' => new KeyMsg(KeyType::Escape)],
            ['label' => 'range-mode', 'msg' => new RangeModeMsg()],
            ['label' => 'Enter',      'msg' => new KeyMsg(KeyType::Enter)],
            ['label' => 'Right',      'msg' => new KeyMsg(KeyType::Right)],
            ['label' => 'Enter',      'msg' => new KeyMsg(KeyType::Enter)],
        ];
    }

    /**
     * Kick the loop: the Program defers this Cmd, so the first scripted
     * message arrives through the same dispatch path as a live keystroke.
     */
    public function init(): ?\Closure
    {
        return fn (): Msg => $this->script[0]['msg'];
    }

    /**
     * @return array{0: CoreModel, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        // Startup messages the Program injects (window size, env, colour
        // profile) are not script steps: pass through without advancing.
        if (!$msg instanceof KeyMsg && !$msg instanceof RangeModeMsg) {
            return [$this, null];
        }

        // Identity gate: only the exact message scheduled for the current
        // step advances the demo. The Program keeps reading STDIN even with
        // the renderer disabled, so a stray keystroke typed into a live TTY
        // (or a late echo arriving after the final step) would otherwise be
        // consumed as if it were scripted and desynchronise the transcript.
        if ($this->step >= count($this->script) || $msg !== $this->script[$this->step]['msg']) {
            return [$this, null];
        }

        if ($msg instanceof RangeModeMsg) {
            $switched = $this->dp->withPicker(
                static fn (DatePicker $p): DatePicker => $p->withRangeMode(true),
            );

            // Host-local line, deliberately NOT in wire-event format: the
            // store emits no event for a mode flip (see README Events).
            return [$this->withState($switched, ['[host] range-mode toggled']), $this->nextCmd()];
        }

        [$next, ] = $this->dp->update($msg);
        $events = $this->store->release();

        $lines = [];
        foreach ($events as $event) {
            $payload = $event['payload'] === [] ? '{}' : json_encode($event['payload']);
            $lines[] = sprintf(
                '%-12s -> %-14s %s',
                $this->script[$this->step]['label'],
                $event['type'],
                $payload,
            );
        }

        return [$this->withState($next, $lines), $this->nextCmd()];
    }

    public function view(): string
    {
        $picker = $this->dp->picker();
        $mode = $picker->isRangeMode() ? 'range' : 'single';

        return implode("\n", [
            'sugar-datepicker — interactive demo (fixed script, self-exiting)',
            'seed ' . self::SEED_DATE . ' · clock ' . gmdate('Y-m-d H:i:s', self::FIXED_CLOCK) . ' UTC · mode ' . $mode,
            '',
            $this->dp->view(),
        ]);
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /** @return list<string> */
    public function transcript(): array
    {
        return $this->transcript;
    }

    public function scriptLength(): int
    {
        return count($this->script);
    }

    /**
     * @param list<string> $lines
     */
    private function withState(DatePickerModel $dp, array $lines): self
    {
        return new self(
            $dp,
            $this->store,
            $this->step + 1,
            [...$this->transcript, ...$lines],
            $this->script,
        );
    }

    private function nextCmd(): \Closure
    {
        if ($this->step + 1 < count($this->script)) {
            $next = $this->script[$this->step + 1]['msg'];

            return static fn (): Msg => $next;
        }

        return static fn (): Msg => new QuitMsg();
    }
}

$demo = InteractiveDemo::create();

// Silent, never-EOF input stream (see docblock): one end feeds Program, the
// other end is held open by the demo so the watcher never sees EOF and the
// loop is driven purely by the scripted commands.
$inputPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
assert($inputPair !== false);

$program = new Program(
    $demo,
    new ProgramOptions(withoutRenderer: true, hideCursor: false, unicodeMode: false, input: $inputPair[0]),
);

$final = $program->run();
assert($final instanceof InteractiveDemo);
fclose($inputPair[0]);
fclose($inputPair[1]);

echo "\n";
echo $final->view(), "\n";
echo "\n";
echo 'Demo transcript (store events verbatim; [host] lines labelled — ', count($final->transcript()),
    " lines from ", $final->scriptLength(), " scripted messages):\n";
foreach ($final->transcript() as $line) {
    echo '  ', $line, "\n";
}
