<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use Symfony\Component\Yaml\Yaml;

/**
 * Discovers and loads workflow definitions from PHP DSL files and YAML files.
 *
 * Workflows are defined as PHP files that return a Workflow object:
 *
 *   // ~/.sugar-crush/workflows/refactor-service.php
 *   <?php
 *   return (new WorkflowBuilder())
 *       ->name('refactor-service')
 *       ->description('Refactor a microservice with tests and docs')
 *       ->stage('analyze', Tasks::agent('architect')->prompt('Analyze the service'))
 *       ->build();
 *
 * The registry maintains an in-memory map for session-registered workflows
 * and delegates to the filesystem for persisted workflow definitions.
 */
final class WorkflowRegistry
{
    /** @var array<string, Workflow> */
    private array $registered = [];

    public function __construct(
        private readonly string $workflowsPath = '~/.sugar-crush/workflows/',
    ) {}

    /**
     * Load a workflow by name from the filesystem or registered sessions.
     *
     * Tries .php first, then .yaml if no PHP file exists.
     *
     * @throws WorkflowNotFoundException When the workflow file does not exist.
     * @throws WorkflowLoadException When the file does not return a Workflow instance.
     */
    public function load(string $name): Workflow
    {
        // Check in-memory registry first
        if (isset($this->registered[$name])) {
            return $this->registered[$name];
        }

        // Try PHP file first
        $phpPath = $this->resolvePhpPath($name);
        if (file_exists($phpPath)) {
            $workflow = require $phpPath;

            if (!$workflow instanceof Workflow) {
                throw new WorkflowLoadException(
                    "Workflow file {$phpPath} must return a Workflow instance, got " . get_debug_type($workflow)
                );
            }

            return $workflow;
        }

        // Fall back to YAML
        return $this->loadYaml($name);
    }

    /**
     * Load a workflow from a YAML file.
     *
     * @throws WorkflowNotFoundException When the YAML file does not exist.
     * @throws WorkflowLoadException When the YAML data is invalid.
     */
    public function loadYaml(string $name): Workflow
    {
        $path = $this->resolveYamlPath($name);

        if (!file_exists($path)) {
            throw new WorkflowNotFoundException(
                "Workflow '{$name}' not found at {$path}"
            );
        }

        $data = Yaml::parseFile($path);

        if (!is_array($data)) {
            throw new WorkflowLoadException(
                "Workflow YAML {$path} must contain a valid workflow definition"
            );
        }

        return $this->parseYamlWorkflow($data);
    }

    /**
     * List all available workflow names from the filesystem.
     *
     * Returns base names of .php and .yaml files in the workflows directory,
     * excluding hidden files and directories.
     *
     * @return string[]
     */
    public function list(): array
    {
        $expandedPath = $this->expandPath($this->workflowsPath);

        if (!is_dir($expandedPath)) {
            return [];
        }

        $files = scandir($expandedPath);
        if ($files === false) {
            return [];
        }

        $names = [];
        foreach ($files as $file) {
            // Skip hidden files, '.', and '..'
            if ($file[0] === '.') {
                continue;
            }

            if (str_ends_with($file, '.php')) {
                $names[] = basename($file, '.php');
            } elseif (str_ends_with($file, '.yaml')) {
                $names[] = basename($file, '.yaml');
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Register a workflow in-memory for the current session.
     *
     * Registered workflows take precedence over filesystem workflows
     * when using load().
     */
    public function register(Workflow $workflow): void
    {
        $this->registered[$workflow->name] = $workflow;
    }

    /**
     * Resolve a workflow by matching a task description against registered workflows.
     *
     * Performs a case-insensitive substring match of the description against
     * workflow names and descriptions.
     *
     * @return Workflow|null The best match or null if no workflow matches.
     */
    public function resolve(string $taskDescription): ?Workflow
    {
        $lowerDescription = strtolower($taskDescription);

        $bestMatch = null;
        $bestScore = 0;

        // Check registered workflows first
        foreach ($this->registered as $workflow) {
            $score = $this->matchScore($workflow, $lowerDescription);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $workflow;
            }
        }

        // Check filesystem workflows if no strong registered match
        if ($bestScore < 2) {
            foreach ($this->list() as $name) {
                // Skip if already found as registered
                if (isset($this->registered[$name])) {
                    continue;
                }

                try {
                    $workflow = $this->loadFromFilesystem($name);
                    $score = $this->matchScore($workflow, $lowerDescription);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $workflow;
                    }
                } catch (WorkflowNotFoundException|WorkflowLoadException) {
                    continue;
                }
            }
        }

        return $bestScore > 0 ? $bestMatch : null;
    }

    /**
     * Calculate match score for a workflow against a description.
     */
    private function matchScore(Workflow $workflow, string $lowerDescription): int
    {
        $score = 0;

        // Exact name match gets highest score
        if (str_contains(strtolower($workflow->name), $lowerDescription)) {
            $score += 3;
        }

        // Description contains match
        if (str_contains(strtolower($workflow->description), $lowerDescription)) {
            $score += 2;
        }

        // Name contains description (partial match)
        if (str_contains($lowerDescription, strtolower($workflow->name))) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Load a workflow directly from the filesystem (bypassing registry).
     */
    private function loadFromFilesystem(string $name): Workflow
    {
        $phpPath = $this->resolvePhpPath($name);

        if (file_exists($phpPath)) {
            $workflow = require $phpPath;

            if (!$workflow instanceof Workflow) {
                throw new WorkflowLoadException(
                    "Workflow file {$phpPath} must return a Workflow instance"
                );
            }

            return $workflow;
        }

        // Fall back to YAML
        return $this->loadYaml($name);
    }

    /**
     * Resolve workflow name to full PHP filesystem path.
     */
    private function resolvePhpPath(string $name): string
    {
        $this->validateName($name);

        return $this->expandPath($this->workflowsPath) . "/{$name}.php";
    }

    /**
     * Resolve workflow name to full YAML filesystem path.
     */
    private function resolveYamlPath(string $name): string
    {
        $this->validateName($name);

        return $this->expandPath($this->workflowsPath) . "/{$name}.yaml";
    }

    /**
     * Validate workflow name to prevent directory traversal.
     *
     * @throws WorkflowNotFoundException When the name contains invalid characters.
     */
    private function validateName(string $name): void
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);

        if ($sanitized === '' || $sanitized !== $name) {
            throw new WorkflowNotFoundException(
                "Invalid workflow name '{$name}'. Use only alphanumeric characters, underscores, and hyphens."
            );
        }
    }

