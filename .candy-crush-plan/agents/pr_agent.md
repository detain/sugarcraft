# PR Agent Instructions

## Role
You are the **PR Agent** for CandyCrush. Your job is to create the PR and merge it.

## IMPORTANT: GH CLI Usage

**Every time you use `gh` you MUST first `unset GITHUB_TOKEN`**

```bash
unset GITHUB_TOKEN && gh pr ...
```

## Steps

### 1. Ensure Clean State

Check current branch:
```bash
cd /home/sites/sugarcraft
git status
git branch
```

### 2. Stage Changes

```bash
cd /home/sites/sugarcraft
git add candy-crush/
git add crush_ai.md
git add .candy-crush-plan/
```

### 3. Commit

```bash
cd /home/sites/sugarcraft
git commit -m "feat(candy-crush): initial implementation of AI coding assistant

- Multi-provider support (OpenAI, Claude Code, SGLANG, Bedrock, Vertex, Custom)
- Skills system with SKILL.md support
- Hooks system (PreToolUse, PostToolUse)
- Agent system with subagent support
- MCP client integration
- Full TUI with multi-pane layout
- Session persistence

Co-authored-by: Joe Huss <detain@interserver.net>"
```

### 4. Push

```bash
cd /home/sites/sugarcraft
unset GITHUB_TOKEN && gh push -u origin ai/candy-crush-initial
```

### 5. Create PR

```bash
unset GITHUB_TOKEN && gh pr create \
  --title "feat(candy-crush): initial AI coding assistant implementation" \
  --body "$(cat <<'EOF'
## Summary
Initial implementation of CandyCrush - a TUI AI coding assistant with:
- Multi-provider support (OpenAI, Claude Code CLI, SGLANG, Bedrock, Vertex, Custom OpenAI-compatible)
- Skills system mirroring Claude Code conventions
- Hooks system (PreToolUse, PostToolUse)
- Agent system with subagent support
- MCP client integration
- Full multi-pane TUI interface
- Session persistence via SQLite

## Test Plan
- [ ] Unit tests pass for all core classes
- [ ] Provider integration tests
- [ ] Skills loading and matching
- [ ] Hook execution
- [ ] TUI renders correctly

## Coverage
Coverage target: 95%

Co-authored-by: Joe Huss <detain@interserver.net>
EOF
)"
```

### 6. Merge PR

```bash
# Get PR number first
unset GITHUB_TOKEN && PR_NUM=$(gh pr view --json number --jq .number)

# Merge
unset GITHUB_TOKEN && gh pr merge $PR_NUM --merge --delete-branch
```

### 7. Update Local Master

```bash
cd /home/sites/sugarcraft
git checkout master
git pull --ff-only
```

## Error Handling

If PR creation fails:
1. Check if branch already has PR
2. Check for conflicts
3. Resolve and retry

If merge fails:
1. Check if PR needs review
2. Address any blocking issues
3. Retry merge

## Output

Report:
- PR number
- PR URL
- Merge status
- New master state

Log to `/home/sites/sugarcraft/.candy-crush-plan/updates.md`
