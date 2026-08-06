<?php

declare(strict_types=1);

use SugarCraft\Crush\Workflows\TaskBuilder;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;

/**
 * Deep Research Workflow
 *
 * Coordinates multiple specialized agents to investigate a topic thoroughly,
 * gathering information from documentation, code analysis, web search, and
 * expert sources, then synthesizing findings into a comprehensive report.
 *
 * Workflow stages:
 *  1. plan       - Planner breaks down the research question into investigation areas
 *  2. research  - Parallel researchers explore docs, code, pitfalls, and integration
 *  3. synthesize - Synthesizer collects findings and identifies gaps/conflicts
 *  4. report     - Produces structured report with findings, examples, and references
 *  5. refine     - Iterative re-planning if original assumptions were incorrect
 *
 * @see SugarCraft\Crush\Workflows\WorkflowRegistry
 */
return (new WorkflowBuilder())
    ->name('deep-research')
    ->description('Deep research workflow that coordinates multiple specialized agents to investigate a topic thoroughly and produce a comprehensive report')
    ->stage(
        'plan',
        Tasks::agent('planner')
            ->name('research-planner')
            ->prompt(
                'You are a research planner. Break down the research question "{{question}}" into ' .
                'specific areas to investigate. Identify: 1) key concepts that need understanding, ' .
                '2) questions that need answers, 3) sources that need checking, 4) dependencies ' .
                'between topics (some things must be understood before others). ' .
                'Format your response as a structured research plan with clear areas for parallel investigation.'
            )
            ->tools(['Read', 'Grep', 'Glob', 'Bash'])
            ->timeout(600)
    )
    ->parallel(
        'investigate',
        [
            Tasks::agent('researcher')
                ->name('docs-explorer')
                ->prompt(
                    'You are a documentation researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate official documentation, tutorials, and reference materials for: {{question}}. ' .
                    'Search for official docs, API references, guides, and examples. ' .
                    'Document key findings, important caveats, and areas where documentation is unclear or missing.'
                )
                ->tools(['Read', 'Grep', 'Glob', 'WebFetch'])
                ->timeout(900),

            Tasks::agent('researcher')
                ->name('code-analyzer')
                ->prompt(
                    'You are a code analysis researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate example code, real-world usage patterns, and implementation details for: {{question}}. ' .
                    'Search for open source projects, GitHub examples, and code samples. ' .
                    'Analyze how the technology is actually used in practice, not just how it is documented.'
                )
                ->tools(['Read', 'Grep', 'Glob', 'Bash'])
                ->timeout(900),

            Tasks::agent('researcher')
                ->name('pitfalls-researcher')
                ->prompt(
                    'You are a pitfalls researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate common pitfalls, anti-patterns, and mistakes to avoid with: {{question}}. ' .
                    'Search for StackOverflow discussions, bug reports, security advisories, and ' .
                    'lessons-learned posts. Document what goes wrong and how to prevent it.'
                )
                ->tools(['Read', 'Grep', 'WebFetch'])
                ->timeout(900),

            Tasks::agent('researcher')
                ->name('integration-researcher')
                ->prompt(
                    'You are an integration researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate integration challenges, compatibility concerns, and ecosystem tooling ' .
                    'for: {{question}}. Look for: library compatibility, version requirements, ' .
                    'configuration complexity, deployment considerations, and interoperability with ' .
                    'other popular tools in the ecosystem.'
                )
                ->tools(['Read', 'Grep', 'Glob', 'Bash'])
                ->timeout(900),
        ]
    )
    ->stage(
        'synthesize',
        Tasks::agent('synthesizer')
            ->name('findings-synthesizer')
            ->prompt(
                'You are a research synthesizer. Review all findings from the parallel research stage ' .
                'and synthesize them into a coherent picture of: {{question}}.\n\n' .
                'Research findings:\n{{investigate.output}}\n\n' .
                'Your task:\n' .
                '1. Identify key themes and patterns across all research areas\n' .
                '2. Note where findings confirm each other or conflict\n' .
                '3. Identify gaps where more research is needed\n' .
                '4. Flag any assumptions that may need validation\n' .
                '5. Determine if the original research question needs refinement\n\n' .
                'Format your response to highlight consensus, conflicts, gaps, and suggested follow-ups.'
            )
            ->tools(['Read', 'Grep'])
            ->timeout(600)
    )
    ->stage(
        'report',
        Tasks::agent('scribe')
            ->name('report-writer')
            ->prompt(
                'You are a technical writer producing a comprehensive research report on: {{question}}.\n\n' .
                'Research plan: {{plan.output}}\n\n' .
                'Research findings: {{investigate.output}}\n\n' .
                'Synthesis: {{synthesize.output}}\n\n' .
                'Produce a structured report with these sections:\n\n' .
                '## Background and Context\n' .
                'What is this technology/concept? Why does it matter?\n\n' .
                '## Key Findings\n' .
                'Organized by topic, with code examples where relevant.\n\n' .
                '## Code Examples\n' .
                'Practical demonstrations of key concepts.\n\n' .
                '## Common Pitfalls and How to Avoid Them\n' .
                'Documented mistakes and their solutions.\n\n' .
                '## Integration and Ecosystem\n' .
                'Compatibility notes and tooling recommendations.\n\n' .
                '## References and Further Reading\n' .
                'Links to docs, tutorials, and relevant projects.\n\n' .
                '## Open Questions and Research Gaps\n' .
                'Areas that need more investigation.\n\n' .
                'Be thorough but concise. Include realistic code examples.'
            )
            ->tools(['Read', 'Write', 'Edit'])
            ->timeout(900)
    )
    ->stage(
        'refine',
        Tasks::agent('planner')
            ->name('refinement-planner')
            ->prompt(
                'You are a research refinement planner. Review the synthesized findings and report for: {{question}}.\n\n' .
                'Synthesis: {{synthesize.output}}\n\n' .
                'Report: {{report.output}}\n\n' .
                'Determine if the original research assumptions were correct. If the findings reveal ' .
                'that the original question was based on incorrect assumptions or missed the real issue, ' .
                'propose a refined research question that better addresses what was actually discovered.\n\n' .
                'Output one of:\n' .
                '1. "RESEARCH COMPLETE" if the findings adequately answer the original question\n' .
                '2. A refined research question if the original assumptions were wrong, along with ' .
                '   specific areas that need re-investigation\n\n' .
                'This stage enables iterative refinement when initial assumptions prove incorrect.'
            )
            ->tools(['Read', 'Grep'])
            ->timeout(300)
    )
    ->maxConcurrent(5)
    ->timeout(3600)
    ->build();