    /**
     * Expand tilde and environment variables in paths.
     */
    private function expandPath(string $path): string
    {
        return rtrim(
            preg_replace('/^ ~/', $_SERVER['HOME'] ?? '/root', $path),
            '/'
        );
    }

    /**
     * Parse YAML data into a Workflow object.
     *
     * @param array{name:string, description?:string, stages?:array, config?:array} $data
     * @throws WorkflowLoadException When the YAML structure is invalid.
     */
    private function parseYamlWorkflow(array $data): Workflow
    {
        if (empty($data['name'])) {
            throw new WorkflowLoadException('Workflow YAML must have a "name" field');
        }

        $builder = (new WorkflowBuilder())
            ->name($data['name'])
            ->description($data['description'] ?? '');

        if (isset($data['stages']) && is_array($data['stages'])) {
            foreach ($data['stages'] as $stage) {
                $this->parseYamlStage($builder, $stage);
            }
        }

        if (isset($data['config']) && is_array($data['config'])) {
            if (isset($data['config']['maxConcurrent'])) {
                $builder->maxConcurrent((int) $data['config']['maxConcurrent']);
            }
            if (isset($data['config']['timeout'])) {
                $builder->timeout((int) $data['config']['timeout']);
            }
        }

        return $builder->build();
    }

    /**
     * Parse a single stage entry from YAML into the workflow builder.
     *
     * @param array{name:string, agent?:string, prompt?:string, tools?:array, parallel?:bool, agents?:array} $stage
     * @throws WorkflowLoadException When the stage structure is invalid.
     */
    private function parseYamlStage(WorkflowBuilder $builder, array $stage): void
    {
        if (empty($stage['name'])) {
            throw new WorkflowLoadException('Stage must have a "name" field');
        }

        // Parallel stage with multiple agents
        if (!empty($stage['parallel']) && !empty($stage['agents']) && is_array($stage['agents'])) {
            $tasks = [];
            foreach ($stage['agents'] as $agent) {
                if (!is_array($agent)) {
                    throw new WorkflowLoadException('Parallel stage agents must be arrays');
                }
                $taskBuilder = Tasks::agent($agent['type'] ?? 'coder');
                if (isset($agent['name'])) {
                    $taskBuilder->name($agent['name']);
                }
                if (isset($agent['prompt'])) {
                    $taskBuilder->prompt($agent['prompt']);
                }
                if (isset($agent['tools']) && is_array($agent['tools'])) {
                    $taskBuilder->tools($agent['tools']);
                }
                $tasks[] = $taskBuilder;
            }
            $builder->parallel($stage['name'], $tasks);
        }
        // Simple stage with single agent
        elseif (!empty($stage['agent'])) {
            $taskBuilder = Tasks::agent($stage['agent']);
            if (isset($stage['prompt'])) {
                $taskBuilder->prompt($stage['prompt']);
            }
            if (isset($stage['tools']) && is_array($stage['tools'])) {
                $taskBuilder->tools($stage['tools']);
            }
            $builder->stage($stage['name'], $taskBuilder);
        }
        // Pipeline stage with nested stages
        elseif (!empty($stage['stages']) && is_array($stage['stages'])) {
            $builtStages = [];
            foreach ($stage['stages'] as $nestedStage) {
                $nestedBuilder = (new WorkflowBuilder())->name($nestedStage['name'] ?? '');
                if (isset($nestedStage['agent'])) {
                    $taskBuilder = Tasks::agent($nestedStage['agent']);
                    if (isset($nestedStage['prompt'])) {
                        $taskBuilder->prompt($nestedStage['prompt']);
                    }
                    $nestedBuilder->stage($nestedStage['name'], $taskBuilder);
                }
                $builtStages[] = $nestedBuilder->build()->stages[0] ?? null;
            }
            $builtStages = array_filter($builtStages);
            if (!empty($builtStages)) {
                $builder->pipeline($stage['name'], $builtStages);
            }
        } else {
            throw new WorkflowLoadException(
                "Stage '{$stage['name']}' must have either 'agent', 'parallel' with 'agents', or 'stages'"
            );
        }
    }
}
