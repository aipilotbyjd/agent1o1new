<?php

namespace App\Engine\Data;

/**
 * Compiles expression templates at build time and resolves them at runtime.
 *
 * Expressions use the format: {{ $nodes.nodeId.output.path.to.value }}
 *
 * Supported prefixes:
 *   $nodes.{id}.output.{path}  — Upstream node output
 *   $trigger.{path}            — Execution trigger data
 *   $vars.{key}                — Workspace/runtime variable
 *   $env.{key}                 — Config value
 *   $execution.{field}         — Execution metadata
 *   $loop.index                — Current loop iteration index
 *   $loop.item.{path}          — Current loop item data
 */
class ExpressionParser
{
    /**
     * Compile a template string into an array of tokens.
     *
     * @return list<array{type: string, value?: string, source?: string, node?: string, path?: list<string>}>
     */
    public function compile(string $template): array
    {
        if (! str_contains($template, '{{')) {
            return [['type' => 'literal', 'value' => $template]];
        }

        $tokens = [];
        $remaining = $template;

        while (preg_match('/\{\{\s*(.+?)\s*\}\}/', $remaining, $match, PREG_OFFSET_CAPTURE)) {
            $matchStart = $match[0][1];
            $fullMatch = $match[0][0];
            $expression = trim($match[1][0]);

            // Add leading literal text if any
            if ($matchStart > 0) {
                $tokens[] = ['type' => 'literal', 'value' => substr($remaining, 0, $matchStart)];
            }

            $tokens[] = $this->parseExpression($expression);

            $remaining = substr($remaining, $matchStart + strlen($fullMatch));
        }

        // Add trailing literal text if any
        if ($remaining !== '') {
            $tokens[] = ['type' => 'literal', 'value' => $remaining];
        }

        return $tokens;
    }

    /**
     * Resolve compiled tokens against runtime data.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @param  array<string, mixed>  $context  Keys: 'nodes', 'trigger', 'vars', 'env', 'execution', 'loop'
     */
    public function resolve(array $tokens, array $context): mixed
    {
        // Single-token path expression → return the raw value (preserve type)
        if (count($tokens) === 1 && $tokens[0]['type'] !== 'literal') {
            return $this->resolveToken($tokens[0], $context);
        }

        // Multi-token or mixed → concatenate as string
        $result = '';
        foreach ($tokens as $token) {
            $value = $token['type'] === 'literal'
                ? $token['value']
                : $this->resolveToken($token, $context);

            $result .= is_array($value) ? json_encode($value) : (string) $value;
        }

        return $result;
    }

    /**
     * Convenience: compile and immediately resolve a template.
     *
     * @param  array<string, mixed>  $context
     */
    public function evaluate(string $template, array $context): mixed
    {
        return $this->resolve($this->compile($template), $context);
    }

    /**
     * Recursively compile all string values in an array structure.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> Same structure with string values replaced by compiled tokens.
     */
    public function compileConfig(array $config): array
    {
        $compiled = [];

        foreach ($config as $key => $value) {
            if (is_string($value) && str_contains($value, '{{')) {
                $compiled[$key] = ['__expr' => true, 'tokens' => $this->compile($value)];
            } elseif (is_array($value)) {
                $compiled[$key] = $this->compileConfig($value);
            } else {
                $compiled[$key] = $value;
            }
        }

        return $compiled;
    }

    /**
     * Recursively resolve all compiled expressions in a config array.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function resolveConfig(array $config, array $context): array
    {
        $resolved = [];

        foreach ($config as $key => $value) {
            if (is_array($value) && ($value['__expr'] ?? false)) {
                $resolved[$key] = $this->resolve($value['tokens'], $context);
            } elseif (is_array($value)) {
                $resolved[$key] = $this->resolveConfig($value, $context);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Check whether a string contains any expression templates.
     */
    public function hasExpressions(string $value): bool
    {
        return str_contains($value, '{{') && str_contains($value, '}}');
    }

    /**
     * Extract all node IDs referenced in a template string.
     *
     * @return list<string>
     */
    public function extractNodeDependencies(string $template): array
    {
        $tokens = $this->compile($template);
        $nodes = [];

        foreach ($tokens as $token) {
            if (($token['source'] ?? null) === 'nodes' && isset($token['node'])) {
                $nodes[] = $token['node'];
            }
        }

        return array_values(array_unique($nodes));
    }

    /**
     * Parse a single expression (without {{ }}) into a path token.
     *
     * @return array{type: string, source: string, node?: string, path: list<string>}
     */
    private function parseExpression(string $expression): array
    {
        // Remove leading $ if present
        $expression = ltrim($expression, '$');

        $segments = explode('.', $expression);
        $source = array_shift($segments);

        return match ($source) {
            'nodes' => [
                'type' => 'path',
                'source' => 'nodes',
                'node' => array_shift($segments) ?? '',
                'path' => $segments,
            ],
            'trigger', 'vars', 'env', 'execution', 'loop' => [
                'type' => 'path',
                'source' => $source,
                'path' => $segments,
            ],
            default => [
                'type' => 'path',
                'source' => 'vars',
                'path' => array_merge([$source], $segments),
            ],
        };
    }

    /**
     * Resolve a single path token against the runtime context.
     */
    private function resolveToken(array $token, array $context): mixed
    {
        $source = $token['source'];
        $path = $token['path'];

        $data = match ($source) {
            'nodes' => $context['nodes'][$token['node']] ?? null,
            'trigger' => $context['trigger'] ?? [],
            'vars' => $context['vars'] ?? [],
            'env' => $context['env'] ?? [],
            'execution' => $context['execution'] ?? [],
            'loop' => $context['loop'] ?? [],
            default => null,
        };

        if ($data === null || empty($path)) {
            return $data;
        }

        return data_get($data, implode('.', $path));
    }
}
