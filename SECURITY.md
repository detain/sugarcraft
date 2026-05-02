# Security policy

## Reporting a vulnerability

If you discover a security vulnerability in any CandyCore library,
**please do not open a public issue**. Instead, email
**detain@interserver.net** with:

- The library affected (e.g. `candy-shell`, `candy-shine`).
- A description of the vulnerability and its impact.
- Steps to reproduce — minimum working example preferred.
- Your name / handle for the credit (optional).

You can expect:

- An acknowledgement of receipt within **48 hours**.
- A first-pass triage assessment within **7 days**.
- A fix target — either a patched release with a CVE if applicable,
  or an explanation of why we don't consider the report a
  vulnerability.

## Supported versions

CandyCore is currently under active pre-1.0 development. Security
fixes ship against `master` only; pre-1.0 tagged releases are not
patched. Once the libraries split into their own repos at v1.0, each
will publish a stable branch and follow semver — at that point we'll
support the latest minor of the latest major plus the previous major's
final minor.

## Scope

The CandyCore libraries are TUI / CLI components. Practical attack
surfaces:

- **Input parsing** (`CandyCore\Core\Util\Parser`,
  `CandyCore\Core\InputReader`) — malformed terminal input shouldn't
  crash a program or smuggle escape sequences past the parser.
- **Markdown rendering** (`CandyCore\Shine\Renderer`) — input is
  user-supplied; the renderer must not let markdown payloads emit raw
  ANSI sequences they didn't author.
- **Shell-out paths** (`CandyCore\Shell\Process\RealProcess`,
  `CandyCore\Core\Cmd::exec`) — `proc_open` invocations should never
  execute arbitrary user input through a shell when the caller
  supplies an argv list.
- **OSC 8 hyperlinks** (`CandyCore\Core\Util\Ansi::hyperlink`) — URLs
  should be passed through verbatim; we don't sanitise the URL but we
  do escape it inside the OSC envelope.

Out of scope:

- Bugs in user-supplied themes, hooks, or callbacks.
- Vulnerabilities in transitive dependencies (`react/event-loop`,
  `league/commonmark`, `symfony/console`) — please report those
  upstream and CC us.
- DoS via deeply nested input — practical limits are best-effort.

## Disclosure

We follow coordinated disclosure: we'll publish the fix and the CVE
together. Embargo periods up to **90 days** are negotiable for severe
issues.
