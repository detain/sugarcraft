# CandyCrush Implementation - Supervisor Startup Prompt

You are the Supervisor for the CandyCrush AI coding assistant implementation.

## Context

SugarCraft is a PHP monorepo containing 50 TUI library ports from the Charmbracelet ecosystem. CandyCrush is a new library that implements an AI coding assistant TUI with multi-provider support.

## Reference Files

- **Master Plan**: `/home/sites/sugarcraft/crush_ai.md`
- **Supervisor Instructions**: `/home/sites/sugarcraft/.candy-crush-plan/supervisor/SUPERVISOR.md`
- **Updates/Notes**: `/home/sites/sugarcraft/.candy-crush-plan/updates.md`
- **Agent Instructions**:
  - Coder: Use `coder` subagent type
  - Reviewer: `/home/sites/sugarcraft/.candy-crush-plan/agents/reviewer.md`
  - TestEngineer: `/home/sites/sugarcraft/.candy-crush-plan/agents/test_engineer.md`
  - Scribe: `/home/sites/sugarcraft/.candy-crush-plan/agents/scribe.md`
  - FinalReviewer: `/home/sites/sugarcraft/.candy-crush-plan/agents/final_reviewer.md`
  - PR Agent: `/home/sites/sugarcraft/.candy-crush-plan/agents/pr_agent.md`

## What is CandyCrush

An AI coding assistant TUI with:
- **Providers**: OpenAI, Claude Code CLI, SGLANG (MiniMax-M2.7), Bedrock, Vertex, Custom
- **Skills**: SKILL.md-based skills system (Claude Code compatible)
- **Hooks**: PreToolUse/PostToolUse hooks for tool interception
- **Agents**: Subagent system for specialized tasks
- **MCP**: Model Context Protocol client integration
- **TUI**: Full multi-pane terminal interface with menu system

## Implementation Phases

1. **Phase 1**: Core Foundation (Steps 1.1-1.5)
2. **Phase 2**: TUI (Steps 2.1-2.5)
3. **Phase 3**: Provider Integration (Steps 3.1-3.6)
4. **Phase 4**: Skills System (Steps 4.1-4.4)
5. **Phase 5**: Hooks System (Steps 5.1-5.3)
6. **Phase 6**: Agents (Steps 6.1-6.2)
7. **Phase 7**: MCP Integration (Step 7.1)
8. **Phase 8**: Polish (Steps 8.1-8.5)

## Your Task

1. First, read the supervisor instructions and master plan
2. Check updates.md for any prior state
3. Start with Step 1.1 - spawn a Coder agent
4. Follow the cycle: Coder → Review → (Fix if needed) → Tests → Docs
5. Continue through all 35 steps
6. End with Final Review and PR merge

## Workflow Per Step

```
1. Spawn Coder (with step instruction file path)
2. Wait for completion
3. Spawn Reviewer to validate
   - If problems → Spawn Fix agent → Review again (loop until clean)
4. Spawn TestEngineer
5. Spawn Scribe
6. Mark step complete in updates.md
7. Proceed to next step
```

## Important Rules

1. **GH CLI**: Always `unset GITHUB_TOKEN` before `gh` commands
2. **Immutable PHP**: Use readonly and with*() builders
3. **Strict types**: `declare(strict_types=1);` in every file
4. **PSR-12**: Follow PHP coding standards
5. **Branch**: Keep on master after each step
6. **Log**: Update updates.md with progress and issues

## Ready to Resume

**CURRENT PROGRESS - Step 3.4 just completed (Bedrock Provider)**

### Completed Steps:
- **Phase 1 (Core Foundation)**: Steps 1.1-1.5 ✅
- **Phase 2 (TUI)**: Steps 2.1-2.5 ✅
- **Phase 3 (Provider Integration)**: Steps 3.1-3.4 ✅

### Next Step: Step 3.5 - Vertex Provider

Resume from Step 3.5 by spawning a Coder agent with the step file:

```
Step 3.5: Vertex Custom Providers
File: /home/sites/sugarcraft/.candy-crush-plan/steps/3.5_vertex_custom_providers.md
```

### Recent Changes (Step 3.4):
- Added `aws/aws-sdk-php` dependency to composer.json
- Created BedrockProvider.php with AWS SDK integration
- 35 tests written and passing
- Documentation updated in README.md and CALIBER_LEARNINGS.md

### Completed Provider Steps:
- 3.1 OpenAI Provider ✅
- 3.2 SGLANG Provider ✅
- 3.3 Claude Code Provider ✅
- 3.4 Bedrock Provider ✅
- 3.5 Vertex Provider → NEXT
- 3.6 Custom Provider → remaining
