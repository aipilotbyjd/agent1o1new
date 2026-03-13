<?php

use App\Engine\Data\ExpressionParser;

// ── Compilation ─────────────────────────────────────────────

test('plain string compiles to a single literal token', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('Hello World');

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->toMatchArray(['type' => 'literal', 'value' => 'Hello World']);
});

test('single expression compiles to a path token', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('{{ $nodes.httpCall.output.body.token }}');

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->toMatchArray([
            'type' => 'path',
            'source' => 'nodes',
            'node' => 'httpCall',
            'path' => ['output', 'body', 'token'],
        ]);
});

test('mixed template compiles to literal and path tokens', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('Bearer {{ $nodes.auth.output.token }}');

    expect($tokens)->toHaveCount(2)
        ->and($tokens[0])->toMatchArray(['type' => 'literal', 'value' => 'Bearer '])
        ->and($tokens[1])->toMatchArray([
            'type' => 'path',
            'source' => 'nodes',
            'node' => 'auth',
            'path' => ['output', 'token'],
        ]);
});

test('variable expression compiles correctly', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('{{ $vars.api_key }}');

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->toMatchArray([
            'type' => 'path',
            'source' => 'vars',
            'path' => ['api_key'],
        ]);
});

test('trigger expression compiles correctly', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('{{ $trigger.body.name }}');

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->toMatchArray([
            'type' => 'path',
            'source' => 'trigger',
            'path' => ['body', 'name'],
        ]);
});

test('loop expression compiles correctly', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('{{ $loop.index }}');

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->toMatchArray([
            'type' => 'path',
            'source' => 'loop',
            'path' => ['index'],
        ]);
});

test('multiple expressions in one template compile correctly', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('{{ $vars.base_url }}/api/{{ $nodes.setup.output.version }}');

    expect($tokens)->toHaveCount(3)
        ->and($tokens[0]['source'])->toBe('vars')
        ->and($tokens[1])->toMatchArray(['type' => 'literal', 'value' => '/api/'])
        ->and($tokens[2]['source'])->toBe('nodes');
});

// ── Resolution ──────────────────────────────────────────────

test('single path token resolves to raw value preserving type', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('{{ $nodes.http.output.count }}');

    $context = [
        'nodes' => ['http' => ['output' => ['count' => 42]]],
    ];

    expect($parser->resolve($tokens, $context))->toBe(42);
});

test('mixed template resolves to concatenated string', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('Bearer {{ $vars.token }}');

    $context = ['vars' => ['token' => 'abc123']];

    expect($parser->resolve($tokens, $context))->toBe('Bearer abc123');
});

test('nested path resolves correctly', function () {
    $parser = new ExpressionParser;

    $result = $parser->evaluate('{{ $nodes.api.output.data.users.0.name }}', [
        'nodes' => ['api' => ['output' => ['data' => ['users' => [['name' => 'Jaydeep']]]]]],
    ]);

    expect($result)->toBe('Jaydeep');
});

test('missing path resolves to null', function () {
    $parser = new ExpressionParser;

    $result = $parser->evaluate('{{ $nodes.missing.output.value }}', ['nodes' => []]);

    expect($result)->toBeNull();
});

test('array output resolves to JSON in concatenated context', function () {
    $parser = new ExpressionParser;

    $tokens = $parser->compile('Data: {{ $nodes.http.output.items }}');

    $context = ['nodes' => ['http' => ['output' => ['items' => [1, 2, 3]]]]];

    expect($parser->resolve($tokens, $context))->toBe('Data: [1,2,3]');
});

// ── Config compilation ──────────────────────────────────────

test('compileConfig marks expression strings and leaves others untouched', function () {
    $parser = new ExpressionParser;

    $compiled = $parser->compileConfig([
        'url' => '{{ $vars.base_url }}/api',
        'method' => 'GET',
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bearer {{ $vars.token }}',
        ],
    ]);

    expect($compiled['url']['__expr'])->toBeTrue()
        ->and($compiled['method'])->toBe('GET')
        ->and($compiled['timeout'])->toBe(30)
        ->and($compiled['headers']['Authorization']['__expr'])->toBeTrue();
});

test('resolveConfig resolves compiled expressions', function () {
    $parser = new ExpressionParser;

    $compiled = $parser->compileConfig([
        'url' => '{{ $vars.base_url }}/users',
        'method' => 'GET',
    ]);

    $resolved = $parser->resolveConfig($compiled, [
        'vars' => ['base_url' => 'https://api.example.com'],
    ]);

    expect($resolved['url'])->toBe('https://api.example.com/users')
        ->and($resolved['method'])->toBe('GET');
});

// ── Dependency extraction ───────────────────────────────────

test('extractNodeDependencies returns referenced node IDs', function () {
    $parser = new ExpressionParser;

    $deps = $parser->extractNodeDependencies(
        '{{ $nodes.auth.output.token }} and {{ $nodes.config.output.url }}'
    );

    expect($deps)->toBe(['auth', 'config']);
});

test('extractNodeDependencies deduplicates', function () {
    $parser = new ExpressionParser;

    $deps = $parser->extractNodeDependencies(
        '{{ $nodes.http.output.a }} {{ $nodes.http.output.b }}'
    );

    expect($deps)->toBe(['http']);
});

// ── Utility ─────────────────────────────────────────────────

test('hasExpressions detects templates', function () {
    $parser = new ExpressionParser;

    expect($parser->hasExpressions('{{ $vars.x }}'))->toBeTrue()
        ->and($parser->hasExpressions('plain string'))->toBeFalse();
});
