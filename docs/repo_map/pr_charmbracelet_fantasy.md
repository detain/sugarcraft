# Second-Stage Ecosystem Intelligence Report: charmbracelet/fantasy

## Metadata
- **URL:** https://github.com/charmbracelet/fantasy
- **Analysis Date:** 2026-05-27
- **Period Covered:** Nov 2025 - May 2026 (issues/PRs active in this window)
- **Language:** Go
- **Stars:** 775
- **License:** MIT
- **Repository Size:** Multi-provider AI agent framework

---

## 1. Repository Overview

**charmbracelet/fantasy** is an AI agent framework for Go that provides a unified API across multiple LLM providers (OpenAI, Anthropic, Google, Azure, Amazon Bedrock, OpenRouter, Vercel AI, Kronk). It powers "Crush," charmbracelet's AI coding agent, providing real-world production validation of the API design.

**Activity Metrics:**
- 14 open issues, 203+ closed PRs
- 13 open PRs, many in active review
- Very active development with recent maintainer responses
- Strong community engagement with 100 forks

**Key Active PRs:**
- #192: Structured output for agents (feature complete, in review)
- #127: Embeddings API support (in progress)
- #149: WebSocket mode for OpenAI Responses API (in progress)
- #222: Binary size reduction via build tags (draft, seeking direction)

---

## 2. Existing SugarCraft Mapping

From `repo_map/charmbracelet_fantasy.md`:

| fantasy Component | SugarCraft Equivalent | Notes |
|-----------------|---------------------|-------|
| `Agent`, `LanguageModel` interfaces | N/A | No AI/agent infrastructure |
| `NewAgentTool[T]()` factory | N/A | TUI components, not AI tools |
| `object.Generate[T]()` structured outputs | N/A | No structured output needs |
| `schema.Generate()` | N/A | AI-specific JSON schema |
| `Provider` interface | N/A | No provider abstraction needed |
| Streaming callbacks | `view()` rendering | Different domains |
| Retry with backoff | HTTP client retry in `include/Api/` | Different context, same pattern |

**Key Insight:** SugarCraft currently has no AI/agent capabilities and no plans for them. The fantasy patterns are instructive but not directly applicable to the TUI-focused SugarCraft mission.

---

## 3. Previously Identified Gaps

From first-pass analysis, these gaps were identified:

1. **Go-Only** — No Python, TypeScript, or other bindings
2. **Work in Progress** — Image/audio models not yet supported
3. **API Stability** — Still evolving (powers commercial product)
4. **Complexity** — Agent loop with streaming, retries, tools is complex
5. **No Built-in Prompt Management** — No templates, chaining, or memory beyond history
6. **Limited Evaluation** — No built-in metrics or observability
7. **Dependency on External Providers** — Heavily coupled to provider API stability

---

## 4. High-Signal Open Issues

### Issue #239: Include accumulated text in OnTextEnd callback
**Author:** carsonfarmer (May 15, 2026)
**Type:** Feature

**Problem:** `OnTextEndFunc` receives only message ID, forcing consumers to buffer all deltas themselves to get completed text. Meanwhile, `OnReasoningEndFunc` already passes full content.

**Proposal:** Add accumulated text as second parameter to `OnTextEndFunc func(id string, text string) error`

**Signal:** The text is already available in `activeTextContent[part.ID]` internally—this is an API ergonomics gap, not a new data requirement.

**SugarCraft Relevance:** None—streaming callback pattern differs from TUI rendering.

---

### Issue #235: Allow callers to disable all tools for a single Agent call
**Author:** fwang2002 (May 13, 2026)
**Type:** Feature (area: tools)

**Problem:** No way to disable tools for a single call without using `PrepareStep` callback. Both `ActiveTools == nil` and `ActiveTools == []string{}` mean "include every tool."

**Workaround:** `PrepareStepResult.DisableAllTools` exists but requires custom `PrepareStep` callback.

