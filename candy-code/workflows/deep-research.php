<?php

declare(strict_types=1);

use SugarCraft\Code\Workflows\TaskBuilder;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;

/**
 * Deep Research Workflow
 *
 * Coordinates multiple specialized agents to investigate a topic thoroughly,
 * gathering information from documentation, code analysis, web search, and
 * expert sources, then synthesizing findings into a comprehensive report.
 *
 * Workflow stages:
 *  1. plan        - Planner breaks down the research question into investigation areas
 *  2. investigate - Parallel researchers explore docs, code, pitfalls, and integration
 *  3. synthesize  - Synthesizer collects findings and names the gaps, one GAP: line each
 *  4. follow_up   - ONE extra research round, run only when synthesize named a gap
 *  5. report      - Produces structured report with findings, examples, and references
 *
 * Shape notes:
 *  - Task names are snake_case so every result is addressable as
 *    `{{name.results}}`; a hyphenated name is recorded but can never be read
 *    back (docs/WORKFLOWS.md, "What {{name.results}} is named by").
 *  - `investigate` is captured (`capture: true`): downstream prompts get the
 *    capture file's path plus a 2 KiB preview instead of four full reports,
 *    and the readers carry `Read` to open the file. `synthesize` and `report`
 *    reach earlier stages through `input:` joins rather than pasting
 *    `{{stage.output}}` into each prompt.
 *  - `follow_up` is gated by `when:` and is a single bounded round, not a
 *    loop: `loop:` re-runs plain stages only, and the research it would
 *    repeat is a parallel stage. A follow-up the gate skips records nothing,
 *    so the report's join simply omits it.
 *  - `report` returns the report as its answer; it holds `Read` only (to
 *    open the capture file), never `Write`/`Edit`.
 *
 * @see SugarCraft\Code\Workflows\WorkflowRegistry
 */
return (new WorkflowBuilder())
    ->name('deep-research')
    ->description('Deep research workflow that coordinates multiple specialized agents to investigate a topic thoroughly and produce a comprehensive report')
    ->stage(
        'plan',
        Tasks::agent('planner')
            ->name('research_planner')
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
                ->name('docs_explorer')
                ->prompt(
                    'You are a documentation researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate official documentation, tutorials, and reference materials for: {{question}}. ' .
                    'Search for official docs, API references, guides, and examples. ' .
                    'Document key findings, important caveats, and areas where documentation is unclear or missing.'
                )
                ->tools(['Read', 'Grep', 'Glob', 'WebFetch'])
                ->timeout(900),

            Tasks::agent('researcher')
                ->name('code_analyzer')
                ->prompt(
                    'You are a code analysis researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate example code, real-world usage patterns, and implementation details for: {{question}}. ' .
                    'Search for open source projects, GitHub examples, and code samples. ' .
                    'Analyze how the technology is actually used in practice, not just how it is documented.'
                )
                ->tools(['Read', 'Grep', 'Glob', 'Bash'])
                ->timeout(900),

            Tasks::agent('researcher')
                ->name('pitfalls_researcher')
                ->prompt(
                    'You are a pitfalls researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate common pitfalls, anti-patterns, and mistakes to avoid with: {{question}}. ' .
                    'Search for StackOverflow discussions, bug reports, security advisories, and ' .
                    'lessons-learned posts. Document what goes wrong and how to prevent it.'
                )
                ->tools(['Read', 'Grep', 'WebFetch'])
                ->timeout(900),

            Tasks::agent('researcher')
                ->name('integration_researcher')
                ->prompt(
                    'You are an integration researcher. Using the research plan from {{plan.output}}, ' .
                    'investigate integration challenges, compatibility concerns, and ecosystem tooling ' .
                    'for: {{question}}. Look for: library compatibility, version requirements, ' .
                    'configuration complexity, deployment considerations, and interoperability with ' .
                    'other popular tools in the ecosystem.'
                )
                ->tools(['Read', 'Grep', 'Glob', 'Bash'])
                ->timeout(900),
        ],
        capture: true,
    )
    ->stage(
        'synthesize',
        Tasks::agent('synthesizer')
            ->name('findings_synthesizer')
            ->prompt(
                'You are a research synthesizer. Review all findings from the parallel research stage ' .
                'and synthesize them into a coherent picture of: {{question}}.\n\n' .
                'The research stage\'s output was captured to a file; below is its path and a preview. ' .
                'Read the file for the full findings.\n\n{{input}}\n\n' .
                'Your task:\n' .
                '1. Identify key themes and patterns across all research areas\n' .
                '2. Note where findings confirm each other or conflict\n' .
                '3. Identify gaps where more research is needed\n' .
                '4. Flag any assumptions that may need validation\n' .
                '5. Determine if the original research question needs refinement\n\n' .
                'Format your response to highlight consensus, conflicts, and suggested follow-ups. ' .
                'List every gap that needs more research as its own line starting with "GAP: ", ' .
                'and write no line starting with "GAP: " when nothing is missing.'
            )
            ->input(['from' => ['investigate'], 'as' => 'all'])
            ->tools(['Read', 'Grep'])
            ->timeout(600)
    )
    ->stage(
        'follow_up',
        Tasks::agent('researcher')
            ->name('gap_researcher')
            ->prompt(
                'You are a follow-up researcher on: {{question}}.\n\n' .
                'The synthesis below names the gaps the first research round left open, ' .
                'one per line starting with "GAP: ". Investigate each gap and report what you found, ' .
                'gap by gap; say plainly when a gap stays open.\n\n{{synthesize.output}}'
            )
            ->tools(['Read', 'Grep', 'Glob', 'WebFetch'])
            ->timeout(900)
    )
    ->when(['stage' => 'synthesize', 'matches' => '/^GAP: /m'])
    ->stage(
        'report',
        Tasks::agent('scribe')
            ->name('report_writer')
            ->prompt(
                'You are a technical writer producing a comprehensive research report on: {{question}}.\n\n' .
                'Below are the research plan, the research findings (captured to a file: Read it for the full text), ' .
                'the synthesis, and, when one ran, the follow-up round:\n\n{{input}}\n\n' .
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
                'Be thorough but concise. Include realistic code examples. ' .
                'Return the report as your answer; do not write it to a file.'
            )
            ->input(['from' => ['plan', 'investigate', 'synthesize', 'follow_up'], 'as' => 'all'])
            ->tools(['Read'])
            ->timeout(900)
    )
    ->maxConcurrent(5)
    ->timeout(3600)
    ->build();
