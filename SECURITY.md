# Security policy

## Reporting a vulnerability

If you discover a security vulnerability in any SugarCraft library,
**please do not open a public issue**. Instead, email
<!-- codacy ignore Agentlinter_security_no-pii-exposure -->
**`detain@interserver.net`** (intentionally public for security vulnerability reports) with:

- The library affected (e.g. `candy-scriptui`, `sugar-markdown`).
- A description of the vulnerability and its impact.
- Steps to reproduce — minimum working example preferred.
- Your name / handle for the credit (optional).

GitHub private-vulnerability reporting is enabled on this repository and
is the **primary** private intake channel — use the "Report a
vulnerability" form on the security tab. The email address above is the
secondary route, for when the form is unavailable or you prefer mail.

You can expect:

- An acknowledgement of receipt within **48 hours**.
- A first-pass triage assessment within **7 days**.
- A fix target — either a patched release with a CVE if applicable,
  or an explanation of why we don't consider the report a
  vulnerability.

## Supported versions

SugarCraft ships on two rails, and both get security fixes:

- **`master` (the monorepo)** — the source of truth. Every security
  fix lands here first and is available immediately to composer and
  git consumers.
- **Tagged signed releases** — `candy-code/v0.1.0`, `candy-code/v0.1.1`
  and successors are built by `.github/workflows/release-phar.yml` into
  a self-contained `candy-code.phar`; `candy-top/v0.1.0` and successors
  are built by `.github/workflows/release-candy-top-phar.yml` into
  `candy-top.phar`. Both ship to GitHub Releases with a SHA-256 sidecar
  and an Ed25519 `minisig` signature. The release key is
  **sugarcraft-wide and pinned** in `SelfUpdate.php`
  (`PUBLIC_KEY_BASE64`, pinned 2026-10-20): `candy-code self-update`
  refuses a payload that does not verify against it, and loudly
  refuses an absent signature. The one keyless exception is the earliest
  candy-code build (`candy-code/v0.1.0`, pre-dating the key pin) —
  SHA-256 only; `candy-top/v0.1.0` shipped signed. The container mirror
  `ghcr.io/detain/sugarcraft-candy-code` (tags `latest` + commit SHA)
  is rebuilt by `.github/workflows/candy-code-image.yml` from the
  published composer stream.

We do **not** backport to old tags or maintain LTS branches pre-1.0: a
fixed vulnerability is shipped by cutting a new tag and pushing the
updated `master`; phar users receive it through the signed
`self-update` path (with `self-update --rollback` as a one-shot escape
hatch). If you need a patched binary for a specific released version,
say so in your report and we will prioritise the release cut.

## Scope

The SugarCraft fleet is TUI / CLI components, terminal apps, and a
handful of network-facing surfaces. Practical attack surfaces:

- **Input parsing** (`SugarCraft\Core\Util\Parser`,
  `SugarCraft\Core\InputReader`) — malformed terminal input shouldn't
  crash a program or smuggle escape sequences past the parser.
- **Markdown rendering** (`SugarCraft\Markdown\Renderer`) — input is
  user-supplied; the renderer must not let markdown payloads emit raw
  ANSI sequences they didn't author.
- **Shell-out paths** (`SugarCraft\ScriptUi\Process\RealProcess`,
  `SugarCraft\Core\Cmd::exec`) — `proc_open` invocations should never
  execute arbitrary user input through a shell when the caller
  supplies an argv list.
- **OSC 8 hyperlinks** (`SugarCraft\Core\Util\Ansi::hyperlink`) — URLs
  should be passed through verbatim; we don't sanitise the URL but we
  do escape it inside the OSC envelope.
- **Terminal-escape injection from untrusted data** — every
  presentation sink that can render attacker-influenced strings
  (pane titles, rail titles, poster/attachment bytes, tool output,
  stderr echoes) must pass through `SugarCraft\Core\Util\Sanitize`.
  The full fleet contract lives in
  [docs/TRUST-BOUNDARY.md](./docs/TRUST-BOUNDARY.md); bypassing it is
  a vulnerability, not a style nit.
- **Network-facing apps** — `candy-git-server` (self-hostable Git
  server), `sugar-ssh-server` (ForceCommand SSH hub middleware), and
  the `candy-code` web UI / `serve` mode (including its container
  image). Auth bypasses, protocol-level smuggling, and SSRF through
  their fetch paths are in scope.
- **Config-as-code execution** — MCP stdio server definitions, SSH
  hub options, and Git repository configuration all name programs to
  run. Treat a hostile `.mcp.json`, `authorized_keys` environment
  story, or cloned repo's `diff.external`/`textconv` driver as
  arbitrary code execution; reports about missing warnings or missing
  isolation around these paths are in scope.

Out of scope:

- Bugs in user-supplied themes, hooks, or callbacks.
- Vulnerabilities in transitive dependencies (`react/event-loop`,
  `league/commonmark`, `symfony/console`) — please report those
  upstream and CC us.
- DoS via deeply nested input — practical limits are best-effort.

## Supply chain

- **Lock files:** per-library `composer.lock` files are never
  committed (the root manifest carries its own). A committed lib lock
  would silently defeat CI's path-repository injection — see the
  Gotchas section of [AGENTS.md](./AGENTS.md).
- **Advisory scanning:** the `candy-code` CI leg runs
  `composer audit` on its install and fails on HIGH or CRITICAL
  advisories (lower severities are reported but do not fail the leg,
  so the signal stays actionable). The step lives in
  `.github/workflows/ci.yml`.
- **Waivers:** an accepted advisory is waived only by adding it to
  the `audit.ignore` array in `candy-code/composer.json`
  (`composer config audit.ignore --json '["GHSA-…"]'` run from
  `candy-code/`), and the PR that adds the waiver must carry a
  written reason. There are no waivers in the array today.
- **Dependabot:** security-update and secret-scanning alerts are
  enabled through repository settings (`push_protection` too), but
  there is no committed `.github/dependabot.yml`, so no automated
  version-bump PRs open. Triaging Dependabot security alerts and
  cutting the bump commits is owner work — same contact as above;
  there is no separate cadence and no silent queue.
- **Release integrity:** every published phar ships a SHA-256 sidecar
  and (from 2026-10-20 onward) a pinned-key `minisig`; see
  *Supported versions* above.

## Disclosure

We follow coordinated disclosure: we'll publish the fix and the CVE
together. Embargo periods up to **90 days** are negotiable for severe
issues.
