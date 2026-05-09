# Plan: candy-wish PTY upgrade — in-process supervisor mode (default), host-sshd mode (opt-in)

## Goal

Upgrade `candy-wish` from "in-process SugarCraft Program attached
directly to sshd's stdin/stdout" to "PTY supervisor that spawns the
user's shell / TUI as a subprocess via `candy-pty` with full
controlling-terminal semantics." Both modes continue to require a
host sshd as the SSH wire-protocol front-end (per `plans/x-xpty.md`'s
deferred-Option-A scope decision); the upgrade is internal to the
PHP process model and middleware stack.

## Scope

**In**

- New `Transport` abstraction with two implementations:
  - `InProcessTransport` (new default) — allocates a candy-pty,
    spawns the user's cmd as a subprocess with `controllingTerminal:
    true`, pumps bytes between supervisor STDIN/STDOUT and the PTY
    master.
  - `HostSshdTransport` (legacy, opt-in) — current behaviour:
    middleware run directly in the supervisor process, STDIN/STDOUT
    are the sshd-allocated PTY slave.
- New terminal middleware: `Spawn` — produces a cmd from the Session
  and runs it through the active transport. Replaces `BubbleTea` for
  in-process mode (BubbleTea stays valid for HostSshd mode).
- SIGWINCH forwarding: when the SSH client resizes, sshd delivers
  SIGWINCH to the supervisor; the in-process transport propagates
  to `Pty::resize()`.
- `Server::withTransport(Transport $t): self` factory switch.
  Default: `InProcessTransport`. Opt-in via
  `Server::new()->withTransport(new HostSshdTransport())`.

**Out**

- Real SSH wire-protocol implementation in PHP (Option A from the
  scoping conversation; gates on a viable PHP SSH server primitive
  and is a separate multi-week effort).
- Multi-connection-per-process ReactPHP event loop. The supervisor
  remains one PHP process per SSH connection; sshd still forks via
  ForceCommand.
- Authentication / authorization changes. `Auth` middleware semantics
  unchanged.
- Connection multiplexing, port forwarding, X11, agent forwarding —
  all stay sshd's responsibility.

## Naming + placement

- Composer pkg: `sugarcraft/candy-wish` (unchanged)
- Subdir: `candy-wish/` (unchanged)
- Namespace: `SugarCraft\Wish` (unchanged)
- New sub-namespace: `SugarCraft\Wish\Transport` for the two transport
  classes.

## Routes evaluated

| Route | Pros | Cons | Verdict |
|---|---|---|---|
| **Transport abstraction + 2 impls** | Both modes coexist cleanly; tests can mock; future SSH-server transport plugs into the same seam | Mild API churn for existing users (Server::serve becomes transport-driven) | **chosen** |
| Single transport, `if` branches inside Server | No new types | Tangled; new transports (Option A later) become messy | no |
| Replace HostSshd entirely with InProcess | Single code path | Breaks every existing ForceCommand deployment | no — explicitly contradicts user's "still support host sshd via some param" |
| Make HostSshd the default, in-process opt-in | Minimal disruption | User explicitly asked for in-process default | no — contradicts user direction |

## Layout

```
candy-wish/
  src/
    Transport.php                       # interface + factory constants
    Transport/
      InProcessTransport.php            # NEW: candy-pty supervisor
      HostSshdTransport.php             # NEW: extracted from current Server inline code
    Middleware/
      Spawn.php                         # NEW: produces cmd[] from Session, runs via transport
    Server.php                          # MODIFIED: withTransport(), serve() delegates
    Session.php                         # MODIFIED: maybe expose env() for cmd factories
  tests/
    Transport/
      InProcessTransportTest.php        # NEW
      HostSshdTransportTest.php         # NEW (covers existing behaviour at the new seam)
    Middleware/
      SpawnTest.php                     # NEW
    ServerTest.php                      # MODIFIED: withTransport tests
  examples/
    hello-server.php                    # MODIFIED: keep working (HostSshd-friendly)
    spawn-bash.php                      # NEW: in-process bash supervisor
    spawn-program.php                   # NEW: in-process SugarCraft Program via subprocess
