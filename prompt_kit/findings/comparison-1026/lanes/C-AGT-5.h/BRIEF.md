### C-AGT-5.h — PR-comment bot example workflow
- item: C-AGT-5 (sources: GAP-GIT-12, GAP-AUT-05, p2-miss: GIT-12 bot)
- kind: gap · effort S
- approach: Ship `candy-code/docs/examples/pr-comment-bot.yml`, an `issue_comment` workflow:
  - it is gated on an `@candy-code` mention AND `author_association` ∈ OWNER/MEMBER/COLLABORATOR;
  - job 1 runs the existing action in `plan` mode on the PR's diff text, with no write token and never checking out or running PR code;
  - job 2 alone holds `pull-requests: write` and posts `result` with `gh pr comment`;
  - every value crosses into shell through `env:` only.
  - `action.yml` is unchanged, so its "holds no token" doctrine stands.
  - Add a `docs/CI.md` section, and a `tools/tests` guard that the example keeps the env-only and association gates.
- files.create: `candy-code/docs/examples/pr-comment-bot.yml`, `tools/tests/PrCommentBotExampleTest.php`
- files.modify: `candy-code/docs/CI.md` (bot section), `candy-code/docs/README.md` (examples row wording)
- tests: create `tools/tests/PrCommentBotExampleTest.php` (no `${{` inside `run:`, the association gate is present, no `pull_request_target` checkout of the head)
- docs/guards: `docs/CI.md`
- serialized: [docs:CI.md] [docs:README.md (docs index)]
- deps: C-AGT-5.g
- phase: normal
- ruling: R13 (remote security). Is an issue-comment-triggered bot that posts with a write token acceptable, and under which association gate?
- risks: a mis-copied example by users is the residual risk. The guard pins only our copy.

---

## C-AGT-6 — /commit, /pr & worktrees

