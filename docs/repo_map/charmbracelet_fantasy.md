# charmbracelet/fantasy

## Metadata
- **URL:** https://github.com/charmbracelet/fantasy
- **Language:** Go
- **Stars:** 774
- **License:** MIT
- **Description:** Build AI agents with Go. Multiple providers, multiple models, one API. 🧙

## Feature List

- **Multi-Provider Support:** OpenAI, Anthropic, Google, Azure, Amazon Bedrock, OpenRouter, OpenAI-Compatible, Vercel AI, Kronk
- **Agent Framework:** Multi-step agentic loops with tool calling and result processing
- **Tool System:** Typed tool creation with automatic JSON schema generation from Go structs
- **Structured Outputs:** Type-safe object generation with `object.Generate[T]()` and streaming with `object.Stream[T]()`
- **Streaming:** Full streaming support for both agent runs and structured outputs with callbacks
- **Retry Logic:** Exponential backoff with retry header respect (`retry-after-ms`, `retry-after`)
- **Stop Conditions:** Configurable termination conditions (`StepCountIs`, `HasToolCall`, `HasContent`, `FinishReasonIs`, `MaxTokensUsed`)
- **Provider Options:** Pass-through for provider-specific functionality (cache control, etc.)
- **JSON Schema Generation:** Automatic schema from Go types with `json`, `description`, `enum` struct tags
- **JSON Repair:** Repair malformed JSON responses from models before parsing
- **Parallel Tool Execution:** Tools can declare parallel execution capability with semaphore limiting (max 5 concurrent)
- **Tool Call Validation & Repair:** Validates tool inputs against schema, with optional repair function

## Key Classes and Methods

### Core Interfaces
- **`Agent`:** `Generate(ctx, AgentCall)`, `Stream(ctx, AgentStreamCall)` — Main agent interface
- **`LanguageModel`:** `Generate(ctx, Call)`, `Stream(ctx, Call)`, `GenerateObject(ctx, ObjectCall)`, `StreamObject(ctx, ObjectCall)`, `Provider()`, `Model()`
- **`Provider`:** `Name()`, `LanguageModel(ctx, modelID)` — Abstract factory for language models

### Agent Configuration
- **`NewAgent(model, ...AgentOption)`** — Creates agent with model and options
- **`WithSystemPrompt(string)`** — Set system prompt
- **`WithTools(...AgentTool)`** — Register tools
- **`WithStopConditions(...StopCondition)`** — Set termination conditions
- **`WithMaxRetries(int)`**, **`WithTemperature(float64)`**, etc. — Standard LLM options

### Tool System
- **`NewAgentTool[T any](name, description, fn)`** — Creates typed tool from function
- **`NewParallelAgentTool[T any](name, description, fn)`** — Creates tool with parallel execution
- **`AgentTool`** interface: `Info() ToolInfo`, `Run(ctx, ToolCall) ToolResponse`

### Content Types
- **`TextContent`**, **`ReasoningContent`**, **`FileContent`**, **`ToolCallContent`**, **`ToolResultContent`**, **`SourceContent`**
- **`ResponseContent`** methods: `Text()`, `Reasoning()`, `ReasoningText()`, `Files()`, `Sources()`, `ToolCalls()`, `ToolResults()`

### Structured Outputs (`object` package)
- **`object.Generate[T any](ctx, model, ObjectCall)`** — Generate typed object
- **`object.Stream[T any](ctx, model, ObjectCall)`** — Stream typed object with progressive updates
- **`StreamObjectResult[T].PartialObjectStream()`** — Progressive object updates iterator
- **`StreamObjectResult[T].Object()`** — Wait for final typed result

### Schema (`schema` package)
- **`schema.Generate(reflect.Type)`** — Generate JSON schema from Go type
- **`schema.ParsePartialJSON(text)`** — Parse potentially incomplete JSON with repair
- **`schema.ValidateAgainstSchema(obj, schema)`** — Validate parsed object
- **`schema.Normalize(map[string]any)`** — Normalize schema for providers that reject type-arrays

### Retry (`retry.go`)
- **`RetryWithExponentialBackoffRespectingRetryHeaders[T](options)`** — Returns `RetryFunction[T]`
- **`RetryOptions`:** `MaxRetries`, `InitialDelayIn`, `BackoffFactor`, `OnRetry`
- **`DefaultRetryOptions()`** — Returns defaults: 2 retries, 2s initial, 2x factor

### Error Types
- **`Error`** — Core error with Title/Message/Cause
- **`ProviderError`** — Provider errors with `IsRetryable()`, `IsContextTooLarge()`
- **`RetryError`** — Wraps multiple retry errors
- **`NoObjectGeneratedError`** — Structured output failure with raw text and parse error

## Notable Algorithms / Named Patterns

### Exponential Backoff with Header Respect
```go
// From retry.go:L18-53
func getRetryDelayInMs(err error, exponentialBackoffDelay time.Duration) time.Duration {
    // Checks "retry-after-ms" first, then "retry-after" header
    // Ensures delay is reasonable: 0 < ms < 60s or ms < exponentialBackoffDelay
}
```

### Tool Call Validation & Repair Pattern
```go
// From agent.go:L1026-1052
func (a *agent) validateAndRepairToolCall(ctx, toolCall, availableTools, ...) {
    if err := a.validateToolCall(...); err == nil {
        return toolCall  // Valid, return as-is
    }
    if repairFunc != nil {
        if repairedToolCall, repairErr := repairFunc(ctx, options); repairErr == nil {
            if validateErr := a.validateToolCall(*repairedToolCall, ...); validateErr == nil {
                return *repairedToolCall  // Repaired successfully
            }
        }
    }
    // Mark invalid and return with error
    invalidToolCall.Invalid = true
    invalidToolCall.ValidationError = err
}
```