```

## composer.json

- `sugarcraft/candy-pty: @dev` added to `require` (in-process mode is
  default; lib won't function without it).
- Path-repo entry for `../candy-pty` in `repositories` (matches the
  monorepo pattern).
- `ext-pcntl` bumped from suggest to soft-required (still a `suggest`
  rather than a hard `require` so HostSshd-mode-only deployments
  without pcntl still install; runtime check throws clear error if
  in-process is used without it).

## Public API

### Picking a transport

```php
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Transport\InProcessTransport;
use SugarCraft\Wish\Transport\HostSshdTransport;

# Default — in-process supervisor (uses candy-pty):
Server::new()
    ->use(...)
    ->serve();

# Same as:
Server::new()
    ->withTransport(new InProcessTransport())
    ->use(...)
    ->serve();

# Legacy — direct STDIN/STDOUT, like pre-PTY-upgrade candy-wish:
Server::new()
    ->withTransport(new HostSshdTransport())
    ->use(...)
    ->serve();
```

### Spawn middleware (in-process default)

```php
use SugarCraft\Wish\Middleware\Spawn;

Server::new()
    ->use(new Logger())
    ->use(new Auth(users: ['alice', 'bob']))
    ->use(new Spawn(fn (Session $s) => [
        'cmd' => ['/bin/bash', '-l'],
        'env' => [
            'TERM' => $s->term,
            'USER' => $s->user,
            'HOME' => "/home/{$s->user}",
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
        ],
    ]))
    ->serve();
```

### BubbleTea (host-sshd legacy)

```php
use SugarCraft\Wish\Middleware\BubbleTea;

Server::new()
    ->withTransport(new HostSshdTransport())
    ->use(new Logger())
    ->use(new BubbleTea(fn (Session $s) => new MyApp($s)))
    ->serve();
```

## Bytes pump (InProcessTransport core)

```
[ssh client] ──ssh──▶ [sshd] ──pty──▶ [supervisor STDIN]
                                           │
                                           ▼
                              [pump loop reads STDIN]
                                           │
                                           ▼
                              [Pty::write to master]
                                           │
                                           ▼
                              [kernel pty layer]
                                           │
                                           ▼
                                    [child stdin]
                                    [child runs]
                                    [child stdout]
                                           │
                                           ▼
                              [kernel pty layer]
                                           │
                                           ▼
                              [Pty::read from master]
                                           │
                                           ▼
                                  [fwrite to STDOUT]
                                           │
                                           ▼
