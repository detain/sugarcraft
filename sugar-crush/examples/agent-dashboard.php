<?php

declare(strict_types=1);

/**
 * The full-pane agent dashboard, with something in it.
 *
 * {@see AgentDashboardPane} reads its rows off whatever the hosted `Chat`
 * holds: registered sub-agents from an {@see AgentManager}, then daemonised
 * background sessions from a {@see BackgroundSupervisor}, in stable slot
 * order and grouped by status. This seeds both collaborators with fixed data
 * rather than spawning real workers — a demo that forked processes would
 * record different numbers every run, and the pane is the thing being
 * demonstrated, not the scheduler behind it.
 *
 * Keys once it is up: Up/Down move the selection, Space peeks at the selected
 * entry's output tail, Alt+1..9 jump straight to a slot, Escape leaves the
 * peek overlay. The tail is the seeded `output` string below, fixed once at
 * construction — nothing about it streams or moves while the demo runs, which
 * is exactly what a recorded tape needs.
 *
 * @see .vhs/agents.tape
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tui\Pane;

/**
 * A start instant that slides with the clock, so an UNFINISHED worker's
 * elapsed readout is a constant rather than a counter.
 *
 * Both of this pane's elapsed sources measure against `now` whenever the
 * worker has not finished: {@see AgentManager::elapsedSeconds()} pins the end
 * of an agent's span to `time()` while any of its sub-agents is still running,
 * and {@see BackgroundSession::elapsedSeconds()} is `time() - createdAt`
 * unconditionally — a background session's clock keeps counting even after it
 * has stopped. Seeding either from a fixed birth instant (`-2 minutes`) is
 * therefore NOT deterministic: the seconds field advances once a second, so a
 * ~9s tape records a different reading in almost every frame and a different
 * GIF on every take. `.github/workflows/vhs.yml` renders and COMMITS the GIFs,
 * so that alone would produce a content diff on every re-render, from nothing
 * but a counter.
 *
 * Pinning the AGE instead of the birth instant makes `now - start` a constant
 * without pretending the worker has finished, which is the state the demo is
 * there to show.
 *
 * Every reader is rebased, not just the one the pane happens to use.
 * `getTimestamp()` is the only method the two elapsed calculations call today,
 * but a subclass whose `getTimestamp()` disagreed with its own `format()` would
 * be a trap for the next person: `BackgroundSession::toArray()` serialises
 * `createdAt->format('c')`, so a demo tweak that dumped a session would quietly
 * record an instant the pane never showed. So `format()` and `diff()` are
 * rebased onto the same sliding instant, and the parent is constructed AT that
 * instant so the object starts out internally consistent too.
 *
 * What this class canNOT rebase, and what that costs:
 *
 *   * The PARENT's internal instant is still the one frozen at construction.
 *     Anything that reads it without going through a method — `var_dump()`,
 *     `print_r()`, `json_encode()` on the object itself, and the `<` / `>`
 *     comparison operators, which PHP evaluates on a DateTime's internal state
 *     with no interception point — sees the birth instant, not the sliding
 *     one. The gap is however long the process has been alive. Nothing on this
 *     demo's path does any of those — the serialisers that touch these fields
 *     (`BackgroundSession::toArray()`, `SubAgent::toArray()`) go through
 *     `format('c')`, which IS rebased.
 *   * `modify()` / `add()` / `sub()` and the `set*()` family are NOT safe to
 *     inherit, despite the `static` return type suggesting a subclass cannot
 *     produce them: PHP clones rather than constructs for these, so they do
 *     return a `FixedAge` — one carrying the original `$ageSeconds`, because
 *     no constructor ran to recompute it. The overridden `getTimestamp()` then
 *     answers that original age and the arithmetic vanishes without a trace:
 *     `(new FixedAge(132))->modify('+1 hour')` reads exactly the same instant
 *     as the unmodified object. A pinned AGE has no fixed instant for them to
 *     move relative to, so there is no right answer to return either — they
 *     throw, per CONTRIBUTING.md's "no silent failures" rule, rather than
 *     quietly discarding what the caller asked for.
 */
final class FixedAge extends DateTimeImmutable
{
    public function __construct(private readonly int $ageSeconds)
    {
        parent::__construct('@' . (time() - $ageSeconds));
    }

    public function getTimestamp(): int
    {
        return time() - $this->ageSeconds;
    }

    public function format(string $format): string
    {
        return $this->slid()->format($format);
    }

    public function diff($targetObject, bool $absolute = false): DateInterval
    {
        return $this->slid()->diff($targetObject, $absolute);
    }

