You are operating inside a large monorepo containing many internal Sugarcraft libraries/apps and a large collection of research/reference documentation for third-party repositories.

Your task is to perform a comprehensive ecosystem comparison and opportunity analysis across all internal Sugarcraft packages/apps versus all documented third-party repos, PRs, issue discussions, and ecosystem references.

---

# High-Level Objective

For every Sugarcraft package/app:

* Understand what it currently does
* Understand its architecture, APIs, capabilities, roadmap, and limitations
* Compare it against all relevant third-party repositories and PR discussions
* Identify:

  * Missing features
  * Better UX patterns
  * Faster algorithms
  * Better architecture approaches
  * Better APIs
  * Better TUI interactions
  * Better plugin systems
  * Better extensibility
  * Better examples/cookbooks/docs
  * Better developer workflows
  * Better testing strategies
  * Better performance approaches
  * Better caching/indexing/search techniques
  * Better rendering/layout approaches
  * Better terminal handling
  * Better async/concurrency models
  * Better state management
  * Better observability/debugging
  * Better accessibility/usability
  * Better packaging/distribution/versioning
  * Better AI/agent integrations
  * Better MCP integrations
  * Better command systems
  * Better keyboard/mouse interaction systems
  * Better theming/layout/composition systems
  * Better persistence/session handling
  * Better virtualization/windowing/render diffing
  * Better event systems
  * Better resource handling
  * Better portability
  * Better examples/cookbooks/templates
  * Better onboarding/docs/tutorials

You must generate detailed actionable upgrade reports.

---

# Repository Layout

Primary monorepo map:

* `docs/repo_map.md`

Per-Sugarcraft package/app docs:

* `docs/repo_map/sugarcraft_<dir>.md`

Examples:

* `docs/repo_map/sugarcraft_candy-core.md`
* `docs/repo_map/sugarcraft_terminal-ui.md`

Each Sugarcraft lib/app directory also contains:

* `README.md`

Third-party research docs:

* All other files under `docs/repo_map/`
* EXCLUDING files prefixed with:

  * `sugarcraft_`

PR / issue research files:

* Files prefixed with:

  * `pr_`
  * `issue_`
  * `discussion_`
  * similar research/reference docs

---

# REQUIRED AGENT ORCHESTRATION MODEL (STRICT)

You MUST follow this exact orchestration hierarchy and execution behavior.

Failure to follow this hierarchy is considered incorrect execution.

----------

# AGENT HIERARCHY

There are EXACTLY FOUR orchestration layers:

1.  Master Agent
2.  8 Parallel Group Worker Agents
3.  Sequential Per-Package Analysis Agents
4.  Final Aggregation Agent

----------

# LEVEL 1 — MASTER AGENT

The master agent is responsible for orchestration only.

The master agent MUST:

1.  Read:
    -   `docs/repo_map.md`
2.  Discover ALL Sugarcraft package/app mapping files:
    -   `docs/repo_map/sugarcraft_*.md`
3.  Extract the package/app names from those files.
4.  Divide ALL Sugarcraft packages/apps into EXACTLY 8 balanced groups.

Balance groups by:

-   estimated complexity
-   documentation size
-   likely analysis workload
-   app/lib size
-   architectural complexity

5.  Spawn EXACTLY 8 GROUP WORKER AGENTS SIMULTANEOUSLY.

These 8 group worker agents MUST run in parallel.

Example:

-   Group Worker 1
-   Group Worker 2
-   Group Worker 3
-   Group Worker 4
-   Group Worker 5
-   Group Worker 6
-   Group Worker 7
-   Group Worker 8

ALL 8 are launched concurrently.

----------

# REQUIRED PROMPT INHERITANCE RULE

The master agent MUST construct a COMPLETE worker prompt.

That worker prompt MUST include:

-   all global instructions
-   all analysis requirements
-   all formatting requirements
-   all output requirements
-   all comparison requirements
-   all relevance filtering requirements
-   all reporting requirements
-   all source citation requirements
-   all orchestration requirements relevant to lower-level agents

Every agent spawned by the master agent MUST receive the full inherited worker prompt.

Additionally:

Every group worker agent MUST pass the same inherited instructions forward to every package-analysis subagent it spawns.

This means ALL spawned agents operate with:

-   identical analysis standards
-   identical formatting standards
-   identical output expectations
-   identical evaluation criteria

No spawned agent should receive abbreviated instructions.

----------

# LEVEL 2 — GROUP WORKER AGENTS (8 PARALLEL AGENTS)

Each group worker agent receives:

* a subset of Sugarcraft package/app dirs

Example:

* Group 1 gets 12 packages
* Group 2 gets 11 packages
* etc.

These 8 group worker agents execute SIMULTANEOUSLY.

However:

WITHIN EACH GROUP:
processing MUST be STRICTLY SEQUENTIAL.

---

# CRITICAL RULE — GROUP WORKERS ARE ORCHESTRATORS ONLY

THIS IS A HARD REQUIREMENT.

Group worker agents are NOT allowed to perform package analysis themselves.

Group worker agents MUST NOT:

* inspect repo internals in depth
* analyze implementation details
* compare third-party repos directly
* evaluate PRs/issues/discussions themselves
* generate final package reports themselves
* flatten work into the group worker context
* batch multiple packages into one analysis pass
* read entire codebases for assigned packages
* perform feature-gap analysis directly
* perform architecture analysis directly
* perform ecosystem comparison directly

The ONLY responsibility of a group worker agent is orchestration and sequencing.

A group worker agent acts purely as a dispatcher/coordinator.

---

# REQUIRED GROUP WORKER EXECUTION MODEL

For EACH assigned package/app:

The group worker agent MUST:

1. Identify the NEXT package/app in sequence
2. Spawn EXACTLY ONE dedicated package-analysis subagent
3. Pass the FULL inherited worker prompt to that subagent
4. Pass ONLY that SINGLE package/app as the assigned target
5. Wait for the subagent to COMPLETE fully
6. Collect/store the resulting output references
7. Move to the NEXT package/app
8. Repeat until all assigned packages/apps are complete

The group worker itself MUST NOT perform the analysis work.

The package-analysis subagent performs ALL actual investigation and analysis.

---

# STRICT "ONE PACKAGE = ONE SUBAGENT" RULE

Every individual Sugarcraft package/app MUST receive its OWN dedicated package-analysis subagent.

MANDATORY:

* 1 package/app
* 1 dedicated subagent
* 1 isolated analysis context
* 1 generated report

FORBIDDEN:

* one subagent handling multiple packages
* one group worker directly analyzing multiple packages
* merging package analyses into a single context
* "lightweight" inline analysis by the group worker
* partial analysis before spawning the subagent
* skipping subagent creation for "simple" packages
* directly reading/analyzing all repos from the group worker

Even very small/simple packages MUST receive their OWN dedicated analysis subagent.

---

# REQUIRED GROUP WORKER BEHAVIOR EXAMPLE

CORRECT:

Group Worker 3:

* spawn package-analysis agent for `sugarcraft_terminal-ui`
* WAIT for completion
* collect result
* spawn package-analysis agent for `sugarcraft_renderer`
* WAIT for completion
* collect result
* spawn package-analysis agent for `sugarcraft_cli`
* WAIT for completion

INCORRECT:

Group Worker 3:

* reads/analyzes all 15 assigned repos itself
* performs direct comparisons itself
* generates all reports itself
* only spawns agents occasionally
* batches multiple repos into one agent
* performs "pre-analysis" before spawning agents

The above behavior is STRICTLY FORBIDDEN.

---

# GROUP WORKER MEMORY / CONTEXT CONSTRAINT

The reason for this architecture is to prevent:

* context overload
* cross-package contamination
* reduced analysis quality
* lost details
* hallucinated comparisons
* incomplete ecosystem analysis
* degraded long-context performance

Therefore:

The group worker agent MUST keep minimal active context.

It should maintain ONLY:

* orchestration state
* queue state
* completed package list
* generated output references

ALL deep analysis context MUST exist ONLY inside the dedicated package-analysis subagent.

---

# REQUIRED SEQUENTIAL PROCESSING INSIDE EACH GROUP

A group worker agent MUST:

1. Take the FIRST package/app in its assigned group
2. Spawn ONE dedicated package-analysis subagent
3. Wait until that subagent COMPLETES fully
4. Collect/store results
5. Move to the NEXT package/app
6. Spawn the next dedicated package-analysis subagent
7. Wait for completion
8. Repeat until all assigned packages/apps are finished

DO NOT process multiple packages/apps simultaneously inside a group.

DO NOT spawn multiple package-analysis agents at once inside the same group worker.

DO NOT directly analyze repositories inside the group worker itself.

The execution model is:

* 8 parallel group workers globally
* BUT sequential package processing locally within each group
* AND all actual analysis performed ONLY by dedicated package-analysis subagents

Example:

Group Worker 1:

* spawn lib1 agent
* wait
* collect result
* spawn lib2 agent
* wait
* collect result
* spawn lib3 agent
* wait
* collect result

Group Worker 2:

* spawn appA agent
* wait
* collect result
* spawn appB agent
* wait
* collect result

These groups run concurrently with each other, but each individual group remains synchronous/sequential internally.

----------

# LEVEL 3 — PACKAGE ANALYSIS SUBAGENTS

Each package-analysis subagent handles EXACTLY ONE Sugarcraft package/app.

The subagent MUST:

-   perform all required repo analysis
-   perform all third-party comparison analysis
-   perform all PR/issue/discussion analysis
-   generate:
    -   `docs/repo_map/update_sugarcraft_<dir>.md`

The subagent then terminates.

----------

# LEVEL 4 — FINAL AGGREGATION AGENT

ONLY AFTER:
ALL 8 group worker agents have fully completed:

The master agent MUST spawn ONE final aggregation agent.

The aggregation agent MUST:

1.  Read ALL:
    -   `docs/repo_map/update_sugarcraft_*.md`
2.  Generate:
    -   `docs/repo_map_update.md`
3.  Produce:

-   global findings
-   repeated architectural gaps
-   repeated feature gaps
-   shared opportunities
-   ecosystem-wide priorities
-   organization-wide recommendations
-   cross-project consolidation opportunities

----------

# STRICT CONCURRENCY RULES

MANDATORY EXECUTION MODEL:

-   1 master agent
-   8 parallel group worker agents
-   sequential package processing INSIDE each group
-   1 dedicated analysis subagent per package/app
-   1 final aggregation agent after everything completes

FORBIDDEN:

-   flattening the hierarchy
-   processing all packages in one agent
-   spawning unlimited parallel package-analysis agents
-   skipping the aggregation phase
-   using abbreviated prompts for child agents
-   processing packages concurrently within a single group

The concurrency structure is intentionally designed to:

-   maximize parallelism safely
-   reduce context overload
-   maintain deterministic execution order
-   improve output consistency
-   reduce agent context degradation
-   improve orchestration reliability

# REQUIRED OUTPUT FILE

For each Sugarcraft dir create:

* `docs/repo_map/update_sugarcraft_<dir>.md`

Example:

* `docs/repo_map/update_sugarcraft_candy-core.md`

---

# REQUIRED OUTPUT FORMAT

The update file MUST be EXTREMELY detailed.

Use this structure:

# Overview

* Summary of analyzed package/app
* Overall ecosystem positioning
* Biggest opportunity areas
* Biggest missing capabilities

# Internal Capability Summary

Detailed breakdown of:

* current architecture
* current features
* APIs
* rendering systems
* extension systems
* strengths
* weaknesses

# Relevant External Repositories

Table:

* Repo
* Relevance
* Major applicable concepts
* Priority level

# Feature Gap Analysis

Categorized by:

* critical
* high value
* medium
* low priority

For each item include:

* title
* description
* why it matters
* source repo
* source PR/issue/discussion if applicable
* implementation ideas
* estimated complexity
* expected impact

# Algorithm / Performance Opportunities

Include:

* current approach
* external approach
* why external approach is better
* tradeoffs
* applicability

# Architecture Improvements

# API / Developer Experience Improvements

# Documentation / Cookbook Opportunities

# UX / TUI Improvements

# Testing / Reliability Improvements

# Ecosystem / Integration Opportunities

# Notable PRs / Issues / Discussions

Include:

* summaries
* relevance
* lessons learned
* potential adaptations

# Recommended Roadmap

Categorize:

* immediate wins
* medium-term improvements
* major architectural upgrades
* experimental ideas

# Priority Matrix

Table:

* Opportunity
* Impact
* Complexity
* Risk
* Recommended Priority

# Final Strategic Assessment

Detailed concluding analysis.

---

# FINAL AGGREGATION STEP

After ALL group workers complete:

The master agent MUST:

1. Spawn ONE final aggregation agent.

2. The aggregation agent MUST:

   * Read ALL:

     * `docs/repo_map/update_sugarcraft_*.md`

3. Generate:

   * `docs/repo_map_update.md`

---

# FINAL MASTER REPORT REQUIREMENTS

The master report should contain:

# Global Ecosystem Summary

# Cross-Repo Common Weaknesses

# Most Requested Missing Features

# Shared Architectural Opportunities

# Shared Performance Opportunities

# Shared DevEx Problems

# Shared Documentation Gaps

# Shared UX/TUI Gaps

# Shared Testing/Reliability Problems

# Shared Integration Opportunities

# Most Valuable Third-Party Repositories

Rank by:

* ecosystem value
* innovation
* applicability
* architectural quality

# Most Valuable PRs / Discussions

# Global Priority Matrix

# Suggested Organization-Wide Roadmap

# Suggested Shared Internal Frameworks

# Suggested Shared Components/Abstractions

# Potential Consolidation Opportunities

# Repeated Reinventions Across Sugarcraft Packages

# Areas Where Sugarcraft Is Already Superior

# Strategic Recommendations

---

# IMPORTANT EXECUTION RULES

* Be exhaustive.
* Prefer depth over brevity.
* Extract concrete implementation details.
* Focus heavily on actionable engineering insights.
* Include specific algorithms/patterns when possible.
* Include source references everywhere.
* Avoid generic observations.
* Distinguish between:

  * theoretical improvements
  * production-proven approaches
  * experimental ideas
* Pay special attention to:

  * performance
  * TUI rendering
  * async/event systems
  * virtualization
  * terminal handling
  * extensibility
  * developer ergonomics
  * examples/cookbooks
  * AI/agent integrations
  * MCP ecosystem compatibility

---

# CONCURRENCY RULES

STRICTLY FOLLOW:

* 1 master agent
* 8 parallel group worker agents
* each group worker processes Sugarcraft dirs sequentially
* each Sugarcraft dir uses exactly 1 dedicated analysis subagent
* aggregation happens ONLY after all workers complete

Do NOT flatten the hierarchy.
Do NOT process everything in one agent.
Do NOT skip the aggregation phase.

---

# QUALITY BAR

The final reports should resemble:

* senior staff engineer architecture reviews
* ecosystem competitiveness reports
* platform modernization plans
* deep technical gap analyses
* product/platform strategic assessments

The output should be useful for:

* roadmap planning
* architectural redesign
* feature prioritization
* performance initiatives
* ecosystem strategy
* developer experience improvements
* platform consolidation efforts