[ssh client] ◀──ssh── [sshd] ◀──pty── [supervisor STDOUT]
```

Pump uses non-blocking I/O on both ends + `stream_select` with a
short timeout to multiplex. Loop exits when the child exits OR
either of the supervisor stdio streams hits EOF (client
disconnected). Final `Pty::read` flushes any tail bytes the kernel
buffered on the master before close.

## Resize forwarding

Supervisor process inherits sshd's PTY (its stdin/stdout). When
the SSH client resizes:
1. sshd updates its PTY winsize via TIOCSWINSZ.
2. sshd sends SIGWINCH to the foreground process group (us).
3. We catch it via `candy-pty`'s `SignalForwarder::attachSigwinch`
   with a size provider that reads sshd's PTY size via
   `candy-core/Util/Tty::size()` (TIOCGWINSZ on STDIN).
4. The forwarder calls `$pty->resize($cols, $rows)` on the inner
   candy-pty, propagating the resize to the child.

## Implementation slices

### PR1 — Transport abstraction + Server refactor (~1 day)

- `src/Transport.php` interface: `run(Session, list<Middleware>): void`.
- `src/Transport/HostSshdTransport.php` — extracts the current
  `Server::dispatch()` recursion (in-process middleware walk) into
  the transport.
- `src/Transport/InProcessTransport.php` — stub for now; throws
  `\LogicException` if `run()` called. Real implementation lands in
  PR2.
- `Server::withTransport(Transport $t): self`. Default in
  `Server::new()` is `InProcessTransport`.
- `Server::serve()` delegates to `$transport->run($session, $stack)`.
- Existing `ServerTest` updated for the new seam; `HostSshdTransportTest`
  covers the extracted middleware-walk path.

### PR2 — InProcessTransport: PTY + bytes pump + child lifecycle (~2 days)

- `InProcessTransport::run()` walks the middleware stack like
  HostSshd does, but the terminal middleware (Spawn) calls back into
  the transport's `runChild(Session, cmd, env)` to spawn + pump.
- Allocates a `Pty` via `Pty::open()` at session start using
  `Session->cols/rows`.
- Child spawn via `pty->spawn($cmd, $env, controllingTerminal: true)`.
- Pump loop:
  - Set STDIN, STDOUT, master fd all to non-blocking.
  - Select on the three streams + the child's exit status (poll).
  - Forward bytes both directions.
  - Exit on child exit, STDIN EOF, or STDOUT broken pipe.
- Tests use `stream_socket_pair()` to simulate STDIN/STDOUT and
  spawn `/bin/cat` as the child — write "ping\n" to fake STDIN,
  read it back from fake STDOUT.

### PR3 — Spawn middleware (~half day)

- `src/Middleware/Spawn.php` — terminal middleware. Constructor
  takes a factory: `callable(Session): array{cmd: list<string>, env?:
  array<string,string>}`. `handle()` invokes the factory, then asks
  the active transport (passed via `$next`'s context or a transport-
  aware base) to run the resulting cmd.
- Refactor: middleware chain may need a small extension to expose
  the active transport — likely a `TransportAware` interface or a
  Session annotation. Keep the change small.
- Tests: factory called with the Session, returned cmd is what the
  transport sees.

### PR4 — SIGWINCH forwarding (~half day)

- `InProcessTransport` calls
  `SignalForwarder::attachSigwinch($pty, fn () => Tty::size())` after
  spawn, where `Tty::size()` reads STDIN's winsize.
- Test: spawn a child that loops printing `tput cols`. Send SIGWINCH
  to the supervisor with the host PTY winsize updated; assert the
  child's reported cols changes mid-loop.

### PR5 — BubbleTea compatibility + transport-aware (~half day)

- `BubbleTea::handle()` checks the active transport and:
  - HostSshd → runs the Program inline (current behaviour).
  - InProcess → throws a clear PtyException pointing at Spawn middleware.
- Document the migration in README.

### PR6 — Examples + docs + cross-cuts (~half day)

- `examples/spawn-bash.php` — shows in-process bash supervisor.
- `examples/spawn-program.php` — shows running a SugarCraft Program
  as a subprocess via a tiny wrapper script.
- `examples/hello-server.php` — keep working in HostSshd mode (add
  explicit `withTransport(new HostSshdTransport())`).
- README updates: architecture diagram for both modes, transport
  selection guide, Spawn factory cookbook.
- MATCHUPS / UPSTREAM_OPPORTUNITIES rows update to mention the
  candy-pty integration.
- candy-wish/CALIBER_LEARNINGS captures byte-pump caveats.

## Test strategy

- Unit tests use `stream_socket_pair()` to simulate STDIN/STDOUT
  pairs — no real SSH connection needed.
- Spawn `/bin/cat`, `/bin/echo`, `/bin/sh -c '...'` as child cmds —
  small, deterministic.
- Test the pump loop's exit conditions explicitly: child exit while
  pumping; STDIN EOF (client disconnect); STDOUT EPIPE.
- Resize tests: use the `posix_kill(getpid(), SIGWINCH)` self-deliver
  pattern (see candy-pty's CALIBER_LEARNINGS).
- Skip every test on Windows / no-FFI / no-pcntl hosts via the same
  `requirePtySyscalls()` helper as candy-pty.
- candy-core regression sweep on each PR.

## Caveats / open questions

1. **STDIN / STDOUT are inherited**, not opened by candy-wish. We
   need to set them non-blocking — but stdin is shared with the
   parent shell when running outside sshd (e.g. in tests). Pattern:
   only flip blocking if we own the stream (test injection vs real
   STDIN). Tests pass injected pair instances; production uses
   `STDIN` / `STDOUT` constants.
2. **STDOUT EPIPE on client disconnect** — fwrite returns false /
   short write. Treat as session-ended; tear down child + close pty.
   Don't bubble as exception.
3. **Pump loop CPU**: `stream_select` with a short timeout (e.g.
   50 ms) is fine for typing-rate I/O. Don't busy-loop.
4. **BubbleTea in-process compatibility**: existing users running
   BubbleTea via the implicit-default-transport will hit a clear
   error after this lands. Migration path: either pass `withTransport
   (new HostSshdTransport())` explicitly, or migrate to Spawn with a
   wrapper script. Document both.
5. **PTY size at spawn time**: Session reads cols/rows from
   `COLUMNS` / `LINES` env vars at start, but those may be unset in
   ForceCommand contexts. Fall back to TIOCGWINSZ on STDIN via
   candy-core's `Util/Tty::size()` if Session reports 0×0.
6. **Per-process resource cost**: in-process transport adds a
   subprocess (the spawned child) and a PTY pair per SSH connection.
   At 100 concurrent connections that's 100 extra processes + 100
   PTY pairs — within typical sshd MaxSessions / MaxStartups limits
   but worth a deployment note.
7. **Child env propagation**: the Spawn factory must explicitly
   include TERM / HOME / PATH / USER. We don't auto-inherit the
   supervisor's env because production deployments often want a
   sanitised env for the child shell. Document the common pattern.
8. **Existing tests for `Server` use synthesised Sessions** (e.g.
   `showcase.php`'s pattern). Keep that working — the new transport
   abstraction is opt-in for the test path.

## Effort

| Slice | Effort |
|---|---|
| PR1 Transport abstraction + Server refactor | 1 day |
| PR2 InProcessTransport core (PTY + pump) | 2 days |
| PR3 Spawn middleware | half day |
| PR4 SIGWINCH forwarding | half day |
| PR5 BubbleTea compatibility | half day |
| PR6 Examples + docs + cross-cuts | half day |
| **Total** | **~4-5 days** |

## Dependencies

- `candy-pty` (Linux + macOS PTY primitive — PR1-PR7 already merged).
- `candy-core/Util/Tty` for terminal-size queries.
- Optional: `ext-pcntl` for SignalForwarder; resize forwarding
  degrades to "size at spawn time only" without it.

## Tracking

- `MATCHUPS.md` — update CandyWish row description to mention
  in-process transport with candy-pty backing.
- `UPSTREAM_OPPORTUNITIES.md` — note the candy-pty integration is
  shipped (charmbracelet/wish row).
- `candy-wish/CALIBER_LEARNINGS.md` — capture pump-loop / EPIPE /
  inheritance gotchas as they surface during slices.
- Existing `docs/lib/candy-wish.html` detail page updated for the
  new architecture diagram + transport guide.

## Non-goals (explicitly deferred)

- Full SSH server in PHP (Option A from the scoping conversation) —
  separate plan, gated on a viable PHP SSH primitive (libssh FFI
  binding or similar).
- Multi-connection-per-process event loop — not in scope; one PHP
  process per connection is fine for the supervisor model.
- Reload-on-fly config — the existing per-connection process model
  re-reads config on each connection automatically.