    public function modify(string $modifier): static
    {
        throw self::pinned('modify');
    }

    public function add(DateInterval $interval): static
    {
        throw self::pinned('add');
    }

    public function sub(DateInterval $interval): static
    {
        throw self::pinned('sub');
    }

    public function setTimestamp(int $timestamp): static
    {
        throw self::pinned('setTimestamp');
    }

    public function setDate(int $year, int $month, int $day): static
    {
        throw self::pinned('setDate');
    }

    public function setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0): static
    {
        throw self::pinned('setTime');
    }

    public function setISODate(int $year, int $week, int $dayOfWeek = 1): static
    {
        throw self::pinned('setISODate');
    }

    private static function pinned(string $method): LogicException
    {
        return new LogicException(sprintf(
            'FixedAge::%s(): a FixedAge pins an AGE, not an instant, so there is no '
            . 'fixed point for %s() to move relative to. Seed a plain '
            . 'DateTimeImmutable if the demo needs real date arithmetic.',
            $method,
            $method,
        ));
    }

    /**
     * The birth instant as it stands right now — always exactly $ageSeconds ago.
     *
     * Built from `@<ts>` and then carried into this object's OWN timezone, so
     * `format()` agrees with `getTimezone()`. Building it with
     * `(new DateTimeImmutable())->setTimestamp(...)` instead adopted the
     * default timezone, which made `format('T')` answer (say) `EDT` on an
     * object whose `getTimezone()` was `+00:00` — two different readings of
     * one instant, from one object, depending on which accessor you asked.
     */
    private function slid(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->getTimestamp()))
            ->setTimezone($this->getTimezone());
    }
}

$provider = new EchoProvider();

$agent = static fn (string $name, string $description): Agent => new Agent(
    name: $name,
    description: $description,
    prompt: "You are the {$name} agent.",
    model: 'echo',
    provider: 'echo',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true,
);

$manager = new AgentManager($provider, new SkillRegistry());
$manager->register($agent('reviewer', 'Reviews a diff for correctness bugs'));
$manager->register($agent('porter', 'Ports one upstream Go file to PHP'));

// The pane's agent rows come from AgentManager::active() — the REGISTERED
// agents — with the sub-agent below supplying that row's telemetry, so both
// render under "Working" regardless of the sub-agent's own status field.
//
// Fixed counters, and every clock pinned so no readout on this pane moves
// between frames or between takes. Two shapes, because the two calculations
// differ: a FINISHED worker is measured start-to-finish, so absolute stamps
// give a byte-identical duration; a STILL-RUNNING one is measured against
// `now`, so only a start that slides with the clock ({@see FixedAge}) holds it
// still. A fixed relative offset does NOT — see FixedAge's docblock.
$reviewer = $manager->createSubAgent('reviewer', 'review the TerminalBackground change');
$reviewer->status = SubAgent::STATUS_RUNNING;
$reviewer->startedAt = new FixedAge(132); // reads "2m 12s" in every frame
$reviewer->tokensUsed = 8_412;
$reviewer->costUsd = 0.11;

$porter = $manager->createSubAgent('porter', 'port charmbracelet/lipgloss/whitespace.go');
$porter->status = SubAgent::STATUS_COMPLETE;
// Finished, so start-to-finish is already a constant and plain absolute
// stamps are enough — reads "2m 27s".
$porter->startedAt = new DateTimeImmutable('@1000');
$porter->completedAt = new DateTimeImmutable('@1147');
$porter->tokensUsed = 31_005;
$porter->costUsd = 0.48;

