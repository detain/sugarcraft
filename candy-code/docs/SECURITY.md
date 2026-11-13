# Security

> Every page in `docs/` — see [the docs index](README.md).

candy-code follows the SugarCraft security policy, which lives in one place so it cannot drift: the
repository-root [`SECURITY.md`](https://github.com/detain/sugarcraft/blob/master/SECURITY.md). Read it for
supported versions, scope, supply-chain rules and the disclosure timeline.

## Reporting a vulnerability

Do not open a public issue. Report privately, to the monorepo (`detain/sugarcraft`, not the subtree-split
mirror):

- **GitHub private vulnerability reporting** — the "Report a vulnerability" form on the repository's Security
  tab. This is the primary channel.
- **Email** — the maintainer address named in the root policy, when the form is unavailable.

Name `candy-code` as the affected package, describe the impact, and include minimal reproduction steps.

## Where candy-code's own security surface is documented

- [`PERMISSIONS.md`](PERMISSIONS.md) — the permission gate, modes and rules.
- [`MCP.md`](MCP.md) — the MCP trust gate and why `.mcp.json` is declined by default.
- [`CI.md`](CI.md) — the fork-PR and prompt-injection doctrine for headless runs.
- [`SERVER.md`](SERVER.md) — the `serve` mode security model, allowed hosts and IPs.
- [`DOCKER.md`](DOCKER.md) — the container image.
