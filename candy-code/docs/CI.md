# CI and automation

> Every page in `docs/` — see [the docs index](README.md).

CandyCode's headless mode (`candy-code -p`, see [README](../README.md#non-interactive-one-shot-mode)) is built for
machines: one prompt in, one answer out, a versioned JSON envelope on stdout, a meaningful exit
code. This page covers the two things around it: the shipped GitHub Action that wraps a headless
run, and the security doctrine you are agreeing to when you point an agent at content you do not
control.

## The composite action

`action.yml` lives at the root of the `candy-code/` directory in the monorepo. The org sync
subtree-splits that directory into `https://github.com/sugarcraft/candy-code`, and a subtree split
makes the directory's contents the repository root — the same mechanism that surfaces
`candy-code/composer.json` as the package root on Packagist. So the action resolves from the
canonical location GitHub looks in:

```yaml
- uses: sugarcraft/candy-code@<full-commit-sha>   # pin the SHA, not a tag
  with:
    prompt: "Review the diff in ./pr.diff, list problems by severity."
```

The action is a `composite` — no Node runtime, no Docker. It installs PHP 8.3 (pinned action SHA),
`composer global require`s `sugarcraft/candy-code` at the version you pin, runs one headless turn,
and parses the JSON envelope into the step summary and step outputs. It deliberately does **not**
post PR comments — commenting needs `pull-requests: write`, and an action that reviews untrusted
code should hold no token that can write to the pull request that supplied it. The caller posts.

### Inputs

| Input | Default | Meaning |
|---|---|---|
| `prompt` | — (required) | The prompt text. Crosses into the shell only via the environment, never interpolated into a script. |
| `permission-mode` | `plan` | Headless permission mode. **`plan` is the default on purpose**: a review must never edit. |
| `max-cost` | `1.00` | `--max-cost` ceiling in USD. A runaway or prompt-injected turn is stopped by the bill. |
| `model` | *(empty)* | `--model` override; empty inherits the configured default. |
| `args` | *(empty)* | Extra space-separated `candy-code` flags. Caller-owned text — never build it from PR content. |
| `candy-code-version` | `dev-master` | Composer constraint for the Packagist install. Pin a released version in anything you care about. |
| `fail-on-error` | `true` | Fail the step on a non-zero `candy-code` exit. `false` keeps going so your own steps can read the outputs. |

### Outputs

| Output | Content |
|---|---|
| `result` | The envelope `result` — the final assistant message. |
| `error-type`, `error-message` | From `error` when the run produced an error document; empty on success. |
| `refusals`, `refusal-count` | The `refusals` array (compact JSON) and its length — every tool call that was blocked mid-run. |
| `exit-code` | `0` ok, `1` ran-and-failed, `2` usage/config error, `127` binary missing. |

The envelope itself is `{"schema":1,"result":...}` on success plus optional
`"refusals":[{"tool":...,"kind":...,"reason":...}]`, and `{"schema":1,"result":null,
"error":{"type":...,"message":...}}` on failure — the same contract as `candy-code -p
--output-format json` locally. A document without `"schema"` is schema 0.

A turn the engine stopped early says so: under `--output-format
json` the document gains `steps_truncated: true`, `length_stopped: true`, or
`loop_guard_stopped_by: "<tool>"` — each present only when it happened, absent (not
false) otherwise — and a `--output-format text` run appends one plain, ANSI-free
trailer line per stop condition after the answer. A turn that billed an unpriced
model writes one `candy-code: ` notice to stderr. The exit code never changes for a
stopped-but-answered run: `0` still means an answer arrived, and these fields mean
it is a partial one.

### Credentials

The action takes no secret inputs — that is a feature. Pass provider credentials as ordinary
environment variables on the step, from your repo secrets:

```yaml
- uses: sugarcraft/candy-code@<full-commit-sha>
  env:
    ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
  with:
    prompt: "..."
```

`CANDY_CODE_PROVIDER`, `CANDY_CODE_MODEL`, `CANDY_CODE_PERMISSION_MODE` and
`CANDY_CODE_MAX_COST` are honored exactly as documented in
[ENVIRONMENT.md](ENVIRONMENT.md); the explicit `with:` values above them win because the action
passes them as flags.

### Posting the review back — the caller's one step

The action writes the result into its outputs and the step summary. If you want it on the PR, that
is one caller-side step in a job that has `pull-requests: write` and nothing else:

```yaml
permissions:
  contents: read
  pull-requests: write
steps:
  - uses: sugarcraft/candy-code@<full-commit-sha>
    id: review
    env:
      ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
    with:
      prompt: "Review the changes in $(pwd) against HEAD~1; be concrete."
  - name: Post the review
    env:
      GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
      REVIEW: ${{ steps.review.outputs.result }}
    run: gh pr comment "$PR_NUMBER" --body "$REVIEW"
```

Note the shape: the model's output crosses into the shell through `REVIEW`, not through `${{ }}`
inside the script line. Keep it that way.

## The doctrine — read this before automating anything untrusted

### Forked pull requests get no secrets — and that is the load-bearing fact

GitHub does not expose repository secrets to workflows triggered by `pull_request` from a fork
(the token is read-only and the `secrets` context is empty). Your first, worst idea will therefore
be `pull_request_target`, which does get secrets — and pairs it with checking out the PR head to
"review the actual diff". **That combination is the classic exfiltration vector.** With
`pull_request_target` + a head checkout + any step that executes head-supplied files (`npm install`
hooks, `composer` scripts, a `bash setup.sh`), an attacker's PR runs their code **with your secrets
in the environment**, and the logs are public. The workflow file that runs is from the **base**
branch — that protects the workflow text, nothing else.

Two safe shapes exist:

1. **Trusted inputs only.** Run the action on pushes and on `pull_request` from branches you
   control, or gated by an `approval` environment, where every byte the model reads was written by
   someone with write access. Easiest, and enough for "review my own branch" automation.
2. **Two-job artifact split** for forks. Job 1 (`pull_request`, no secrets) checks out the head and
   uploads the diff as an artifact. Job 2 (`pull_request_target`, secrets) downloads the **artifact
   only** — it never checks out or executes head code — and feeds the diff TEXT to `candy-code -p`.
   Secrets exist where untrusted CODE never lands; untrusted code ran where no secrets existed.

### Prompt injection: the model reads what the attacker wrote

Even in shape 2 the agent is handed attacker-controlled text. That is not a bug to route around, it
is the threat model — so the containment is what the agent can *do*, not what it reads:

- **`permission-mode: plan`** (the action default) refuses every mutating tool before it runs; the
  refusals surface in `refusals`/`refusal-count` instead of silently vanishing.
- **`max-cost`** caps the money a hijacked turn can burn.
- **Never auto-act on the output.** No auto-merge, no auto-approve, no "if the review says LGTM,
  close the issue" — that hands the attacker an oracle and an API. A human reads the summary.
- Treat the posted comment as untrusted rendering: GitHub parses model output as Markdown in step
  summaries and PR comments, so an injected reply can forge appearance (badge images, fake
  mentions, link shortening). The action flags this in the step summary itself.
- `args` is for flags you wrote. Anything concatenated from PR content eventually becomes a flag.
- Pin `uses:` to a full commit SHA (this repo's own ci.yml doctrine — the action does the same for
  its setup-php step), and pin `candy-code-version` once you have a reason to. A tag you do not
  audit is an update channel you do not control.

### What this action structurally refuses

- No token, no checkout, no posting → nothing to exfiltrate through the action itself.
- No interpolation of input text into script bodies (grep it: every `${{ }}` lands in `env:` or
  `with:`).
- No implicit `--permission-mode default`: loosening is a visible, per-call decision.
- Secrets are env, never inputs — inputs echo into the runner's context dumps; env does not.

## Running the headless binary without the action

Everything the action does is one command:

```sh
composer global require sugarcraft/candy-code
candy-code -p "summarize this repo" --output-format json \
  --permission-mode plan --max-cost 1.00 --root "$PWD"
```

Exit codes and error envelopes are documented in [TROUBLESHOOTING.md](TROUBLESHOOTING.md); the
provider selection and credential variables in [ENVIRONMENT.md](ENVIRONMENT.md); the permission
modes themselves in [PERMISSIONS.md](PERMISSIONS.md).

## Linting this action

`.github/` workflows are guarded by the monorepo CI; `action.yml` itself is a plain composite and
is checked with [actionlint](https://github.com/rhysd/actionlint) (`actionlint candy-code/action.yml
-parse-only` suffices for syntax). There is no repo test surface for it by design — zero PHP, zero
fixtures, zero suite figures; the doctrine above is the test.