/**
 * Stop a seeded session's heartbeat fuse from going off mid-demo.
 *
 * {@see FixedAge} above pins the elapsed READOUTS. It does not pin the STATUS
 * field, and that one moves on its own: both sessions below are still
 * `isActive()` — {@see BackgroundSession::isActive()} only excludes
 * Completed/Failed/Stopped, and TimedOut is none of those — so
 * {@see Chat::subscriptions()} installs its 2-second background-poll tick and
 * {@see BackgroundSupervisor::tick()} runs for real, marking a session Stalled
 * once `time() - lastHeartbeat > BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS`
 * (15). `lastHeartbeat` is stamped by BackgroundSession's constructor — i.e.
 * right here — and nothing in the public API holds it there: both classes are
 * `final`, and `recordHeartbeat()` only restamps it to `time()`, which is
 * where it already is. So ~16s after startup (15s plus up to one 2s tick) both
 * rows used to relabel to `waiting` and `docs-sync` migrated out of the
 * Completed group.
 *
 * That is worth defusing rather than documenting. It is not only a
 * tape-length constraint: anyone running this file by hand and sitting on the
 * dashboard for twenty seconds watched both seeded workers silently relabel,
 * which reads as a bug in the pane rather than as an artifact of seeding
 * sessions that have no worker to heartbeat. `final` blocks subclassing, not
 * reflection, and one property write reaches the field.
 *
 * A far-future stamp rather than an arbitrary deadline, because a pinned
 * "never" cannot run out during a long manual run. Nothing outside
 * BackgroundSession reads `lastHeartbeat` or `secondsSinceLastHeartbeat()`,
 * and BackgroundSession's own clone path copies the field, so the value
 * survives the status changes `tick()` makes.
 *
 * It is not invisible, though, and by the same standard {@see FixedAge}'s
 * docblock applies to the parent DateTime it cannot rebase:
 * `BackgroundSession::toArray()` serialises the field raw, so a dump of a
 * defused session reads `'last_heartbeat' => 9223372036854775807` rather than
 * a plausible stamp. Dormant today — that method has no caller anywhere in
 * this lib — and left dormant rather than worked around, because the honest
 * alternative (a stamp far enough out to be safe but small enough to look
 * real) is the arbitrary deadline this pin exists to avoid. A demo that grows
 * a reason to serialise a session should stop reflecting and seed a session
 * with a real worker instead.
 *
 * Checked both ways — seeded exactly like this and ticked after a 17-second
 * sleep, the undefused sessions come back Stalled/Stalled and the defused ones
 * come back Running/TimedOut.
 *
 * Reaching into another class's private state is a liberty a DEMO may take to
 * hold a clock still; library code must not.
 */
$defuseHeartbeat = static function (BackgroundSession $session): BackgroundSession {
    (new ReflectionProperty(BackgroundSession::class, 'lastHeartbeat'))
        ->setValue($session, \PHP_INT_MAX);

    return $session;
};

$supervisor = new BackgroundSupervisor();
$supervisor->addSession($defuseHeartbeat(new BackgroundSession(
    id: 'bg1',
    name: 'suite-runner',
    agent: $agent('suite-runner', 'Runs the affected libs\' phpunit suites'),
    task: 'run phpunit for every lib touched by the working tree',
    workingDirectory: getcwd() ?: '/tmp',
    // Sliding, not absolute: BackgroundSession's elapsed is `time() -
    // createdAt` with no completion pinning at all, so this is the only seed
    // that renders the same "12m 22s" in every frame.
    createdAt: new FixedAge(742),
    status: BackgroundSessionStatus::Running,
    output: implode("\n", [
        'candy-core .................................. OK (1204 tests)',
        'candy-sprinkles ............................. OK (388 tests)',
        'sugar-crush ................................. running',
    ]),
)));
// Timed-out rather than completed on purpose: BackgroundSupervisor's active
// set drops Completed/Failed/Stopped sessions (see AgentDashboardPane::
// entries()'s own disclosure), so a Completed one would simply not be on the
// dashboard to record. A timed-out session stays active and is the one worker
// that reaches the "Completed" group, which is what gives this demo a second
// group to show at all.
//
// The row does NOT say "timed out": AgentDashboardPane::sessionStatus() maps
// TimedOut → `failed` (it shares one status vocabulary with AgentStatusBar, so
// both widgets colour the same state the same way), and the recorded frame
// reads `[4] ● docs-sync [failed] php tools/gen-docs.php 5m 18s …` under a
// `Completed (1)` heading. That pairing is the honest rendering of a worker
// that stopped without succeeding, not a seeding mistake.
$supervisor->addSession($defuseHeartbeat(new BackgroundSession(
    id: 'bg2',
    name: 'docs-sync',
    agent: $agent('docs-sync', 'Regenerates the docs/lib pages'),
    task: 'php tools/gen-docs.php',
    workingDirectory: getcwd() ?: '/tmp',
    createdAt: new FixedAge(318), // reads "5m 18s" in every frame
    status: BackgroundSessionStatus::TimedOut,
    output: "wrote docs/lib/sugar-crush.html\nwrote docs/lib/candy-shine.html\n(no output for 300s)",
)));

$app = App::new($provider, 'echo')
    ->withChat(new Chat(
        themeName: 'adaptive',
        agentManager: $manager,
        backgroundSupervisor: $supervisor,
    ))
    ->withPane(Pane::Agents)
    ->withSelectedAgentIndex(0);

(new Program($app, Chat::programOptions()))->run();
