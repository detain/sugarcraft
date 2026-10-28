# C-AGT-5.g — FIX-1 (round 1)

Lane: cc/C-AGT-5.g · base tip 5b4ab4ebd → fixed tip **b5f0033e9** (single amended commit,
subject kept: `C-AGT-5.g: docs/CI.md — GitLab CI recipe around the signed phar`).
File touched: `candy-code/docs/CI.md` only (+37/−17 in the amended diff). Author/committer
`detain@interserver.net` verified on separate greps, zero trailers, tree clean.

## Facts re-derived on master before writing

- `.github/workflows/release-phar.yml` at the `candy-code/v0.1.1` tag emits
  `candy-code.phar.minisig` + `candy-code.phar.minisig.sha256` only — no `.sha256.sig`.
- `candy-code.phar.sha256.sig` entered the pipelines at **6ab223f36** (2026-10-27,
  `C-SES-5.c: release pipelines sign the sha256 sidecar`), which `git merge-base
  --is-ancestor` reports NOT an ancestor of the v0.1.1 tag (2026-10-20). Tags live:
  `candy-code/v0.1.0`, `candy-code/v0.1.1`, `candy-code/dev` (rolling prerelease, signed
  sidecar shape from the same change).
- `candy-code/src/Cli/SelfUpdate.php`: the swap path fetches the sidecar, downloads the phar
  to a temp file, compares its digest, and runs the pinned-key leg AFTER the hash and BEFORE
  the copy (its own comment: "Pinned-key leg, after the hash … and before the copy").
- `.github/workflows/release-phar.yml` "Checksum" step: "the checksum runs BEFORE signing on
  purpose — the signing step's headline artifact is a signature over THIS sidecar's body".
- `candy-code/README.md:128-134` wording (mirrored): "Releases from `candy-code/v0.1.1` onward
  carry an Ed25519 signature that `candy-code self-update` verifies against the pinned key
  before any swap. The preferred asset is `candy-code.phar.sha256.sig` … The legacy
  `candy-code.phar.minisig` signs the raw phar bytes and is accepted for one further release
  cycle."

## MAJOR-1 — v0.1.1 asset claim + pin/leg mismatch (CI.md:184-185, 215)

Corrected paragraph (opening sentences of "## GitLab CI", now):

> The composite action is a GitHub Actions mechanism — `uses:` has no GitLab counterpart. The
> same shape travels to GitLab as a plain recipe: download the release phar, verify it before
> anything executes it, and run one headless turn over the merge request's diff TEXT. The trust
> anchor is the one `candy-code self-update` uses, and the asset list is the one the
> `README.md` install section describes: `.github/workflows/release-phar.yml` publishes
> `candy-code.phar` with its `candy-code.phar.sha256` sidecar, and releases from
> `candy-code/v0.1.1` onward carry an Ed25519 signature verified against the pinned key before
> any swap. The preferred asset is `candy-code.phar.sha256.sig` — a detached signature over the
> `candy-code.phar.sha256` sidecar body, so verification stays on the stream and the phar never
> has to fit in memory — and it is what the recipe below fetches. That asset shape is young:
> the sidecar signature entered the pipelines on 2026-10-27, so a release cut before it —
> `v0.1.1` among them — publishes its signature as the legacy whole-archive
> `candy-code.phar.minisig` and has no `candy-code.phar.sha256.sig` to download; `self-update`
> accepts that legacy leg for one further release cycle, but new automation should not be built
> on it.

Pin/leg consistency (the `PHAR_VERSION` comment in the recipe) now:

>     PHAR_VERSION: v0.1.1      # pin an exact release; a floating "latest" is an update channel
>                               # you do not control — the same law as `candy-code-version` above.
>                               # With THIS pin the `.sha256.sig` curl below 404s: `v0.1.1` was cut
>                               # before the sidecar signature entered the pipelines (2026-10-27),
>                               # so its signature asset is the whole-archive legacy minisig — a
>                               # path for older clients, not one to build new automation on. Move
>                               # the pin to a release cut by a sidecar-signing pipeline; quietly
>                               # skipping the signature leg is not the alternative to take here

(Chose cure option (a) — state that the pin needs a post-2026-10-27 release — and kept the
sidecar-shape legs, because that is the current pipeline shape and the legacy whole-archive leg
is the one the page tells readers not to automate on. "skip the signature" was rejected as an
alternative: it would teach routing around the trust leg the paragraph two lines above refuses.)

## MAJOR-2 — inverted verification order (CI.md:183, 186-189)

Removed "exactly the asset the self-updater trusts first … Do the same here — signature before
hash, never the reverse". Replaced with a paragraph stating the true invariant:

> WHAT THE ANCHOR ACTUALLY GUARANTEES is not an order between two hashes. The swap path fetches
> the sidecar, downloads the phar to a temp file, checks that digest, and runs the pinned-key leg
> LAST — after the hash, so the bytes under test are the real artifact, and before the copy, so a
> rejection leaves nothing to roll back. The publish pipeline computes the checksum before
> signing because its signature is over the sidecar body: the sidecar has to exist first. The
> invariant the two share, and the one this recipe keeps, is that nothing runs until the pinned
> key has vetted a signature over the digest gating the bytes. A hash alone is a word that the
> published bytes are what someone meant to publish; the signature is what makes that word
> someone whose key you chose. The recipe below therefore checks the signature before
> `sha256sum -c` — an order belonging to a download-then-run job, credited to this page, not a
> transcription of the swap path's.

Recipe legs (three `curl`s, the sodium verify, then `sha256sum -c`) are unchanged: sig-then-hash
order kept, now credited to the recipe instead of attributed to self-update. "never the reverse"
dropped.

## Gates (plain pipe, `env -u LINES -u COLUMNS`, cwd `/home/sites/cc-cmp-C-AGT-5.g/candy-code`)

- `--filter 'ReadmeRosterDrift|BootstrapLaunchFormatConstants'` → **OK: 28 tests, 349
  assertions** (2 pre-existing PHPUnit deprecations), as briefed.
- `--filter 'DocFigureProseDrift'` → **91 tests, 3983 assertions, 1 failure**: the foreign
  `thirty-seven` standing red (`testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory`,
  `DocFigureProseDriftTest.php:5425`). Re-derived: W13's cure commit `9fbb73173` is NOT an
  ancestor of this lane's base, so the standing red is expected here, not a full-green pair.
  The CI.md edit added no new nomination — the Bootstrap page-quote roster for `docs/CI.md`
  rode unchanged.
- No suite-figure.json / durations.tsv / dist touches; nothing pushed.

## Verdict greps

- `onward also publish` — before (5b4ab4ebd): 1 → after (b5f0033e9): **0**
- `never the reverse` — before: 1 → after: **0**
- README-mirroring sentence (`install section` / "releases from `candy-code/v0.1.1` onward carry
  an Ed25519 signature verified against the pinned key before any swap") — before: 0 → after:
  **1** (present).
