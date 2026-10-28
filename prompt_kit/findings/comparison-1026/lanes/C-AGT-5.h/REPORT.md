# C-AGT-5.h — PR-comment bot example workflow — CLOSED

Commit: ecda4ca36430abe01ba481d4ad9f2834d32a8192 on cc/C-AGT-5.h (base c8e8a1f42, never pushed).
%ae grep -c detain@interserver.net = 1; %ce separately = 1; Co-Authored = 0; tree clean; branch confirmed before+after.

## Shipped (4 files, +489/-1)
1. **candy-code/docs/examples/pr-comment-bot.yml** (new, 95 lines) — `issue_comment`/created,
   workflow `permissions: {}`, two jobs. `review` job: `if: >-` gate =
   `github.event.issue.pull_request` && mention `@candy-code` && `author_association` ∈
   OWNER/MEMBER/COLLABORATOR; `contents: read` + `pull-requests: read`; fetches
   `gh pr diff "$PR_URL" > pr.diff` into the EMPTY workspace (NO checkout step anywhere), then
   `uses: sugarcraft/candy-code@<full-commit-sha>` (placeholder, pinned-SHA doctrine stated in
   header) with `permission-mode: plan`, `max-cost: "1.00"`. `post` job: sole
   `pull-requests: write` holder; `REVIEW: ${{ needs.review.outputs.result }}` env binding;
   run body writes warning banner + body file, `gh pr comment --body-file`. Zero `${{ }}`
   inside any `run:` body; no `pull_request_target`; no `actions/checkout`.
2. **candy-code/docs/CI.md** (+39) — "The PR-comment bot — the association-gated example"
   inserted between "Posting the review back" and "The doctrine" (doctrine stays the
   presupposed required reading). Explains base-context-secrets ⇒ gate load-bearing, text-only
   diff, solitary write scope, GITHUB_TOKEN anti-recursion, warning-banner rendering point;
   quotes the gate as a yaml excerpt; names the guard file. No line-number cites, no new
   'candy-code: ' prose literals.
3. **candy-code/docs/README.md** — examples row now "systemd unit, the PR-comment bot workflow,
   other deployment samples | You install it as a service or wire the bot into CI".
4. **tools/tests/PrCommentBotExampleTest.php** (new, 354 lines, 8T/47A) — region-anchored
   text-YAML parser (local runBodies/ifBodies copy, licensed by WorkflowRunInterpolationTest
   precedent; symfony/yaml forbidden in the PHAR-based tools-guards job). Arms: extraction
   floors (>=2 run, >=2 if, anti-vacuity); no-interpolation-in-run (headline, with an
   assembled-fragment forgery + $REVIEW/$PR_URL/$RUNNER_TEMP discriminator arm); gate-present-
   and-complete over if-bodies (all six needles); never-pull_request_target/never-checkout
   (fail-closed by omission); write-scope solitary AND positioned (line-anchored
   `/^ +pull-requests: write$/m` count==1, offset under the `\n  post:\n` key, read scope above
   it, `permissions: {}` present); plan-mode + numeric cost ceiling + REVIEW env-relocation pin
   + bodies-read-env-variables; every uses: ref full-40-hex or the `<placeholder>`.
   Plan §1.9 respected: no raw `${{` string literal (fragments only), every bracketed regex
   also carries `$`/`(`/space (corpus-exempt), no bare {@see foo()} cites.

action.yml UNTOUCHED (its holds-no-token doctrine stands, per design block).

## Gate actuals
- `candy-code/vendor/bin/phpunit --bootstrap tools/tests/_bootstrap.php --no-configuration
  tools/tests/PrCommentBotExampleTest.php` → **OK (8 tests, 47 assertions)** (pre- and post-commit).
- Whole dir `tools/tests/` → **OK (497 tests, 2627 assertions)** — era baseline, no other guard
  tripped (ToolsEnvRosterTest population is tools/*.php only; ChildLifetimesToolTest roster
  unperturbed — guard spawns nothing; no tools/tests file-count roster exists).
- `cd candy-code && env -u LINES -u COLUMNS vendor/bin/phpunit -c phpunit.xml --filter
  'BootstrapLaunchFormatConstants|DocFigureProseDrift'` → **Tests: 110, Assertions: 4327**
  green; split proves: BootstrapLaunchFormatConstantsTest alone **OK (18/271)**; the 2 PHPUnit
  deprecations ride DocFigureProseDriftTest's shipped test #1 metadata shape (I touched zero
  candy-code test files) — not a failure, base-era.
- YAML validity: symfony/yaml parse of the example → jobs [review,post], gate folds to the
  full expression, permissions maps as designed.

## Deviations
- Runner-path law text said `candy-core/vendor/bin/phpunit`; this worktree carries only
  `candy-code/vendor` (linked mode) — used `candy-code/vendor/bin/phpunit` with the identical
  bootstrap/no-configuration form. No behavioral difference.
- Write-scope arm uses a line-anchored regex (`^ +pull-requests: write$`) rather than raw
  strpos so header-comment prose mentioning the scope cannot double-count; position assertions
  use PREG_OFFSET_CAPTURE against the `\n  post:\n` job key.

## Durations / figure carries
NONE owed: zero candy-code/tests files minted (tools/tests joins the standalone
tools-guards directory run, per the precedent sentence reproduced in the guard docblock);
zero sugar-crush changes; suite-figure.json/durations.tsv/dist untouched and uncommitted.

## FIX ROUND 1 (commit 5ea83ca14, base ecda4ca36)

All 5 MINORs landed inside the lane's declared files (yml, CI.md bot section, guard test; README row untouched — still truthful).

- M-1: gate arm now pins the connective STRUCTURE — whitespace-normalized verbatim gate leg ANDed-then-ORed. Mutation `&&`->`||` (both top-level sites): exactly testTheAssociationGateIsPresentAndComplete red.
- M-2: write-solitude gained `assertStringNotContainsString('write-all')` + comment-stripped substr_count('write') === 1. Mutations write-all-on-job1 and flow `{contents: read, pull-requests: write}`: exactly testTheWriteScopeIsSolitaryAndBelongsToThePostingJob red.
- M-3: new testTheAntiRecursionAndSkipGatingPremisesHold pins GH_TOKEN: secrets.GITHUB_TOKEN at count 2. Mutation PAT-swap on the post step: that test red.
- M-4: bodiesForKey now consumes multi-line quoted scalars (quote-escape aware: odd-backslash-run for ", doubled '' for '); headline arm sees continuation-line `${{ }}`. New discriminator testTheRunParserSeesMultiLineQuotedScalars (forgery assembled from fragments, §1.9); parser-branch neuter -> exactly that test red; clean yml stays green both directions.
- M-5: post if -> `needs.review.result == 'success' && needs.review.outputs.result != ''`; yml header + post-job comments and the CI.md paragraph carry the premise (skipped review = null output, null != '' is true; GITHUB_TOKEN no-loop holds only while both bindings stay machine-token). Guard regex-pins the new post-if line (revert mutation red). CI.md gate excerpt re-verified verbatim-identical to the yml (indent aside).

Gates (worktree, post-commit clean): PrCommentBotExampleTest 10 tests/56 assertions OK (was 8/47); tools/tests dir 499T/2636A OK; candy-code --filter 'BootstrapLaunchFormatConstants|DocFigureProseDrift' 110T/4327A OK with the reviewer-disclosed 2 base-era deprecations (byte-identical figures to r1). Mutation scripts + originals + this report's evidence: /tmp/opencode/cc-cmp-lanes/C-AGT-5.h/fix1-mut/. php -l clean; no dist/suite-figure/durations touches; not pushed.
