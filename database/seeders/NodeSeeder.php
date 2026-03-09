<?php

namespace Database\Seeders;

use App\Models\Node;
use App\Models\NodeCategory;
use Illuminate\Database\Seeder;

class NodeSeeder extends Seeder
{
    /**
     * Node types to disable. Add a type string here to seed it as inactive.
     *
     * @var list<string>
     */
    private const DISABLED_NODES = [
        // 'ai.text_classifier',
        // 'ai.summarizer',
        // 'storage.read_file',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = NodeCategory::query()->pluck('id', 'slug');

        $nodes = [
            // ── Triggers ──────────────────────────────────────────────
            [
                'category' => 'triggers',
                'type' => 'trigger.webhook',
                'name' => 'Webhook',
                'description' => 'Starts the workflow when an HTTP request is received.',
                'icon' => 'bolt',
                'color' => '#F59E0B',
                'node_kind' => 'trigger',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'http_method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'DELETE'], 'default' => 'POST'],
                        'path' => ['type' => 'string', 'description' => 'Custom webhook path'],
                        'authentication' => ['type' => 'string', 'enum' => ['none', 'header', 'basic'], 'default' => 'none'],
                    ],
                    'required' => ['http_method'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'headers' => ['type' => 'object'],
                        'body' => ['type' => 'object'],
                        'query' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'category' => 'triggers',
                'type' => 'trigger.schedule',
                'name' => 'Schedule',
                'description' => 'Starts the workflow on a recurring cron schedule.',
                'icon' => 'clock',
                'color' => '#F59E0B',
                'node_kind' => 'trigger',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'cron' => ['type' => 'string', 'description' => 'Cron expression (e.g. */5 * * * *)'],
                        'timezone' => ['type' => 'string', 'default' => 'UTC'],
                    ],
                    'required' => ['cron'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'triggered_at' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                ],
            ],
            [
                'category' => 'triggers',
                'type' => 'trigger.manual',
                'name' => 'Manual Trigger',
                'description' => 'Starts the workflow manually by the user.',
                'icon' => 'hand-raised',
                'color' => '#F59E0B',
                'node_kind' => 'trigger',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'input_fields' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'type' => ['type' => 'string', 'enum' => ['string', 'number', 'boolean']],
                                ],
                            ],
                        ],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'input' => ['type' => 'object'],
                    ],
                ],
            ],

            // ── AI ────────────────────────────────────────────────────
            [
                'category' => 'ai',
                'type' => 'ai.llm',
                'name' => 'LLM Prompt',
                'description' => 'Send a prompt to a large language model and receive a completion.',
                'icon' => 'cpu-chip',
                'color' => '#8B5CF6',
                'node_kind' => 'action',
                'credential_type' => 'openai',
                'is_premium' => true,
                'cost_hint_usd' => 0.0020,
                'latency_hint_ms' => 3000,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'model' => ['type' => 'string', 'default' => 'gpt-4o-mini'],
                        'system_prompt' => ['type' => 'string'],
                        'temperature' => ['type' => 'number', 'minimum' => 0, 'maximum' => 2, 'default' => 0.7],
                        'max_tokens' => ['type' => 'integer', 'default' => 1024],
                    ],
                    'required' => ['model'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => ['type' => 'string'],
                    ],
                    'required' => ['prompt'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'usage' => ['type' => 'object', 'properties' => [
                            'prompt_tokens' => ['type' => 'integer'],
                            'completion_tokens' => ['type' => 'integer'],
                        ]],
                    ],
                ],
            ],
            [
                'category' => 'ai',
                'type' => 'ai.text_classifier',
                'name' => 'Text Classifier',
                'description' => 'Classify text into predefined categories using AI.',
                'icon' => 'tag',
                'color' => '#8B5CF6',
                'node_kind' => 'action',
                'credential_type' => 'openai',
                'is_premium' => true,
                'cost_hint_usd' => 0.0010,
                'latency_hint_ms' => 2000,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'categories' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'List of classification labels'],
                        'model' => ['type' => 'string', 'default' => 'gpt-4o-mini'],
                    ],
                    'required' => ['categories'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['text'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                    ],
                ],
            ],
            [
                'category' => 'ai',
                'type' => 'ai.summarizer',
                'name' => 'Summarizer',
                'description' => 'Summarize long text into concise bullet points or a paragraph.',
                'icon' => 'document-text',
                'color' => '#8B5CF6',
                'node_kind' => 'action',
                'credential_type' => 'openai',
                'is_premium' => true,
                'cost_hint_usd' => 0.0015,
                'latency_hint_ms' => 2500,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'format' => ['type' => 'string', 'enum' => ['paragraph', 'bullets'], 'default' => 'paragraph'],
                        'max_length' => ['type' => 'integer', 'default' => 200],
                        'model' => ['type' => 'string', 'default' => 'gpt-4o-mini'],
                    ],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['text'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'summary' => ['type' => 'string'],
                    ],
                ],
            ],

            // ── Flow Control ──────────────────────────────────────────
            [
                'category' => 'flow-control',
                'type' => 'flow.if',
                'name' => 'If / Else',
                'description' => 'Branch the workflow based on a condition.',
                'icon' => 'arrows-right-left',
                'color' => '#3B82F6',
                'node_kind' => 'control',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'conditions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'field' => ['type' => 'string'],
                                    'operator' => ['type' => 'string', 'enum' => ['equals', 'not_equals', 'contains', 'gt', 'lt', 'gte', 'lte', 'is_empty', 'is_not_empty']],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'combine' => ['type' => 'string', 'enum' => ['and', 'or'], 'default' => 'and'],
                    ],
                    'required' => ['conditions'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object'],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'branch' => ['type' => 'string', 'enum' => ['true', 'false']],
                    ],
                ],
            ],
            [
                'category' => 'flow-control',
                'type' => 'flow.switch',
                'name' => 'Switch',
                'description' => 'Route to different branches based on matching a value.',
                'icon' => 'queue-list',
                'color' => '#3B82F6',
                'node_kind' => 'control',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string', 'description' => 'The field to evaluate'],
                        'cases' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'value' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'has_default' => ['type' => 'boolean', 'default' => true],
                    ],
                    'required' => ['field', 'cases'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object'],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'matched_case' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'category' => 'flow-control',
                'type' => 'flow.loop',
                'name' => 'Loop',
                'description' => 'Iterate over an array and execute child nodes for each item.',
                'icon' => 'arrow-path',
                'color' => '#3B82F6',
                'node_kind' => 'control',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'batch_size' => ['type' => 'integer', 'default' => 1, 'description' => 'Number of items to process per batch'],
                        'max_iterations' => ['type' => 'integer', 'default' => 100],
                    ],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array'],
                    ],
                    'required' => ['items'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'item' => ['type' => 'object'],
                        'index' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'category' => 'flow-control',
                'type' => 'flow.delay',
                'name' => 'Delay',
                'description' => 'Pause the workflow for a specified amount of time.',
                'icon' => 'clock',
                'color' => '#3B82F6',
                'node_kind' => 'control',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'duration' => ['type' => 'integer', 'description' => 'Duration in seconds'],
                        'unit' => ['type' => 'string', 'enum' => ['seconds', 'minutes', 'hours'], 'default' => 'seconds'],
                    ],
                    'required' => ['duration'],
                ],
            ],
            [
                'category' => 'flow-control',
                'type' => 'flow.merge',
                'name' => 'Merge',
                'description' => 'Merge multiple branches back into a single path.',
                'icon' => 'arrows-pointing-in',
                'color' => '#3B82F6',
                'node_kind' => 'control',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => ['wait_all', 'first'], 'default' => 'wait_all'],
                    ],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'inputs' => ['type' => 'array'],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'merged' => ['type' => 'object'],
                    ],
                ],
            ],

            // ── Data ──────────────────────────────────────────────────
            [
                'category' => 'data',
                'type' => 'data.transform',
                'name' => 'Data Transform',
                'description' => 'Map, rename, or restructure data fields.',
                'icon' => 'adjustments-horizontal',
                'color' => '#10B981',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mappings' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'source' => ['type' => 'string'],
                                    'target' => ['type' => 'string'],
                                    'transform' => ['type' => 'string', 'enum' => ['none', 'uppercase', 'lowercase', 'trim', 'to_number', 'to_string', 'to_boolean']],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['mappings'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object'],
                    ],
                    'required' => ['data'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object'],
                    ],
                ],
            ],
            [
                'category' => 'data',
                'type' => 'data.filter',
                'name' => 'Filter',
                'description' => 'Filter items in an array based on conditions.',
                'icon' => 'funnel',
                'color' => '#10B981',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'conditions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'field' => ['type' => 'string'],
                                    'operator' => ['type' => 'string', 'enum' => ['equals', 'not_equals', 'contains', 'gt', 'lt']],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['conditions'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array'],
                    ],
                    'required' => ['items'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array'],
                        'count' => ['type' => 'integer'],
                    ],
                ],
            ],
            [
                'category' => 'data',
                'type' => 'data.aggregate',
                'name' => 'Aggregate',
                'description' => 'Aggregate array items (count, sum, average, min, max).',
                'icon' => 'calculator',
                'color' => '#10B981',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'operation' => ['type' => 'string', 'enum' => ['count', 'sum', 'average', 'min', 'max']],
                        'field' => ['type' => 'string', 'description' => 'Field to aggregate (not required for count)'],
                    ],
                    'required' => ['operation'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array'],
                    ],
                    'required' => ['items'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'result' => ['type' => 'number'],
                    ],
                ],
            ],
            [
                'category' => 'data',
                'type' => 'data.set_variable',
                'name' => 'Set Variable',
                'description' => 'Set a workflow-level variable for use in subsequent nodes.',
                'icon' => 'variable',
                'color' => '#10B981',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'variable_name' => ['type' => 'string'],
                        'value' => ['type' => 'string', 'description' => 'Static value or expression'],
                    ],
                    'required' => ['variable_name', 'value'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'variable_name' => ['type' => 'string'],
                        'value' => [],
                    ],
                ],
            ],
            [
                'category' => 'data',
                'type' => 'data.json_parse',
                'name' => 'JSON Parse',
                'description' => 'Parse a JSON string into a structured object.',
                'icon' => 'code-bracket',
                'color' => '#10B981',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'json_string' => ['type' => 'string'],
                    ],
                    'required' => ['json_string'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object'],
                    ],
                ],
            ],

            // ── Communication ─────────────────────────────────────────
            [
                'category' => 'communication',
                'type' => 'comm.send_email',
                'name' => 'Send Email',
                'description' => 'Send an email using SMTP or a transactional email provider.',
                'icon' => 'envelope',
                'color' => '#EC4899',
                'node_kind' => 'action',
                'credential_type' => 'smtp',
                'latency_hint_ms' => 1500,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'to' => ['type' => 'string'],
                        'subject' => ['type' => 'string'],
                        'body_type' => ['type' => 'string', 'enum' => ['text', 'html'], 'default' => 'html'],
                    ],
                    'required' => ['to', 'subject'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'body' => ['type' => 'string'],
                        'cc' => ['type' => 'string'],
                        'bcc' => ['type' => 'string'],
                    ],
                    'required' => ['body'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message_id' => ['type' => 'string'],
                        'sent' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'category' => 'communication',
                'type' => 'comm.slack_message',
                'name' => 'Slack Message',
                'description' => 'Send a message to a Slack channel or user.',
                'icon' => 'chat-bubble-left',
                'color' => '#EC4899',
                'node_kind' => 'action',
                'credential_type' => 'slack',
                'latency_hint_ms' => 1000,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'channel' => ['type' => 'string'],
                        'as_user' => ['type' => 'boolean', 'default' => false],
                    ],
                    'required' => ['channel'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                    ],
                    'required' => ['message'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ts' => ['type' => 'string'],
                        'ok' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'category' => 'communication',
                'type' => 'comm.discord_message',
                'name' => 'Discord Message',
                'description' => 'Send a message to a Discord channel via webhook.',
                'icon' => 'chat-bubble-bottom-center-text',
                'color' => '#EC4899',
                'node_kind' => 'action',
                'credential_type' => 'discord',
                'latency_hint_ms' => 1000,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'webhook_url' => ['type' => 'string'],
                        'username' => ['type' => 'string'],
                    ],
                    'required' => ['webhook_url'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string'],
                    ],
                    'required' => ['content'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                    ],
                ],
            ],

            // ── HTTP & APIs ───────────────────────────────────────────
            [
                'category' => 'http-apis',
                'type' => 'http.request',
                'name' => 'HTTP Request',
                'description' => 'Make an HTTP request to any URL.',
                'icon' => 'globe-alt',
                'color' => '#F97316',
                'node_kind' => 'action',
                'latency_hint_ms' => 2000,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], 'default' => 'GET'],
                        'url' => ['type' => 'string'],
                        'headers' => ['type' => 'object'],
                        'timeout' => ['type' => 'integer', 'default' => 30],
                        'response_type' => ['type' => 'string', 'enum' => ['json', 'text', 'binary'], 'default' => 'json'],
                    ],
                    'required' => ['method', 'url'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'body' => [],
                        'query_params' => ['type' => 'object'],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status_code' => ['type' => 'integer'],
                        'headers' => ['type' => 'object'],
                        'body' => [],
                    ],
                ],
            ],
            [
                'category' => 'http-apis',
                'type' => 'http.graphql',
                'name' => 'GraphQL Request',
                'description' => 'Execute a GraphQL query or mutation.',
                'icon' => 'code-bracket-square',
                'color' => '#F97316',
                'node_kind' => 'action',
                'latency_hint_ms' => 2000,
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'endpoint' => ['type' => 'string'],
                        'headers' => ['type' => 'object'],
                    ],
                    'required' => ['endpoint'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                        'variables' => ['type' => 'object'],
                    ],
                    'required' => ['query'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object'],
                        'errors' => ['type' => 'array'],
                    ],
                ],
            ],
            [
                'category' => 'http-apis',
                'type' => 'http.webhook_response',
                'name' => 'Webhook Response',
                'description' => 'Send a custom HTTP response back to the webhook caller.',
                'icon' => 'arrow-uturn-left',
                'color' => '#F97316',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'status_code' => ['type' => 'integer', 'default' => 200],
                        'headers' => ['type' => 'object'],
                    ],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'body' => [],
                    ],
                ],
            ],

            // ── Utility ───────────────────────────────────────────────
            [
                'category' => 'utility',
                'type' => 'util.code',
                'name' => 'Code (JavaScript)',
                'description' => 'Execute custom JavaScript code to transform data.',
                'icon' => 'code-bracket',
                'color' => '#6B7280',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string', 'description' => 'JavaScript code to execute'],
                    ],
                    'required' => ['code'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'object'],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'result' => [],
                    ],
                ],
            ],
            [
                'category' => 'utility',
                'type' => 'util.template',
                'name' => 'Text Template',
                'description' => 'Render a text template with dynamic variables.',
                'icon' => 'document-text',
                'color' => '#6B7280',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'template' => ['type' => 'string', 'description' => 'Template with {{variable}} placeholders'],
                    ],
                    'required' => ['template'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'variables' => ['type' => 'object'],
                    ],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'category' => 'utility',
                'type' => 'util.logger',
                'name' => 'Logger',
                'description' => 'Log a message or data for debugging purposes.',
                'icon' => 'document-magnifying-glass',
                'color' => '#6B7280',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'level' => ['type' => 'string', 'enum' => ['debug', 'info', 'warning', 'error'], 'default' => 'info'],
                    ],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string'],
                        'data' => ['type' => 'object'],
                    ],
                    'required' => ['message'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'logged' => ['type' => 'boolean'],
                    ],
                ],
            ],
            [
                'category' => 'utility',
                'type' => 'util.error_handler',
                'name' => 'Error Handler',
                'description' => 'Catch and handle errors from upstream nodes.',
                'icon' => 'exclamation-triangle',
                'color' => '#6B7280',
                'node_kind' => 'control',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'on_error' => ['type' => 'string', 'enum' => ['stop', 'continue', 'retry'], 'default' => 'stop'],
                        'max_retries' => ['type' => 'integer', 'default' => 3],
                        'retry_delay_seconds' => ['type' => 'integer', 'default' => 5],
                    ],
                ],
            ],

            // ── Storage ───────────────────────────────────────────────
            [
                'category' => 'storage',
                'type' => 'storage.read_file',
                'name' => 'Read File',
                'description' => 'Read the contents of a file from local or cloud storage.',
                'icon' => 'document-arrow-down',
                'color' => '#0EA5E9',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'disk' => ['type' => 'string', 'enum' => ['local', 's3', 'gcs'], 'default' => 'local'],
                        'path' => ['type' => 'string'],
                        'encoding' => ['type' => 'string', 'enum' => ['utf-8', 'base64'], 'default' => 'utf-8'],
                    ],
                    'required' => ['path'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string'],
                        'size' => ['type' => 'integer'],
                        'mime_type' => ['type' => 'string'],
                    ],
                ],
            ],
            [
                'category' => 'storage',
                'type' => 'storage.write_file',
                'name' => 'Write File',
                'description' => 'Write content to a file in local or cloud storage.',
                'icon' => 'document-arrow-up',
                'color' => '#0EA5E9',
                'node_kind' => 'action',
                'config_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'disk' => ['type' => 'string', 'enum' => ['local', 's3', 'gcs'], 'default' => 'local'],
                        'path' => ['type' => 'string'],
                    ],
                    'required' => ['path'],
                ],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string'],
                    ],
                    'required' => ['content'],
                ],
                'output_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'size' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];

        foreach ($nodes as $nodeData) {
            $categorySlug = $nodeData['category'];
            unset($nodeData['category']);

            $nodeData['category_id'] = $categories[$categorySlug];
            $nodeData['is_active'] = ! in_array($nodeData['type'], self::DISABLED_NODES);
            $nodeData['is_premium'] ??= false;

            Node::query()->updateOrCreate(
                ['type' => $nodeData['type']],
                $nodeData,
            );
        }
    }
}
