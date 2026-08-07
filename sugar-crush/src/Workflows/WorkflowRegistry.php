<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use Symfony\Component\Yaml\Yaml;

/**
 * Discovers and loads workflow definitions from PHP DSL files.
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
     * Tries .php first, then falls back to .yaml.
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
     * Load a workflow definition from a YAML file.
     *
     * @throws WorkflowNotFoundException When the YAML file does not exist.
     * @throws WorkflowLoadException When the YAML is invalid or missing required fields.
     */
    public function loadYaml(string $name): Workflow
    {
        $this->validateName($name);

        $yamlPath = $this->expandPath($this->workflowsPath) . "/{$name}.yaml";

        if (!file_exists($yamlPath)) {
            throw new WorkflowNotFoundException(
                "Workflow '{$name}' not found at {$yamlPath}"
            );
        }

        $data = Yaml::parseFile($yamlPath);

        if (!is_array($data)) {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} must contain a YAML map, got " . get_debug_type($data)
            );
        }

        if (!isset($data['name']) || $data['name'] === '') {
            throw new WorkflowLoadException(
                "Workflow file {$yamlPath} must have a \"name\" field"
            );
        }

        return $this->parseYamlWorkflow($data)->build();
    }

    /**
     * Map parsed YAML data to a WorkflowBuilder chain.
     *
     * @param array{name: string, description?: string, stages?: array, config?: array} $data
     */
    private function parseYamlWorkflow(array $data): WorkflowBuilder
    {
        $builder = (new WorkflowBuilder())
            ->name($data['name']);

        if (isset($data['description']) && $data['description'] !== '') {
            $builder = $builder->description($data['description']);
        }

        if (isset($data['stages']) && is_array($data['stages'])) {
            foreach ($data['stages'] as $stage) {
                $parsed = $this->parseYamlStage($stage);

                if (isset($stage['parallel']) && $stage['parallel'] === true) {
                    // $parsed is an array of TaskBuilders for parallel stages
                    $builder = $builder->parallel($stage['name'], $parsed);
                } else {
                    // $parsed is a single TaskBuilder for regular stages
                    $builder = $builder->stage($stage['name'], $parsed);
                }
            }
        }

        if (isset($data['config']) && is_array($data['config'])) {
            if (isset($data['config']['maxConcurrent'])) {
                $builder = $builder->maxConcurrent((int) $data['config']['maxConcurrent']);
            }
            if (isset($data['config']['timeout'])) {
                $builder = $builder->timeout((int) $data['config']['timeout']);
            }
        }

        return $builder;
    }

    /**
     * Map a parsed YAML stage to a TaskBuilder or array of TaskBuilders.
     *
     * Returns an array of TaskBuilders for parallel stages, or a single
     * TaskBuilder for regular stages.
     *
     * @param array{name: string, agent?: string, prompt?: string, tools?: array, parallel?: bool, agents?: array} $stage
     * @return TaskBuilder|array<int, TaskBuilder>
     */
    private function parseYamlStage(array $stage): TaskBuilder|array
    {
        if (isset($stage['parallel']) && $stage['parallel'] === true) {
            $builders = [];
            foreach ($stage['agents'] ?? [] as $agent) {
                $b = Tasks::agent($agent['type'] ?? 'coder');
                if (isset($agent['name'])) {
                    $b = $b->name($agent['name']);
                }
                if (isset($agent['prompt'])) {
                    $b = $b->prompt($agent['prompt']);
                }
                if (isset($agent['tools'])) {
                    $b = $b->tools($agent['tools']);
                }
                $builders[] = $b;
            }
            return $builders;
        }

        $b = Tasks::agent($stage['agent'] ?? 'coder');

        if (isset($stage['prompt'])) {
            $b = $b->prompt($stage['prompt']);
        }

        if (isset($stage['tools'])) {
            $b = $b->tools($stage['tools']);
        }

        return $b;
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
     * Expand tilde in paths.
     */
    private function expandPath(string $path): string
    {
        return rtrim(
            preg_replace('/^~/', $_SERVER['HOME'] ?? '/root', $path),
            '/'
        );
    }
}
