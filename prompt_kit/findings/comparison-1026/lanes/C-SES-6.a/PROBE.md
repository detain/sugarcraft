# C-SES-6.a — __COMPILER_HALT_OFFSET__ probe transcript (Round-2, 2026-10-27)

Answers REVIEW-1 [MINOR]: the bin comment's "defined() answers false inside
the halting file — measured" had no transcript. Fixer re-ran every context
live. Environment: PHP 8.3.6 (cli) NTS, Linux, `phar.readonly` Off (mint
possible — the same predicate `SuiteSkipRoster::pharWritingPossible()` gates
the lane's mini arm on). Scratch harness kept at `/tmp/opencode/c-ses-6a-probe/`
(session-scoped; the transcript below is the durable record).

## Probe 1 — source-run contexts (caller.php / haltfile.php / nothalt.php)

```
E) caller context BEFORE including haltfile: bool(false)
A) defined() in halting file, before __HALT_COMPILER(): bool(false)
B) bare token in halting file: int(509)
C) constant() lookup in halting file: int(509)
F) caller context AFTER including haltfile: bool(false)
G) caller sees bare token? Error
D) defined() in plain file (no halt): bool(false)
```

## Probe 2 — real phar stub contexts (mini mint mirroring PharCompatTest's
`new Phar($p, 0, 'candy-code.phar')` + `setStub(probe . "\n__HALT_COMPILER();")`)

```
=== php candy-code.phar (suffix door) ===
stub defined(): bool(false)
stub token:     int(231)
stub constant(): int(231)
=== php candy-code (renamed install, stub-as-script context) ===
stub defined(): bool(false)
stub token:     int(231)
stub constant(): int(231)
```

## Probe 3 — truncated archive (bytes chopped after mint; stub still compiled)

```
=== truncated renamed install ===
truncstub token: int(163)
truncstub defined(): bool(false)
```

## Probe 4 — frame loaded from INSIDE the archive (phar:// entry)

```
inside defined(): bool(false)
inside token: Error
```

## Probe 5 — REAL bin/candy-code bytes as the stub, renamed install

```
=== intact ===
rc=0
STDOUT: REALBIN-INSIDE: /tmp/ses6a-real-3621793/intact
STDERR: ''
=== trunc ===
rc=2
STDOUT:
STDERR: 'candy-code: cannot find composer autoload.php
'
```

## Verdicts

1. The comment's `defined()` claim is **TRUE, now measured**: false in every
   context including inside the halting file itself — the constant is
   compile-time-substituted and never enters the symbol table.
2. But the comment's broader phrasing ("cannot gate this") over-reached: the
   **bare token** (and `constant()`) DO resolve inside a halting file
   (int offset, Probes 1B/1C/2), so a halt-based pre-gate was technically
   expressible. It would nonetheless be the WRONG oracle: Probe 3 shows the
   token answers int(163) for a TRUNCATED archive whose bytes no longer
   load — a halt-gate waves through exactly the broken installs — and Probe 4
   shows it is an Error in every inside-archive frame, so no gate can sit
   anywhere useful. mapPhar answers the runtime question directly ("is this
   exact file a loadable archive?"), and Probe 5 measures the broken-install
   fall-through: truncated renamed install → `PharException` → clean
   exit 2 with the byte-exact `candy-code: cannot find composer autoload.php`
   line the guard test already pins for unsealed scripts (:348/:391).
3. Action taken: bin comment reworded to the measured truth (mapPhar-as-
   oracle justified WITHOUT the bare "cannot gate" assertion); behavior code
   byte-identical. Reviewer's "php.net presents the constant as defined for
   the halting file" refers to the token/echo form — reproduced (Probes 1B/2)
   — not to `defined()`, which measures false throughout.
