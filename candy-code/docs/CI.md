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
installs `sugarcraft/candy-code` at the version you pin via `composer create-project` into the
runner temp dir and adds its `bin/` to `PATH`, runs one headless turn, and parses the JSON envelope
into the step summary and step outputs. `create-project` is root semantics on purpose: the package's
own `minimum-stability: dev` manifest becomes the composer root, so its `"sugarcraft/*": "@dev"`
sibling requirements resolve as written — a `composer global require` cannot install this package
at all today, because the global root is `stable` and the siblings have no stable releases. It
deliberately does **not**
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
| `candy-code-version` | `dev-master` | Composer constraint for the `create-project` install. Pin a released version in anything you care about. The only real pin today is `dev-master#<split-repo-commit-sha>`, and it pins candy-code only — its `sugarcraft/*` siblings still resolve their own `dev-master` independently. |
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

## The PR-comment bot — the association-gated example

The full example lives at [examples/pr-comment-bot.yml](examples/pr-comment-bot.yml): someone
comments `@candy-code review this` on a PR, the agent runs in `plan` mode over the PR's diff
**text**, and the result is posted back as a reply. It is the one fork-PR review shape that
never needs the artifact split below, and it earns that with three load-bearing choices:

- **The event is `issue_comment`, and the gate is not decoration.** `issue_comment` runs in the
  base context, so — unlike `pull_request` — it does receive secrets and a write-capable token.
  That is precisely why the job runs only when the comment mentions the bot **and** the
  commenter's `author_association` is `OWNER`, `MEMBER` or `COLLABORATOR`. Without the
  association test, anyone who can type a comment can direct a token-bearing, paid agent run at
  your repository. The gate, in full:

  ```yaml
  if: >-
    github.event.issue.pull_request
    && contains(github.event.comment.body, '@candy-code')
    && (github.event.comment.author_association == 'OWNER'
    || github.event.comment.author_association == 'MEMBER'
    || github.event.comment.author_association == 'COLLABORATOR')
  ```

- **Nothing is checked out or executed.** The diff arrives as text via `gh pr diff` into an
  empty workspace and that file is the model's whole world — attacker-authored *code* never
  lands where the secrets are, which is the separation the doctrine below spends two jobs
  enforcing elsewhere. There is no `actions/checkout` step in the file at all.
- **The write scope is solitary.** The run job holds `contents: read` and `pull-requests: read`;
  only the second job holds `pull-requests: write`, and the only thing it does is paste the
  review into a comment — with a `> [!WARNING]` banner, because GitHub renders comment bodies
  as Markdown and the review text is untrusted content wearing your bot's avatar. The review
  crosses into that job as a job output bound through `env:`, exactly the shape above.

Comments created with `GITHUB_TOKEN` do not trigger workflow runs, so the bot cannot loop on its
own replies. Pin the action ref to a full commit SHA as everywhere else on this page, and note
that the example is guarded in the build by `tools/tests/PrCommentBotExampleTest.php` — the
association gate, the absence of any checkout, the solitary write scope and the env-only
crossings are pins, not suggestions.

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

Everything the action does is two commands:

```sh
composer create-project --no-dev --prefer-dist sugarcraft/candy-code /tmp/candy-code
PATH="/tmp/candy-code/bin:$PATH" candy-code -p "summarize this repo" --output-format json \
  --permission-mode plan --max-cost 1.00 --root "$PWD"
```

Exit codes and error envelopes are documented in [TROUBLESHOOTING.md](TROUBLESHOOTING.md); the
provider selection and credential variables in [ENVIRONMENT.md](ENVIRONMENT.md); the permission
modes themselves in [PERMISSIONS.md](PERMISSIONS.md).

## GitLab CI

The composite action is a GitHub Actions mechanism — `uses:` has no GitLab counterpart. The same
shape travels to GitLab as a plain recipe: download the release phar, verify it before anything
executes it, and run one headless turn over the merge request's diff TEXT. The trust anchor is the
one `candy-code self-update` uses, and the asset list is the one the `README.md` install section
describes: `.github/workflows/release-phar.yml` publishes `candy-code.phar` with its
`candy-code.phar.sha256` sidecar, and releases from `candy-code/v0.1.1` onward carry an Ed25519
signature verified against the pinned key before any swap. The preferred asset is
`candy-code.phar.sha256.sig` — a detached signature over the `candy-code.phar.sha256` sidecar body,
so verification stays on the stream and the phar never has to fit in memory — and it is what the
recipe below fetches. That asset shape is young: the sidecar signature entered the pipelines on
2026-10-27, so a release cut before it — `v0.1.1` among them — publishes its signature as the
legacy whole-archive `candy-code.phar.minisig` and has no `candy-code.phar.sha256.sig` to download;
`self-update` accepts that legacy leg for one further release cycle, but new automation should not
be built on it.
Despite the file names nothing here is minisign format: both are raw base64 sodium signatures, so
the verify leg is a few lines of PHP (the official PHP CLI images ship the sodium extension
enabled) against a public key you pin yourself — the same base64 value the binary carries as
`SelfUpdate::PUBLIC_KEY_BASE64`. A key served by the channel it authenticates is not an anchor.
Releases at or before `candy-code/v0.1.0` carry no signature asset at all — the verify leg fails
closed there, which is the honest answer, not an obstacle to route around.