**Proposal:** Add `DisableAllTools bool` to `AgentCall` and `AgentStreamCall`, mirroring internal field.

**SugarCraft Relevance:** API design lesson—avoid ambiguous nil/empty equivalent semantics.

---

### Issue #208: openaicompat silently drops ToolResultOutputContentMedia
**Author:** ezynda3 (Apr 22, 2026)
**Type:** Bug (area: tools, providers: openai, openrouter)

**Problem:** `openaicompat` provider's tool result serialization switch only handles `ToolResultContentTypeText` and `ToolResultContentTypeError`. `ToolResultContentTypeMedia` falls through with no match—**no message appended to array**—LLM never receives the result.

**Impact:** Tools returning images work with Anthropic but silently produce no result for ALL other providers (OpenAI, OpenRouter, Azure, Google).

**Root Cause Location:** `providers/openaicompat/language_model_hooks.go` ~line 409

**Fix Pattern:** Add `case fantasy.ToolResultContentTypeMedia:` to switch, creating multi-content tool messages with base64 data URIs.

**SugarCraft Relevance:** Provider abstraction with incomplete handling is dangerous—silent data loss is worse than explicit errors.

---

### Issue #130: Config mismatch—Fantasy ignores context_window from Catwalk
**Author:** ivan-cstv (Feb 3, 2026)
**Type:** Bug (provider: anthropic claude)

**Problem:** Fantasy v0.7.0 ignores `context_window` from Catwalk provider configs, always using 200K token limit even when 1M specified.

**SugarCraft Relevance:** Configuration propagation across abstraction layers is a common pain point.

---

### Issue #122: Support for embeddings endpoint
**Author:** acheong08 (Jan 22, 2026)
**Type:** Feature

**Request:** One-stop typed API for embeddings since authentication is already handled.

**Note:** PR #127 (add embeddings API) is in progress.

**SugarCraft Relevance:** Embeddings suggest RAG/in-memory search use cases.

---

### Issue #118: Native json output support for agents
**Author:** rashadism (Jan 17, 2026)
**Type:** Feature

**Problem:** No way to get structured output from agents similar to `object.Generate[T]` for models. User got it working with tool + `HasToolCall` stop condition, but couldn't get json mode working with agents.

**Proposal:** Have Agent implement `ObjectGenerator` interface for seamless `object.Generate[T]` with agents.

**Status:** PR #192 addresses this (feature complete, in review).

**SugarCraft Relevance:** API symmetry/implements patterns are valued by users.

---

### Issue #95: Deduplicate code across providers and core logic
**Author:** LarsArtmann (Dec 10, 2025)
**Type:** Maintainability

**Finding:** The `dupl` tool identified **431 clone groups** in codebase, with significant duplication in:
- Provider implementations (`anthropic`, `google`, `openai`, `openrouter`, `openaicompat`)
- `language_model_hooks.go`
- `provider_options.go`
- `agent.go`
- Test files

**SugarCraft Relevance:** High—sugar-charts similarly suffers from copied path-repo closures across 40+ libs. Shared abstractions reduce maintenance burden.

---

### Issue #89: Structured response for streaming mode
**Author:** casidiablo (Dec 2, 2025)
**Type:** Feature

**Request:** Streaming mode needs structured output capability.

**SugarCraft Relevance:** Progressive/partial rendering patterns.

---

## 5. Important Closed Issues

### Issue #252/248: Bedrock region enforcement
**Status:** Merged May 2026
**Pattern:** Enforced `us-east-1` as region for Bedrock, then added `WithRegion` option.

### Issue #242: Retry on network connection errors
**Status:** Merged May 2026
**Pattern:** Extended retry logic beyond HTTP errors to include network-level errors.

### Issue #238/237: Anthropic reasoning/web search fidelity
**Status:** Merged May 2026
**Pattern:** Preserve reasoning replay and web search error replay fidelity.