### JSON Schema Generation from Go Types
```go
// From schema/schema.go:L77-180
// Reflects on struct fields, uses json/description/enum tags
// Recursively handles nested structs, slices, maps, primitives
// Converts field names to snake_case when no json tag present
```

### Agent Loop with Multi-Step Tool Execution
```go
// From agent.go:L384-541 (Generate method)
// 1. Create step input messages (system + history)
// 2. Call model.Generate() with tools
// 3. Extract and validate tool calls
// 4. Execute tools (parallel if marked, else sequential)
// 5. Append tool results to messages
// 6. Check stop conditions
// 7. Loop if tool calls present and not stopped
```

### Parallel Tool Execution with Semaphore
```go
// From agent.go:L1540-1575
parallelSem := make(chan struct{}, 5)  // Max 5 concurrent
// Parallel tools acquire semaphore, sequential use mutex
```

## Strengths

- **Clean Provider Abstraction:** Single `Provider` interface with `LanguageModel` implementation, allowing easy swapping between OpenAI, Anthropic, Google, etc.
- **Type-Safe Tools:** `NewAgentTool[T]()` uses generics and reflection to create tools with automatic schema validation
- **Structured Outputs:** First-class `object.Generate[T]()` pattern with schema generation and JSON repair
- **Comprehensive Streaming:** Both blocking and streaming agent execution with granular callbacks (`OnTextDelta`, `OnToolCall`, `OnToolResult`, `OnStepFinish`, etc.)
- **Robust Retry Logic:** Exponential backoff respecting provider retry headers, with proper context cancellation handling
- **Extensible Architecture:** `PrepareStepFunction`, `RepairToolCallFunction` allow deep customization of agent behavior
- **Provider-Specific Options:** `ProviderOptions` map allows passing provider-specific settings without core changes
- **Well-Documented:** Clear README, AGENTS.md development guide, and doc.go package documentation
- **Active Maintenance:** Part of charmbracelet ecosystem (Bubble Tea, etc.) with professional standards
- **Testing Infrastructure:** VCR-based integration tests with provider cassettes

## Weaknesses

- **Go-Only:** No Python, TypeScript, or other language bindings—limits adoption to Go projects
- **Work in Progress:** Explicitly noted as powering "Crush" coding agent; image/audio models not yet supported
- **API Stability:** Given it's powering a commercial product (Crush), API may still evolve
- **Complexity:** Agent loop with streaming, retries, tool execution, and stop conditions is complex; higher learning curve
- **No Built-in Prompt Management:** No prompt templates, chaining, or memory beyond message history
- **Limited Evaluation:** No built-in metrics, evaluation, or observability beyond usage stats
- **Dependency on External Providers:** Heavily dependent on provider API stability and feature support

## SugarCraft Mapping

**No direct mapping** — `charmbracelet/fantasy` is an AI agent framework for building LLM-powered applications in Go, while **SugarCraft** is a PHP monorepo of TUI (Terminal User Interface) library ports from the Charmbracelet ecosystem (Bubble Tea, Glow, etc.).

| fantasy component | SugarCraft equivalent | Notes |
|------------------|---------------------|-------|
| `Agent`, `LanguageModel` interfaces | N/A | SugarCraft has no AI/agent infrastructure |
| `NewAgentTool[T]()` factory | N/A | SugarCraft models are TUI components, not AI tools |
| `object.Generate[T]()` structured outputs | N/A | No structured output needs in TUI rendering |
| `schema.Generate()` | N/A | JSON schema generation is AI-specific |
| `Provider` interface | N/A | No provider abstraction needed for TUI |
| Streaming callbacks | `view()` rendering | Both are streaming patterns but fundamentally different domains |
| Retry with backoff | HTTP client retry in `include/Api/` | Different context, same pattern |

**If SugarCraft were to explore AI integration**, the provider abstraction and tool pattern from fantasy could inspire:
- A `sugar-agents` library for AI-augmented CLI tools
- Provider abstraction for multiple LLM backends
- Tool calling pattern for function invocations from CLI

Currently, SugarCraft's focus remains **terminal UI components** with no AI/agent capabilities.

## Analysis

**charmbracelet/fantasy** is a well-architected AI agent framework that provides a unified API across multiple LLM providers. Its core strength lies in the clean separation between the `Agent`, `LanguageModel`, and `Provider` interfaces, allowing developers to build provider-agnostic agentic applications. The tool system is particularly elegant—using Go generics and reflection to automatically derive JSON schemas from Go struct types, making tool creation type-safe and developer-friendly.

The framework's approach to structured outputs via the `object` package demonstrates thoughtful design. By supporting multiple modes (JSON native, tool-based, text-with-schema), it gracefully handles the varying capabilities across providers. The JSON repair mechanism is crucial for production use—models frequently output malformed JSON, and having built-in repair with schema validation prevents many integration headaches.

Compared to similar frameworks like LangChain or the OpenAI SDK directly, fantasy occupies a niche of being provider-agnostic while remaining Go-native and focused on the agent use case. Its integration with the charmbracelet ecosystem (used by Crush, their AI coding agent) provides real-world validation of the API design.

For PHP developers or those working in the SugarCraft monorepo, fantasy represents a different paradigm—agentic AI rather than TUI rendering. The patterns are instructive but not directly applicable to the TUI domain that SugarCraft serves. The project's existence does suggest that if SugarCraft ever needed AI capabilities (e.g., an AI-assisted TUI builder or natural language interface to terminal tools), the fantasy architecture could serve as a reference for how to design a multi-provider, tool-enabled AI system in a strongly-typed language.