WHAT THE ANCHOR ACTUALLY GUARANTEES is not an order between two hashes. The swap path fetches the
sidecar, downloads the phar to a temp file, checks that digest, and runs the pinned-key leg LAST —
after the hash, so the bytes under test are the real artifact, and before the copy, so a rejection
leaves nothing to roll back. The publish pipeline computes the checksum before signing because its
signature is over the sidecar body: the sidecar has to exist first. The invariant the two share,
and the one this recipe keeps, is that nothing runs until the pinned key has vetted a signature
over the digest gating the bytes. A hash alone is a word that the published bytes are what someone
meant to publish; the signature is what makes that word someone whose key you chose. The recipe
below therefore checks the signature before `sha256sum -c` — an order belonging to a download-then-
run job, credited to this page, not a transcription of the swap path's.

```yaml
# Illustrative recipe; nothing here runs in this repo's CI.
candy-code-diff:
  stage: build
  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
  script:
    - git diff "$CI_MERGE_REQUEST_DIFF_BASE_SHA" "$CI_COMMIT_SHA" > mr.diff
  artifacts:
    paths: [mr.diff]

candy-code-review:
  stage: test
  needs: [candy-code-diff]
  variables:
    GIT_STRATEGY: none        # this job may hold secrets, so the merge source reaches it as
                              # artifact TEXT only — no checkout, nothing head-supplied executes
    PHAR_VERSION: v0.1.1      # pin an exact release; a floating "latest" is an update channel
                              # you do not control — the same law as `candy-code-version` above.
                              # With THIS pin the `.sha256.sig` curl below 404s: `v0.1.1` was cut
                              # before the sidecar signature entered the pipelines (2026-10-27),
                              # so its signature asset is the whole-archive legacy minisig — a
                              # path for older clients, not one to build new automation on. Move
                              # the pin to a release cut by a sidecar-signing pipeline; quietly
                              # skipping the signature leg is not the alternative to take here
    PHAR_BASE: https://github.com/detain/sugarcraft/releases/download/candy-code
  environment:
    name: candy-code-review   # bind a required-approval rule to this environment, and keep
                              # ANTHROPIC_API_KEY and PINNED_PUBKEY_B64 masked and protected:
                              # fork pipelines never read them, and their values never echo
  before_script:
    - apt-get update -qq && apt-get install -y -qq jq  # php:8.3-cli ships curl and sha256sum
  script:
    - curl -fsSL -o candy-code.phar            "$PHAR_BASE/$PHAR_VERSION/candy-code.phar"
    - curl -fsSL -o candy-code.phar.sha256     "$PHAR_BASE/$PHAR_VERSION/candy-code.phar.sha256"
    - curl -fsSL -o candy-code.phar.sha256.sig "$PHAR_BASE/$PHAR_VERSION/candy-code.phar.sha256.sig"
    - |
      php -r '
        $pub = base64_decode((string) getenv("PINNED_PUBKEY_B64"), true);
        $sig = base64_decode(trim((string) file_get_contents("candy-code.phar.sha256.sig")), true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($sig, file_get_contents("candy-code.phar.sha256"), $pub)) {
          fwrite(STDERR, "the pinned-key check over the sha256 sidecar failed\n");
          exit(1);
        }
      '
    - sha256sum -c candy-code.phar.sha256
    - php candy-code.phar -p "Review the diff in ./mr.diff, list problems by severity."
        --output-format json --permission-mode plan --max-cost 1.00 --root "$PWD" > review.json
    - jq -e '.schema == 1 and .error == null' review.json
    - jq -r '.result' review.json > review.md
    - jq '.refusals | length' review.json   # every tool call plan mode refused — read the number
```

The legs are the doctrine above in a different shell: the agent receives attacker-written TEXT and a
verified binary it trusts, `plan` and `--max-cost` contain what a hijacked turn can do, and the
exit codes and envelope shapes mean exactly what [the outputs table](#outputs) and
[TROUBLESHOOTING.md](TROUBLESHOOTING.md) say they mean — a non-zero run fails the job before any
`jq` leg reads its document.

Posting the review back is one more job, and it is the only one holding a write-scoped project
token — the reviewer itself keeps none, so a prompt-injected answer cannot post, close, or merge
anything:

```yaml
candy-code-post:
  stage: deploy
  needs: [candy-code-review]
  variables:
    GIT_STRATEGY: none
  script:
    - 'curl -fsS --request POST --header "PRIVATE-TOKEN: $GITLAB_POST_TOKEN"
       --data-urlencode body@review.md
       "$CI_API_V4_URL/projects/$CI_PROJECT_ID/merge_requests/$CI_MERGE_REQUEST_IID/notes"'
```

The note body is model output and GitLab renders it as Markdown — the same forged-appearance
hazard named above for step summaries and PR comments. No auto-merge on a review verdict, either
side of the bridge.

## Linting this action

`.github/` workflows are guarded by the monorepo CI; `action.yml` itself is a plain composite and
is checked with [actionlint](https://github.com/rhysd/actionlint) (`actionlint candy-code/action.yml
-parse-only` suffices for syntax). It now has a real repo test surface, in two layers:
`tools/tests/ActionYmlInstallTest.php` (the `tools-guards` job) pins the install leg to the
resolvable `create-project` shape and fails the build if any `${{ inputs.* }}` ever interpolates
into a `run:` body, and `.github/workflows/candy-code-action-selftest.yml` runs the shipped action
end-to-end — offline echo provider, `plan` mode — whenever `action.yml` or this page changes,
asserting exit code 0 and a non-empty `result`. The self-test installs from Packagist's split repo,
so it proves the install leg and the envelope, not unmerged PHP; the doctrine above is still what
keeps the payload itself honest.
