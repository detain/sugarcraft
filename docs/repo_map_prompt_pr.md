Use this as the second-stage orchestration prompt that runs AFTER the initial repo comparison pass has completed.

---

You are performing a second-stage ecosystem intelligence and upstream monitoring analysis across all third-party repositories referenced in the monorepo ecosystem mapping.

The first analysis pass has already completed and produced:

* `repo_map.md`
* `repo_map/<org>_<repo>.md`
* `repo_map/unmatched.md`
* various `repo_map/sugarcraft_*.md` reports

Your task is now to mine the upstream repositories themselves for:

* recurring problems
* architectural pain points
* feature demand
* rejected ideas
* accepted ideas
* roadmap direction
* performance bottlenecks
* security concerns
* maintainability concerns
* usability complaints
* extensibility limitations
* plugin ecosystem requests
* migration pain
* API complaints
* configuration complaints
* implementation workarounds
* clever fixes
* emerging patterns
* future opportunities

This pass is intended to discover:

* problems we may also have
* improvements we should proactively implement
* ideas worth adopting
* ecosystem trends
* design mistakes to avoid
* high-value feature opportunities
* hidden implementation insights

---

# INITIALIZATION

First:

1. Read:

   * `repo_map.md`
   * all existing `repo_map/*.md` reports
   * especially all:

     * `repo_map/<org>_<repo>.md`
     * `repo_map/sugarcraft_*.md`
     * `repo_map/unmatched.md`

2. Build a complete inventory of all third-party repositories.

3. Determine:

   * org name
   * repo name
   * mapped Sugarcraft target(s)
   * unmatched status
   * category/domain
   * ecosystem relevance

---

# EXECUTION MODEL

Spawn subagents in parallel.

## Concurrency Rules

* Run agents in groups of 15
* Maintain rolling execution until all repositories are processed
* Retry failed agents automatically
* Track completion state continuously

---

# AGENT RESPONSIBILITIES

Spawn one agent per third-party repository.

Each agent is responsible for ONE upstream repository.

---

# STEP 1 — READ EXISTING ANALYSIS

The agent MUST first read the entire existing report for its repository:

`repo_map/<org>_<repo>.md`

This context is mandatory.

The agent must understand:

* what the repository does
* how it works
* what Sugarcraft equivalent(s) exist
* previous gap analysis
* prior recommendations
* previously identified weaknesses
* previously identified strengths

Do NOT duplicate prior analysis unnecessarily.

This phase extends and enriches it.

---

# STEP 2 — MINE ISSUES / PRS / DISCUSSIONS

The agent must thoroughly inspect:

## GitHub Issues

Both:

* open issues
* closed issues

Look for:

* recurring bugs
* architectural limitations
* scaling problems
* API pain points
* UX complaints
* performance problems
* memory leaks
* concurrency issues
* edge cases
* migration problems
* configuration confusion
* integration pain
* extensibility requests
* plugin limitations
* maintainability complaints

Pay special attention to:

* highly reacted issues
* long-running issues
* recurring issue themes
* “known limitations”
* issues repeatedly reopened
* issues with workaround discussions

---

## Pull Requests

Inspect:

* merged PRs
* rejected PRs
* abandoned PRs
* controversial PRs

Identify:

* important new features
* architectural shifts
* refactors
* optimization work
* major bug fixes
* ecosystem adaptations
* modernization efforts
* breaking changes
* future direction indicators

Analyze:

* why changes were made
* tradeoffs discussed
* reviewer concerns
* maintainability implications
* performance implications

Especially look for:

* clever implementation details
* rejected approaches worth reconsidering
* features users strongly wanted
* technical debt indicators

---

## Discussions / Forums / RFCs

If discussions exist, inspect them thoroughly.

Look for:

* roadmap direction
* community pain points
* requested integrations
* plugin requests
* future architecture plans
* ecosystem dissatisfaction
* common confusion
* abandoned ideas
* frequently requested features

Pay attention to:

* what maintainers repeatedly refuse
* what maintainers repeatedly recommend
* common workarounds
* unofficial best practices

These often expose opportunities not visible in the code itself.

