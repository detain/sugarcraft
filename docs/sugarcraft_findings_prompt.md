Perform a comprehensive monorepo-wide architectural and implementation audit.

This repository is a monorepo containing many subdirectories where most apps/libs/packages are effectively independent projects with their own responsibilities, APIs, internal architecture, and operational concerns.

Automatically detect and enumerate all major packages/apps/libs/services/modules in the repository and treat each as its own independently reviewable unit.

For each package/app/lib:

* Spawn a dedicated review subagent focused exclusively on that package.
* Run all package review subagents in parallel for maximum coverage and throughput.
* Each subagent should deeply inspect its assigned package and generate a detailed findings report.

The review should focus on correctness, completeness, maintainability, architectural quality, production readiness, consistency, scalability, and ecosystem opportunities.

The review should identify issues including, but not limited to:

* Bugs and correctness issues
* Broken edge cases
* Invalid assumptions
* Incomplete implementations
* Stubbed or placeholder logic
* TODO/FIXME/HACK markers indicating unfinished work
* Features partially implemented but not completed
* Features exposed in configs/APIs/UI but not fully wired up
* Dead code or obsolete compatibility layers
* Duplicate or fragmented implementations
* Weak abstractions
* Architectural inconsistencies
* Poor separation of concerns
* Tight coupling between packages
* Hidden dependency problems
* Circular dependencies
* Configuration drift
* Runtime safety concerns
* Error handling weaknesses
* Retry/fallback deficiencies
* Missing validation
* Security concerns
* Unsafe defaults
* Missing observability/logging/metrics/tracing
* Operational risk areas
* Scalability bottlenecks
* Memory/performance inefficiencies
* Excessive complexity
* Maintainability concerns
* Poor developer ergonomics
* API inconsistencies
* Documentation drift
* Mismatch between docs/specs/comments and actual implementation
* Features documented but missing
* Features claimed but incomplete
* Incorrect or outdated documentation
* Missing tests or weak test coverage
* Weak migration/backward compatibility handling
* Areas likely to fail under production load or failure scenarios
* Code that technically works but violates intended architecture/design principles

Additionally, each subagent should analyze the broader ecosystem of similar packages/libs/apps both inside this monorepo and conceptually comparable external projects/patterns.

Look for:

* Valuable features present in similar libs/packages that this package lacks
* Industry-standard capabilities missing from the implementation
* Missing quality-of-life functionality
* Missing operational tooling
* Missing debugging or observability capabilities
* Missing configuration flexibility
* Missing extensibility/plugin systems
* Missing automation opportunities
* Missing testing infrastructure
* Missing developer tooling
* Missing safety mechanisms
* Missing resilience/failure recovery features
* Missing caching/indexing/optimization strategies
* Missing interoperability features
* Missing APIs/hooks/events/extensions
* Areas where another internal package already solved the same problem better
* Features implemented inconsistently across packages that should be standardized
* Reusable functionality that should be extracted/shared
* Packages that overlap heavily and should potentially be unified or consolidated
* Existing architecture patterns elsewhere in the monorepo that should be adopted here

Each subagent must generate a detailed structured report including:

* Executive summary
* Package purpose/responsibility analysis
* Architectural observations
* Severity-ranked findings
* Incomplete/missing functionality
* Documentation inconsistencies
* Suggested improvements
* Missing features/opportunities
* Cross-package standardization opportunities
* Refactor recommendations
* Performance/scalability concerns
* Operational concerns
* Maintainability concerns
* Technical debt assessment
* Suggested roadmap/priorities

After all subagents complete:

1. Collect all findings and reports.
2. Consolidate and deduplicate overlapping findings.
3. Identify cross-package patterns and systemic architectural issues.
4. Identify recurring anti-patterns and repeated technical debt.
5. Identify opportunities for shared infrastructure, standardization, and consolidation.
6. Generate a single comprehensive repository-wide report named:

`sugarcrash_findings.md`

The generated report should contain:

* Repository-wide executive summary
* Package-by-package findings
* Cross-package findings
* Shared infrastructure opportunities
* Standardization recommendations
* Technical debt overview
* Missing capabilities/features overview
* Architecture consistency analysis
* Documentation consistency analysis
* Prioritized remediation roadmap
* High-risk areas
* Quick wins
* Long-term strategic improvements
* Suggested package consolidation opportunities
* Suggested reusable shared abstractions/utilities
* Suggested testing/CI improvements
* Suggested operational improvements
* Suggested developer experience improvements

The final report should be extremely detailed and actionable, suitable for use as a master remediation and modernization planning document for the entire monorepo.

Be highly critical and skeptical during review. Assume architectural drift, incomplete implementations, duplicated solutions, and documentation divergence are likely unless disproven.