### Issue #245/246: Require stream termination events before finishing
**Status:** Merged May 2026
**Pattern:** 
- Anthropic: require `message_stop` before finishing
- OpenAI: require terminal stream events before finishing

**Lesson:** Streaming requires explicit termination handshake.

---

## 6. Recurring Pain Points

### A. Provider Parity Gaps
Multiple issues highlight inconsistent behavior across providers:
1. **Media handling** — Anthropic handles `ToolResultOutputContentMedia`, others don't (#208)
2. **Context window config** — Ignored from Catwalk configs (#130)
3. **Structured output + tool calls** — PR #192 only implements for OpenAI provider

**Pattern:** Each provider implements features independently, leading to capability drift.

### B. Binary Size
**Issue #222 (Draft):** Provider build tags to reduce binary size
- All providers compiled: 32 MB
- Core only: 12 MB
- **Savings: 63%**

Maintainer response suggests preferring package reorganization over build tags.

### C. API Ergonomics Asymmetry
1. `OnTextEndFunc` vs `OnReasoningEndFunc` — inconsistent parameter passing (#239)
2. `DisableAllTools` exists at `PrepareStepResult` level but not at `AgentCall` level (#235)
3. No way to get structured output from agents without workarounds (#118)

### D. Code Duplication
**431 clone groups** identified (#95). Key areas:
- Provider hook files
- Provider options handling
- Agent core logic

---

## 7. Frequently Requested Features

| Feature | Issue | Priority Signal |
|---------|-------|-----------------|
| Structured output for agents | #118, #192 | High—PR in review |
| Embeddings endpoint | #122, #127 | High—PR in progress |
| WebSocket mode (OpenAI Responses API) | #149 | Medium—active development |
| Amazon Nova via Bedrock | #106, #117 | Medium—PR in review |
| Disable tools per-call | #235 | Medium |
| Accumulated text in callback | #239 | Low but elegant |
| Binary size reduction | #222 | Low—maintainer prefers reorganizing |

---

## 8. Important PRs

### PR #192: Structured output for agents
**Status:** Open, feature complete, in review
**Author:** crazybolillo
**Purpose:** Enable `object.Generate[T]` with agents

**Key Details:**
- Updates `LanguageModel` to support tool calls alongside structured output
- Backwards compatible
- Currently only OpenAI provider implements tool calls + structured output
- Other providers get structured output without tool calls
- Closes #118

**SugarCraft Relevance:** Shows importance of interface symmetry.

---

### PR #127: Add embeddings API + OpenAI/OpenAI-compat
**Status:** Open, 8 comments, in progress
**Purpose:** Add embeddings endpoint support

---

### PR #149: WebSocket mode for OpenAI Responses API
**Status:** Open, 2 tasks done
**Purpose:** Alternative transport for Responses API

---

### PR #222: Build tags for binary size reduction
**Status:** Draft, seeking direction
**Author:** ljuti (contributor)

**Maintainer Response (andreynering):**
> "The Go module system is smart enough to only add dependencies used... I would [prefer] to reorganize packages in a way that would prevent these extra dependencies."

**Pattern:** Maintainers favor architectural solutions over build-tag patching.

---

### PR #229: Agent prepare call
**Status:** Open
**Purpose:** Add `PrepareCall` hook to `AgentCall` / `AgentStreamCall` (addresses #224)

---

### PR #85: Hooks for pre/post tool call manipulation
**Status:** Open
**Purpose:** Add hooks for pre/post tool call manipulation

---

## 9. Architectural Changes

### Recent Merged Changes

1. **Bedrock region handling** — From hardcoded to configurable
2. **Retry header respect** — Network error retrying added
3. **Streaming termination** — Require explicit termination events
4. **Reasoning replay fidelity** — Preserve reasoning content accurately

### Pending Architectural Work

1. **Structured output for agents** (#192) — Major API extension
2. **Provider build tags** (#222) — Package reorganization alternative
3. **Agent PrepareCall** (#229) — Extension hooks for call manipulation

---

## 10. Performance Discussions

### Binary Size Issue (#222)
- 32 MB stripped binary for core+2 providers
- 63% reduction possible via build tags
- Maintainer prefers package reorganization

### Streaming Goroutine Leak (#191)
- **Fixed:** Stream idle timeout and goroutine leak in `processStepStream`
- Issue demonstrates complexity of streaming with proper cleanup

### Retry Performance (#242)
- Extended retry to network connection errors
- Pattern: respect `retry-after-ms` and `retry-after` headers

---

## 11. Extensibility Discussions

### Issue #224: Application-level extension field + PrepareCall hook
**Request:** Add extension field and `PrepareCall` hook to `AgentCall`/`AgentStreamCall`

**Purpose:** Allow callers to pass through custom data without modifying core types.

**Status:** PR #229 addresses this.

### Issue #85: Pre/post tool call manipulation hooks
**Request:** Hooks for pre/post tool call manipulation

**Status:** Open

### Issue #89: Structured response for streaming mode
**Request:** Streaming mode structured output

**SugarCraft Relevance:** If SugarCraft ever adds streaming, similar patterns matter.

---

## 12. API/UX Complaints

### A. Library Ergonomics (Discussion #247)
**Author:** guesdo
**Reference:** RubyLLM ergonomics

> "What would it take for Fantasy to have this level of ergonomic feel?"

**Signal:** Community wants cleaner, simpler API surface.

### B. OnTextEnd Inconsistency (#239)
`OnReasoningEndFunc` passes full content; `OnTextEndFunc` only passes ID.

### C. DisableTools Gap (#235)
No way to disable tools per-call without `PrepareStep` callback.

### D. Structured Output Gap (#118)
Cannot get structured output from agents without workarounds.

---

## 13. Migration Problems

No explicit migration issues in recent issues. However:

1. **Context window ignored** (#130) — Breaking for users with custom Catwalk configs
2. **Provider-specific behavior** — Media tool results silently dropped for non-Anthropic providers (#208)
3. **API evolution** — Still adding features to `AgentCall`/`AgentStreamCall` (#224, #229)

---

## 14. Clever Fixes & Workarounds

### Workaround #1: DisableTools via PrepareStep
Users who want to disable tools for a single call must use:
```go
PrepareStepResult{D disableAllTools: true}
```
This requires implementing `PrepareStep` function just to flip a boolean.

### Workaround #2: Structured output + HasToolCall
Issue #118 user got structured output working via:
```go
tool + HasToolCall stop condition
```
But couldn't get native JSON mode working.

### Workaround #3: Media tool results with Anthropic
Only Anthropic provider handles `ToolResultOutputContentMedia`. Users with OpenAI/OpenRouter must use Anthropic for media-capable tools.

---

## 15. Community Workarounds

1. **Provider-specific code** — Using Anthropic for media tools to avoid #208
2. **Custom PrepareStep** — Just to disable tools for one call
3. **Manual text buffering** — Buffering `OnTextDelta` callbacks to reconstruct text
4. **Fork for build tags** — Contributor (ljuti) willing to maintain fork for binary size

---

## 16. Maintainer Guidance Patterns

### A. Prefer Architectural Solutions Over Patching
**Example:** For binary size (#222), maintainer prefers package reorganization over build tags.

### B. Require Discussion Before Large Features
**Example:** PR #192 notes contributor "created a discussion that was approved by a maintainer" for new features.

### C. Provider-by-Provider Implementation
**Example:** Structured output + tool calls only implemented for OpenAI; other providers get degraded mode.

### D. Backwards Compatibility Emphasis
**Example:** PR #192 is "fully backwards compatible."

### E. Reuse Existing Types
**Example:** Using `WithRegion` pattern for Bedrock instead of new abstractions.

---

## 17. Rejected Ideas Worth Revisiting

1. **Build tags for binary size** — May be rejected in favor of package reorganization
2. **Simple token counter** (Discussion #121) — Idea raised, no conclusion
3. **ContextTooLargeError** (Discussion #123) — Idea raised for better context management

---

## 18. Problems Likely Relevant To SugarCraft

### A. Code Duplication (#95)
SugarCraft's 40+ lib monorepo has similar issues—duplicated path-repo closures across `composer.json` files. The fantasy experience validates that early attention to DRY pays off.

### B. Silent Data Loss (#208)
Provider drops `ToolResultContentTypeMedia` silently. SugarCraft should ensure any provider abstraction either:
- Handles all content types consistently, OR
- Fails loudly if content type unsupported

### C. API Ergonomics Asymmetry
`OnReasoningEndFunc` vs `OnTextEndFunc` inconsistency. SugarCraft's streaming callbacks (e.g., `view()` rendering) should ensure symmetric patterns.

### D. Binary Size (63% savings possible)
If SugarCraft ever adds heavy AI features, the fantasy 63% binary size reduction lesson applies: consider what users actually need vs what's compiled by default.

### E. Configuration Propagation (#130)
Context window config ignored across layers. SugarCraft's API configs should propagate consistently through abstraction layers.

---

## 19. Features SugarCraft Should Consider

### A. Provider Abstraction Lessons
If SugarCraft ever adds multi-backend support (e.g., different storage providers), the fantasy Provider pattern is instructive:
- Abstract factory (`Provider` interface)
- Per-provider implementation
- Consistent capability detection

### B. Tool Calling Pattern
The `NewAgentTool[T]()` generics + reflection for automatic schema generation is elegant. If SugarCraft ever needs function invocation from CLI, this pattern is worth study.

### C. Streaming with Callbacks
`OnTextDelta`, `OnToolCall`, `OnStepFinish` callback pattern for streaming. SugarCraft's `view()` could adopt similar granular callback hooks.

### D. Retry with Header Respect
`retry-after-ms` / `retry-after` header respect pattern. SugarCraft's HTTP client could benefit from similar retry header awareness.

### E. Structured Output Pattern
`object.Generate[T]()` type-safe generation pattern. If SugarCraft ever needs structured CLI output, this is a reference.

---

## 20. Architectural Lessons

### A. Provider Capability Matrix
Fantasy has inconsistent provider capabilities:
- Anthropic: handles media tool results ✓
- OpenAI-compat: drops media tool results ✗
- Structured output + tools: only OpenAI ✓

**Lesson:** Document and test provider capability matrices explicitly.

### B. Abstraction Leakage
Some abstractions leak provider details:
- `context_window` handling differs by provider
- Media type handling differs by provider

**Lesson:** Choose abstraction boundaries that don't require provider-specific fallbacks.

### C. Extension Points
Fantasy's `PrepareStep` callback is powerful but awkward for simple cases. Better to have explicit fields (`DisableAllTools bool`) than force extension through callbacks.

**Lesson:** Provide both explicit fields AND extension hooks—don't force hooks for simple cases.

### D. Silent vs Loud Failures
Issue #208: Silent data loss (media dropped). Better to fail explicitly.

**Lesson:** Fail loudly on unsupported operations; silent failures cause debugging nightmares.

### E. Binary Size from Proliferation
431 code clone groups + multiple heavy providers = 32 MB binary. 

**Lesson:** Consider compile-time composition from the start.

---

## 21. Defensive Design Lessons

### A. Defensive Content Type Handling
Always handle all content types explicitly:
```go
switch toolResultPart.Output.GetType() {
case fantasy.ToolResultContentTypeText: // handled
case fantasy.ToolResultContentTypeError: // handled
// NO implicit fallthrough - error on unknown types
default:
    return fmt.Errorf("unsupported tool result type: %v", type)
}
```

### B. Defensive Streaming
Require proper termination:
```go
// Anthropic: require message_stop before finishing
// OpenAI: require terminal stream events
```

### C. Defensive Config Propagation
Validate config fields at boundary:
```go
// Don't silently ignore context_window
if cfg.context_window > 0 {
    options = append(options, WithMaxTokens(cfg.context_window))
}
```

### D. Defensive Build Composition
Allow users to compile only what they need:
- Go: build tags
- PHP: composer `--no-dev` + selective autoloading

---

## 22. Ecosystem Trends

### A. Multi-Provider as Default
Users expect OpenAI + Anthropic + local providers all working. Single-provider libraries are declining.

### B. Structured Output as Table Stakes
LangChain, LlamaIndex, and fantasy all moving toward native structured output. JSON mode / tool calling + structured output.

### C. Embeddings Everywhere
Vector search / RAG patterns becoming standard even in small agent frameworks.

### D. Binary Size Consciousness
Go ecosystem increasingly aware of binary size (fantasy's 32 MB sparked discussion).

### E. Streaming as Default
Blocking + streaming both needed; streaming requires careful termination handling.

---

## 23. Strategic Opportunities

### For SugarCraft (if AI integration ever considered)

1. **Agent framework in PHP** — No mature Go-like option exists for PHP
2. **Multi-provider abstraction** — Could learn from fantasy's provider pattern
3. **Tool calling via PHP attributes** — Generics + reflection for typed tools
4. **Streaming TUI + AI** — Combine Bubble Tea rendering with fantasy-style streaming

### For SugarCraft (immediate)

1. **Document capability matrices** for multi-lib features
2. **Reduce composer.json duplication** — Centralize path-repo closure pattern
3. **Fail loudly on unsupported content types** — Never silently drop
4. **Provide explicit fields over forced hooks** — Don't require callbacks for simple cases

---

## 24. Cross-Ecosystem Pattern Matches

| Pattern | fantasy Issue | SugarCraft Equivalent |
|---------|---------------|----------------------|
| Code duplication | #95 (431 clone groups) | `composer.json` path-repo closures |
| Silent data loss | #208 (media dropped) | None currently |
| API asymmetry | #239 (callbacks differ) | None currently |
| Binary size | #222 (32 MB) | None currently |
| Config propagation | #130 (context_window) | None currently |
| Extension hooks | #85, #229 | None currently |

---

## 25. High ROI Recommendations

### For SugarCraft Development

1. **Adopt defensive switch patterns** — Never let switch fallthrough silently; handle all known cases and error on unknown.

2. **Document capability matrices** — If any abstraction layer has provider-specific capabilities, document them explicitly.

3. **Provide both fields AND hooks** — Don't force `PrepareStep` callbacks for what could be simple bool fields.

4. **Consider compile-time composition** — If SugarCraft grows heavy dependencies, allow opt-out via composer or build flags.

5. **Prioritize DRY** — 431 clone groups in fantasy is a warning; address duplication early.

6. **Fail loudly** — Replace any silent drops with explicit errors.

### For SugarCraft AI Consideration (if ever)

1. **Study fantasy's Provider interface** — Clean separation of concerns.

2. **Study tool calling pattern** — `NewAgentTool[T]()` generics + reflection is elegant.

3. **Study streaming callbacks** — Granular `OnTextDelta`, `OnToolCall` pattern.

4. **Study retry with header respect** — Respect `retry-after` headers in HTTP clients.

5. **Consider embeddings** — Vector search patterns becoming standard.

---

## Appendix: Key Metrics

| Metric | Value |
|--------|-------|
| Open Issues | 14 |
| Closed Issues | 50+ |
| Open PRs | 13 |
| Closed PRs | 203+ |
| Stars | 775 |
| Forks | 100 |
| Code Clone Groups | 431 (#95) |
| Binary Size (all providers) | 32 MB |
| Binary Size (core only) | 12 MB |
| Binary Size Reduction Possible | 63% |

---

*Report compiled 2026-05-27 from GitHub Issues, PRs, and Discussions analysis.*