---

# STEP 3 — DETERMINE IMPACT ON SUGARCRAFT

For every meaningful finding, determine:

## Direct Risk

Could this same issue affect Sugarcraft?

Examples:

* architectural scaling problems
* rendering bottlenecks
* cache invalidation problems
* state synchronization issues
* memory growth
* extensibility limitations
* API complexity

---

## Feature Opportunities

Should Sugarcraft implement similar features?

Examples:

* highly requested capabilities
* plugins/extensions
* workflow improvements
* UX enhancements
* automation features
* debugging tooling
* observability
* customization

---

## Strategic Opportunities

Can Sugarcraft:

* solve the problem better?
* generalize the concept?
* create reusable infrastructure?
* provide superior DX?
* unify fragmented approaches?
* create composable primitives?

---

## Defensive Lessons

What mistakes should we avoid?

Examples:

* overcomplicated APIs
* brittle abstractions
* poor plugin boundaries
* scaling traps
* migration disasters
* excessive configuration complexity

---

# STEP 4 — IDENTIFY RECURRING THEMES

Agents should actively look for:

* repeated complaints across ecosystems
* repeated feature requests
* repeated architectural pain points
* repeated scalability issues
* repeated UX frustrations
* repeated customization limitations

Flag these prominently.

Cross-ecosystem repetition strongly indicates:

* high-value opportunities
* common industry pain
* strategic areas worth investing in

---

# STEP 5 — PRODUCE REPORT

Write findings to:

`repo_map/pr_<org>_<repo>.md`

Examples:

* `repo_map/pr_facebook_react.md`
* `repo_map/pr_vercel_nextjs.md`

These are NOT simple issue summaries.

They are ecosystem intelligence reports.

---

# REQUIRED REPORT STRUCTURE

Each report should contain sections similar to:

# Repository Overview

# Existing Sugarcraft Mapping

# Previously Identified Gaps

# High-Signal Open Issues

# Important Closed Issues

# Recurring Pain Points

# Frequently Requested Features

# Important PRs

# Architectural Changes

# Performance Discussions

# Extensibility Discussions

# API/UX Complaints

# Migration Problems

# Clever Fixes & Workarounds

# Community Workarounds

# Maintainer Guidance Patterns

# Rejected Ideas Worth Revisiting

# Problems Likely Relevant To Sugarcraft

# Features Sugarcraft Should Consider

# Architectural Lessons

# Defensive Design Lessons

# Ecosystem Trends

# Strategic Opportunities

# Cross-Ecosystem Pattern Matches

# High ROI Recommendations

---

# ANALYSIS QUALITY BAR

Agents must:

* avoid shallow summarization
* avoid generic issue recaps
* avoid listing issues without analysis
* synthesize patterns
* infer strategic meaning
* extract implementation lessons
* identify ecosystem signals

Think like:

* framework maintainers
* ecosystem strategists
* OSS architects
* performance engineers
* product/platform designers

combined together.

---

# IMPORTANT

Do NOT merely summarize issues and PRs.

You are extracting:

* actionable engineering intelligence
* ecosystem direction
* architectural lessons
* innovation opportunities
* strategic warnings

The goal is to help Sugarcraft:

* evolve faster
* avoid ecosystem mistakes
* adopt valuable ideas early
* recognize recurring industry pain points
* build better abstractions
* identify strategic opportunities before competitors

---

# FINAL ORCHESTRATION REQUIREMENTS

Track:

* spawned agents
* active agents
* completed agents
* failed agents
* generated report files

Retry failures automatically.

At completion:

1. Verify every third-party repo has:

   * a base report
   * a PR/issues/discussions intelligence report

2. Verify all report files exist.

3. Produce a final ecosystem intelligence summary including:

   * most recurring pain points across ecosystems
   * most requested feature categories
   * most common architectural failures
   * most valuable innovation opportunities
   * most dangerous implementation traps
   * strongest recurring UX complaints
   * repeated extensibility limitations
   * repeated scalability bottlenecks
   * recurring maintainability problems
   * high-ROI opportunities for Sugarcraft
   * strategic ecosystem trends
