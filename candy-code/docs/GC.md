# Session store maintenance: `gc`

`candy-code gc` answers one question about the session store, "where is the disk
going?", and then gives you the tools to reclaim it. Its verbs run from a
read-only look to a destructive purge, and the order below is the order to use
them in:

| Verb | Writes? | What it does |
|---|---|---|
| `gc status` | no | Census of the store: file and free-page bytes, per-table bytes, session counts and ages, the storage directories beside the database, and the retention setting |
| `gc sessions` | no | One row per session with its real bytes: database fold, transcript journals and shadow directories |
| `gc analyze` | no | The top sessions by real bytes, a labelled growth rate, per-table and per-storage bars, root versus sub-agent totals, and the orphan census |
| `gc purge` | only with `--force` | Deletes or strips the sessions your filters select, and sweeps store-wide orphans. `--dry-run` plans and writes nothing |
| `gc vacuum` | only with `--force` | Reports free pages; `--force` rewrites the database file to return them |

Nothing in `status`, `sessions`, `analyze` or `vacuum` without `--force` prunes a
session. Those verbs open the store read-only, except that `vacuum --force` is the
one rewrite they can perform. Retention pruning runs at launch, not in `gc`: the
`sessionRetentionDays` setting in [SETTINGS.md](SETTINGS.md) removes unnamed,
unpinned sessions that have gone untouched for that long. `gc purge` never runs
that sweep (the store is opened with pruning off), so a purge removes only what
its own flags name.

The store is `session.db` in your configuration directory, `~/.candy-code/`
(`src/Cli/Subcommands.php`, `gcStorePath()`).

## Start with a read-only look

```sh
candy-code gc status              # where the bytes are, and how many are reclaimable
candy-code gc sessions --sort size --limit 20
candy-code gc analyze --top 10    # the same ranking, with growth and orphan counts
```

`gc sessions` takes `--sort size|age|name` (default `size`). `age` lists the
oldest first. `--limit N` keeps the first N rows of the sorted list. `--archived`
includes archived sessions, which are hidden by default. `--children` restricts
the list to sub-agent sessions. `analyze --top N` sets how many sessions it ranks
(default 10).

`status`, `sessions`, `analyze` and `purge` take `--format text|json`. Every verb
also honors the global `--output-format json`, which prints one machine-readable
document. Each verb accepts only the flags it lists, and a flag it does not speak
is a usage error (exit 2), not a silent no-op.

## Purge: plan first, then act

`gc purge` selects work in three families. A purge needs at least one of them.

- **Filters** select whole sessions: `--older-than <N>m|h|d|w|mo`,
  `--larger-than <N>k|m|g|kb|mb|gb`, `--keep-latest <N>`, `--session <id>[,<id>…]`
  (repeatable), `--subagents`, and `--archived`. `--keep-latest N` protects the N
  newest of the matched set, and those sessions are left out of the plan entirely.
- **Strips** hollow rows out of the matched sessions and keep the session row:
  `--strip-events`, `--strip-usage`, `--strip-checkpoints`, `--strip-journal`,
  `--strip-fts`.
- **Cleans** remove store-wide orphans, independent of any filter:
  `--clean-orphan-journals`, `--clean-orphan-events`, `--clean-orphan-usage`,
  `--clean-locks`, `--clean-shares`, `--clean-imports`, `--clean-shadows`,
  `--clean-expired-tombstones`. These target exactly what the orphan census
  reports, so `gc analyze` shows you the set before you clean it.

A filter with no strip deletes the matched sessions. A filter with a strip keeps
the session rows and strips their contents instead.

Always plan first:

```sh
candy-code gc purge --older-than 90d --dry-run
candy-code gc purge --older-than 90d --strip-events --dry-run
candy-code gc purge --clean-orphan-journals --clean-locks --dry-run
```

`--dry-run` (or `-n`) prints the plan and opens no read-write connection. Read
the plan, then repeat the same command with `--force` (or `-f`) to execute it:

```sh
candy-code gc purge --older-than 90d --force
```

Without `--force`, a purge run from a terminal prints the plan and asks
`Execute the plan above? [y/N]`. Anything other than `y` or `yes` declines, and
the store is untouched. A run whose standard input is not a terminal prints the
plan and says `not a terminal: refusing to purge unattended`. It then exits 0,
because that is a decline, and nothing was removed. **A script that exits 0 has
not purged anything unless it passed `--force`.**

`--format json` (or the global `--output-format json`) requires `--dry-run` or
`--force`. A machine caller has nobody to answer the prompt, so without one of the
two the call fails with exit 2.

### What a purge will not touch

- **Named and pinned sessions are exempt from deletes** by default. A session is
  exempt when it is pinned, or when it has a real name and a title that was not
  auto-generated. This is the inverse of the retention rule, so retention and
  purge agree on which sessions are protected. Add `--include-named` to delete
  them too. Strips act on exempt sessions as well, because the exemption applies
  only to deletes.
- **Sessions with a live writer are refused** unless you pass `--force`. A
  session whose lock is held by a running candy-code process is skipped by deletes
  and strips, and the plan names it. `--force` includes those sessions and prints a
  disclosure line for them. Stopping the other process first is the safer order.
- **Vacuum is not part of purge.** After deletes, the run prints a reclaim hint.
  Run `gc vacuum` to return the space to the disk.
- **A bare `gc purge` is a usage error (exit 2).** It needs a filter, a strip, or a
  clean.

Deletes route through the session store's own delete path, so the tombstone,
share revocation and full-text-index cleanup run as they do for `session delete`.
Journal files are unlinked after their database rows commit. Those file removals
are best-effort, and missing files are ignored.

## Vacuum: reclaim free pages

```sh
candy-code gc vacuum              # report only: free bytes, auto_vacuum mode, estimated size after a rewrite
candy-code gc vacuum --check      # the same report, spelled as "only look"
candy-code gc vacuum --force      # rewrite the database file now
candy-code gc vacuum --incremental --force
```

Without `--force`, nothing is rewritten, whatever flags came with it. The report
is the answer, and the exit code is 0. The size after a rewrite is an estimate,
and the output labels it as one.

`--force` rewrites the file with `VACUUM`. A rewrite needs about the file's own size
in free disk space, and the command prints that advisory before it starts. It is
never refused on the estimate alone. `--incremental` switches the database to
`auto_vacuum = INCREMENTAL`, which takes effect only after a full rewrite. Once the
database is already incremental, `--incremental --force` drains free pages with
`PRAGMA incremental_vacuum`. `--check` with `--force` is a usage error.

If a running session holds the store, vacuum says so and names the number of
holders. Wait for that session to finish, then run it again.

## Exit codes

| Exit | Meaning |
|---|---|
| 0 | The verb ran. This includes a purge that was declined or refused for want of a terminal, and a vacuum report |
| 1 | The store could not be opened (missing, corrupt or unreadable), or a `--session` target did not resolve |
| 2 | Usage error: unknown action, a flag the action does not speak, a malformed value, a bare purge, `--check` with `--force`, or a JSON purge with neither `--dry-run` nor `--force` |

## Related

- [SETTINGS.md](SETTINGS.md): `sessionRetentionDays`, the launch-time retention sweep.
- [COMMANDS.md](COMMANDS.md): `session list|show|delete|pin|archive`, the per-session verbs.
- [ENVIRONMENT.md](ENVIRONMENT.md): where the configuration directory comes from.
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): what to do when the store will not open.
