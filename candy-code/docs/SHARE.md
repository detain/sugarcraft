# Sharing a session: `/share` and `/export`

`/share` and `/export` both turn the current session into a file, but they
differ on two points that matter before you send anything anywhere: whether
secrets are redacted, and whether a page is published. Read the table first.

| | `/share` | `/export` |
|---|---|---|
| Writes | a file on this machine | a file on this machine |
| Secret redaction | applied (the same redactor the memory audit uses) | not applied: the file is the raw transcript |
| Uploads anything | never | never |
| Publishes a page | only with `/share serve`, and only on your own `candy-code serve` | never |
| Formats | `md`, `html`, `json`, `text` | `md`, `html`, `json`, `jsonl`, `text` |

Nothing in either command leaves the machine on its own. The only way a
transcript is reachable from another machine is `/share serve`, and that page
is served by a `candy-code serve` process you started.

## `/share`: a local file

```text
/share                  # Markdown, to ~/.candy-code/exports/<session-id>-<UTC timestamp>.md
/share html             # the same, as HTML
/share json notes.json  # JSON, to notes.json inside the project
```

The format is the first word, `md`, `html`, `json` or `text` (`txt` and `plain`
also work for text). If there is no format word, the path's extension decides,
and otherwise the format is Markdown.

- With no path, the file goes to `~/.candy-code/exports/`. The directory is
  created `0700` and the file `0600`.
- A path is resolved against the project root and must stay inside it. A path
  that leaves the project is refused. An existing directory receives the
  default file name.
- The reply names the file it wrote.

Before the transcript is written, the same secret redactor the memory audit
trail uses runs over it. That is the only difference from `/export`, and it is
why `/share` is the one to use when the file might be shared.

## `/export`: the raw transcript

```text
/export                 # the transcript, unredacted, to the exports directory
/export jsonl --path out.jsonl
```

`/export` reads the same rows as `/share` and writes them in the same way, but
without the redactor. Its path rules differ: a plain path is jailed to the project
root or the `~/.candy-code/` shelf, and its `--path` flag writes anywhere without
the project jail. Its reply says so: the file is not secret-redacted, so
treat it like the transcript itself. `text` output is also cleaned of invisible
formatting and terminal control noise, which is display hygiene and does not
remove secrets. `md`, `html` and `json` are written byte for byte, because
other tools parse them.

## `/share serve`: a self-hosted page

```text
/share serve            # a snapshot of the transcript now, valid for 7 days
/share serve 30         # a snapshot, valid for 30 days
/share serve --live 14  # a page that tracks this session, valid for 14 days
```

`/share serve` renders the transcript to HTML at that moment and stores the
rendered page in the `shares` table of the session database. The reply gives
the `/s/<token>` path on your own server.

- The token is 128 random bits. It is the only credential the page needs. Listings
  show a prefix only, but `/share serve` prints the whole token once, so it lands
  in the transcript. Revoke with the prefix from `/share list`, and do not paste
  the transcript anywhere the link must stay private.
- The default expiry is 7 days. The maximum is 365.
- The page is answered by `candy-code serve`. The server has to be running and
  reachable from the reader. See [SERVER.md](SERVER.md) for the bind address,
  the sign-in model and remote access. Share pages need no login cookie, so
  whoever holds the link can read the page until it expires or you revoke it.
- Without `--live`, the page is a snapshot. Later edits to the session do not
  change it, so publishing the new state is a second `/share serve`.
- With `--live`, every request serves the session's current transcript, through
  the same redaction. The render is memoized per session revision, so it
  re-renders only when the session changes. The live render keeps the newest
  500 rows and at most 512 KiB, and a truncation notice marks a cut. A live page
  of a deleted session answers the same `404` as an expired one.

Responses carry `Cache-Control: no-store`, so intermediaries should not keep a
copy.

### Listing and revoking

```text
/share list             # every live page, across all sessions
/share revoke           # revokes the single live page of this session, if there is exactly one
/share revoke 3fa9c2d1e4b7   # by the 12-character prefix /share list shows
```

`/share list` shows each page by a 12-character prefix, never the whole token,
so a transcript that contains the listing does not leak a working link.
`revoke` accepts the whole token or the prefix. The page answers an unknown,
expired and revoked token with the same `404`, so it cannot be probed for valid
tokens. The `revoke` command gives its own reply for an unknown token; that reply
is text, not a `404`.
A bare `revoke` revokes only when there is exactly one live page and it belongs
to the current session. With no live page it prints `There is no live share page
to revoke.` Otherwise it prints the list and changes nothing.

## What is sent, and to whom

| Command | Sent off the machine | Notes |
|---|---|---|
| `/share` | nothing | The file is written locally |
| `/export` | nothing | Written locally, unredacted |
| `/share serve` | nothing by candy-code | The page is served from your own `candy-code serve`, to whoever you give the link |

`CANDY_CODE_SHARE_UPLOAD_URL` is retired. candy-code never uploads a
transcript. If the variable is still set, the `/share` reply still writes the
local export, prints a notice that uploads are retired, and names `/share serve`
as the replacement. See the variable's row in
[ENVIRONMENT.md](ENVIRONMENT.md).

## Where the files are

| What | Path | Mode |
|---|---|---|
| Default exports | `~/.candy-code/exports/` (directory `0700`) | files `0600` |
| Published pages | the `shares` table in the session database (`~/.candy-code/session.db`) | database `0600` |

Export files stay until you delete them. The one exception is session
retention: when `sessionRetentionDays` is set (it is off by default), exports
whose files are older than that window are swept at launch, by file age. Published pages are rows in the session database, and an expired or revoked
page answers `404`. To reclaim the space a session took, see [GC.md](GC.md).

## See also

- [COMMANDS.md](COMMANDS.md): the full slash-command reference
- [SERVER.md](SERVER.md): running `candy-code serve`, and the page routes
- [SECURITY.md](SECURITY.md): the trust boundaries around a shared page
- [GC.md](GC.md): reclaiming session storage
