# 🚀 AI Implementation Plan — Using Laravel AI SDK

> **Key Decision:** Use the official **`laravel/ai`** SDK instead of raw HTTP calls. This gives you multi-provider support, agents, tools, structured output, conversation memory, embeddings, image generation, audio, failover, and testing — all out of the box with a beautiful Laravel-native API.

---

## Why `laravel/ai` SDK Instead of Raw HTTP Calls

| What You Have Now (OpenAiNode) | What `laravel/ai` Gives You |
|---|---|
| Hand-written HTTP calls to OpenAI only | Unified API for OpenAI, Anthropic, Gemini, Groq, xAI, Mistral, Ollama, Cohere |
| Manual token management | Built-in conversation memory (`RemembersConversations`) |
| No tool-calling loop | Native agent + tools pattern with automatic tool-calling loop |
| Manual JSON parsing for structured output | `HasStructuredOutput` interface with JSON schema validation |
| No failover | Automatic failover across providers (`[Lab::OpenAI, Lab::Anthropic]`) |
| No testing fakes | Built-in `fake()` for agents, images, audio, embeddings |
| No embeddings support for RAG | `Str::of('text')->toEmbeddings()` + `SimilaritySearch` tool |
| No image/audio built-in | `Image::of()`, `Audio::of()`, `Transcription::for()` |

---

## Phase 1: Foundation — Install & Configure `laravel/ai`

### Step 1.1: Install the SDK

```bash
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

This creates:
- `config/ai.php` — provider configuration
- `agent_conversations` table — conversation memory
- `agent_conversation_messages` table — message history

### Step 1.2: Configure Providers in [.env](file:///Users/jaydeep/Herd/agent1o1/.env)

```env
# Primary
OPENAI_API_KEY=sk-...

# Additional providers (add as needed)
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
XAI_API_KEY=...

# Optional: self-hosted
OLLAMA_API_KEY=ollama
OLLAMA_BASE_URL=http://localhost:11434
```

### Step 1.3: Configure `config/ai.php`

Set default models, providers, and custom base URLs if needed. The SDK supports: OpenAI, Anthropic, Gemini, Groq, xAI, Mistral, Ollama, Cohere, DeepSeek, ElevenLabs, Jina, VoyageAI.

---

## Phase 2: Refactor Existing AI — Replace OpenAiNode

### Step 2.1: Create a New `LlmNode` Using the SDK

Replace the current [OpenAiNode](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#10-214) (which only calls OpenAI via HTTP) with a new `LlmNode` that uses `laravel/ai` SDK agents internally.

**New file:** `app/Engine/Nodes/Apps/Ai/LlmNode.php`

This node will:
- Accept `provider` in config (openai, anthropic, gemini, etc.)
- Accept `model` in config
- Support all existing operations: `chat_completion`, `text_classifier`, [summarizer](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#129-157), [embeddings](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#158-184), `image_generation`
- Use `laravel/ai` SDK internally instead of raw HTTP calls
- Support automatic failover

### Step 2.2: Create Supporting AI Nodes

| Node Class | Operations | SDK Feature Used |
|---|---|---|
| `LlmNode` | `chat_completion`, `text_classifier`, [summarizer](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#129-157) | `Agent::prompt()` |
| `EmbeddingsNode` | `embed_text`, `embed_batch` | `Embeddings::for()->generate()` |
| `ImageNode` | `generate`, `analyze` | `Image::of()->generate()` |
| `AudioNode` | `synthesize`, `transcribe` | `Audio::of()`, `Transcription::for()` |
| `AiAgentNode` | [run](file:///Users/jaydeep/Herd/agent1o1/database/seeders/NodeSeeder.php#22-1080) (the autonomous tool-calling loop) | Full Agent + Tools |

### Step 2.3: Update NodeRegistry

Add to [appDirectoryMap()](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeRegistry.php#192-231):
```php
'llm' => ['Ai', 'LlmNode'],
'ai_embeddings' => ['Ai', 'EmbeddingsNode'],
'ai_image' => ['Ai', 'ImageNode'],
'ai_audio' => ['Ai', 'AudioNode'],
'ai_agent' => ['Ai', 'AiAgentNode'],
```

Add aliases in `NodeRegistry::$aliases`:
```php
'ai.llm' => 'llm.chat_completion',          // backward compat
'ai.text_classifier' => 'llm.text_classifier',
'ai.summarizer' => 'llm.summarizer',
'ai.embeddings' => 'ai_embeddings.embed_text',
'ai.image_generate' => 'ai_image.generate',
'ai.image_analyze' => 'ai_image.analyze',
'ai.transcribe' => 'ai_audio.transcribe',
'ai.tts' => 'ai_audio.synthesize',
'ai.agent' => 'ai_agent.run',
```

### Step 2.4: Backward Compatibility

- Keep [OpenAiNode](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#10-214) working but deprecated
- The old `ai.llm` alias routes to the new `LlmNode`
- Existing workflows continue to work without changes

---

## Phase 3: AI Agent Node — The Big Feature

### Step 3.1: Create Workflow AI Tools

Each of your existing engine nodes becomes available as a **Laravel AI SDK Tool** that the agent can call:

**New directory:** `app/Ai/Tools/`

```php
// app/Ai/Tools/WorkflowNodeTool.php
class WorkflowNodeTool implements Tool
{
    public function __construct(
        private string $nodeType,      // e.g. 'slack.send_message'
        private string $toolName,      // e.g. 'send_slack_message'
        private string $toolDescription,
        private array $parameterSchema,
        private array $credentials,
    ) {}

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function handle(Request $request): string
    {
        // Route to your existing NodeRegistry & execute the node
        $handler = NodeRegistry::handler($this->nodeType);
        $payload = new NodePayload(/* build from $request */);
        $result = $handler->handle($payload);
        return json_encode($result->output);
    }

    public function schema(JsonSchema $schema): array
    {
        // Convert your existing config_schema to Laravel AI SDK schema
        return $this->parameterSchema;
    }
}
```

### Step 3.2: Create the Agent

**New file:** `app/Ai/Agents/WorkflowAgent.php`

```php
#[MaxSteps(15)]
#[Timeout(120)]
class WorkflowAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(
        private string $systemPrompt,
        private array $availableTools,  // WorkflowNodeTool[]
        private string $provider,
        private string $model,
    ) {}

    public function instructions(): string
    {
        return $this->systemPrompt;
    }

    public function tools(): iterable
    {
        return $this->availableTools;
    }
}
```

### Step 3.3: Create the `AiAgentNode` Engine Node

**New file:** `app/Engine/Nodes/Apps/Ai/AiAgentNode.php`

This node:
1. Reads `config.system_prompt`, `config.provider`, `config.model`, `config.tools`
2. Builds `WorkflowNodeTool` instances for each enabled tool
3. Creates a `WorkflowAgent` instance
4. Calls `$agent->prompt($inputData['prompt'])`
5. Returns the agent's response as output

### Step 3.4: Create the Seeder Entry

Add to [NodeSeeder.php](file:///Users/jaydeep/Herd/agent1o1/database/seeders/NodeSeeder.php):
```php
[
    'category' => 'ai',
    'type' => 'ai.agent',
    'name' => 'AI Agent',
    'description' => 'Autonomous AI agent that decides which tools to use to complete a task.',
    'icon' => 'sparkles',
    'color' => '#8B5CF6',
    'node_kind' => 'action',
    'credential_type' => 'openai',
    'is_premium' => true,
    'config_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => ['type' => 'string', 'enum' => ['openai', 'anthropic', 'gemini', 'groq', 'xai'], 'default' => 'openai'],
            'model' => ['type' => 'string', 'default' => 'gpt-4o'],
            'system_prompt' => ['type' => 'string'],
            'max_steps' => ['type' => 'integer', 'default' => 10],
            'tools' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'List of tool node types the agent can use'],
            'temperature' => ['type' => 'number', 'default' => 0.7],
        ],
        'required' => ['system_prompt', 'tools'],
    ],
],
```

---

## Phase 4: Additional AI Nodes

### 4.1: Structured Output Node (`ai.structured_extract`)

Extract structured data from unstructured text using `HasStructuredOutput`:
- Input: raw text (email, document, etc.)
- Output: structured JSON matching user-defined schema
- Use case: Parse invoices, extract contacts from emails, etc.

### 4.2: Sentiment Analysis Node (`ai.sentiment`)

A wrapper around the LLM with a pre-built prompt + structured output:
```php
return [
    'sentiment' => $schema->string()->enum(['positive', 'negative', 'neutral'])->required(),
    'score' => $schema->number()->min(0)->max(1)->required(),
    'emotions' => $schema->array()->items($schema->string())->required(),
];
```

### 4.3: Vision/Multimodal Node (`ai.vision`)

Uses the SDK's attachment support to analyze images:
- OCR text extraction
- Image description
- Document understanding (invoices, receipts)

### 4.4: Embeddings + RAG Node (`ai.rag`)

Uses the SDK's `SimilaritySearch` tool + `Embeddings` class:
1. Ingest documents → generate embeddings → store in DB (pgvector)
2. At query time → embed the query → similarity search → pass context to LLM

### 4.5: TTS / STT Nodes (`ai.tts`, `ai.transcribe`)

- **TTS:** `Audio::of('text')->generate(Lab::OpenAI)` → returns audio URL
- **STT:** `Transcription::for($audioPath)->generate()` → returns text

---

## Phase 5: AI-Powered Platform Features

### 5.1: Wire Up [AiFixSuggestion](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiFixSuggestion.php#8-55) (Error Diagnosis)

When a workflow execution fails:
1. Create an `ErrorDiagnosisAgent` with tools to inspect the failed node config, input data, and error message
2. Agent diagnoses the issue and suggests fixes
3. Store in [AiFixSuggestion](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiFixSuggestion.php#8-55) model
4. User can one-click apply the fix

### 5.2: Wire Up [AiGenerationLog](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiGenerationLog.php#8-55) (Workflow Builder)

Create a `WorkflowBuilderAgent` that:
1. Takes a natural language description
2. Has tools to list available nodes, list credential types, fetch node schemas
3. Generates a complete workflow JSON (nodes + edges + configs)
4. Saves to [AiGenerationLog](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiGenerationLog.php#8-55) for auditing

### 5.3: AI-Powered Workflow Description

Auto-generate workflow descriptions and documentation from the workflow graph using a simple agent prompt.

---

## Phase 6: Database Migrations

### 6.1: Migration for Provider Credentials

```bash
php artisan make:migration add_ai_provider_to_credential_types --no-interaction
```

Add new credential types for each AI provider:
- `anthropic` (API key)
- `gemini` (API key)
- `groq` (API key)
- `xai` (API key)
- `ollama` (base URL)

### 6.2: Migration for Agent Execution Logging

```bash
php artisan make:migration create_ai_agent_steps_table --no-interaction
```

```php
Schema::create('ai_agent_steps', function (Blueprint $table) {
    $table->id();
    $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
    $table->string('execution_node_key');
    $table->unsignedSmallInteger('step_number');
    $table->string('action');         // 'tool_call' or 'final_answer'
    $table->string('tool_name')->nullable();
    $table->json('tool_input')->nullable();
    $table->json('tool_output')->nullable();
    $table->string('llm_reasoning')->nullable();
    $table->unsignedInteger('tokens_used')->default(0);
    $table->unsignedInteger('duration_ms')->default(0);
    $table->timestamps();
});
```

---

## Phase 7: Tests

All phases include corresponding tests. Key test files:

| Test File | What It Tests |
|---|---|
| `tests/Feature/Engine/LlmNodeTest.php` | Multi-provider LLM calls via SDK |
| `tests/Feature/Engine/AiAgentNodeTest.php` | Agent tool-calling loop |
| `tests/Feature/Engine/EmbeddingsNodeTest.php` | Embedding generation |
| `tests/Feature/Engine/ImageNodeTest.php` | Image generation/analysis |
| `tests/Feature/Engine/AudioNodeTest.php` | TTS and STT |
| `tests/Feature/Ai/WorkflowNodeToolTest.php` | Tool wrapping of engine nodes |
| `tests/Feature/Ai/ErrorDiagnosisAgentTest.php` | AI error fix suggestions |
| `tests/Feature/Ai/WorkflowBuilderAgentTest.php` | NL → workflow generation |

All tests use the SDK's built-in `fake()` methods — **no real API calls needed**.

---

## File Structure (New Files)

```
app/
├── Ai/
│   ├── Agents/
│   │   ├── WorkflowAgent.php           # Main agent for AI Agent Node
│   │   ├── ErrorDiagnosisAgent.php     # Diagnoses failed nodes
│   │   └── WorkflowBuilderAgent.php    # NL → workflow JSON
│   └── Tools/
│       ├── WorkflowNodeTool.php        # Wraps engine nodes as AI tools
│       ├── ListAvailableNodesTool.php   # For workflow builder agent
│       └── InspectNodeSchemaTool.php    # For workflow builder agent
├── Engine/
│   └── Nodes/
│       └── Apps/
│           └── Ai/
│               ├── LlmNode.php         # Multi-provider LLM node
│               ├── AiAgentNode.php     # Autonomous agent node
│               ├── EmbeddingsNode.php  # Vector embeddings node
│               ├── ImageNode.php       # Image gen + analysis
│               └── AudioNode.php       # TTS + STT
├── Services/
│   └── AiProviderService.php           # Resolves provider/model from credentials
database/
├── migrations/
│   ├── xxxx_create_ai_agent_steps_table.php
│   └── xxxx_add_ai_providers_to_credential_types.php
config/
└── ai.php                               # Published by laravel/ai
tests/
└── Feature/
    ├── Engine/
    │   ├── LlmNodeTest.php
    │   ├── AiAgentNodeTest.php
    │   ├── EmbeddingsNodeTest.php
    │   ├── ImageNodeTest.php
    │   └── AudioNodeTest.php
    └── Ai/
        ├── WorkflowNodeToolTest.php
        ├── ErrorDiagnosisAgentTest.php
        └── WorkflowBuilderAgentTest.php
```

---

## Implementation Order & Timeline

| Order | Phase | What | Est. Effort |
|---|---|---|---|
| 1️⃣ | Phase 1 | Install & configure `laravel/ai` SDK | 30 min |
| 2️⃣ | Phase 2.1 | Create `LlmNode` (replace OpenAiNode) | 2-3 hours |
| 3️⃣ | Phase 2.3 | Update NodeRegistry + aliases | 30 min |
| 4️⃣ | Phase 6.1 | Credential type migration | 30 min |
| 5️⃣ | Phase 2.4 | Seeder updates for new AI nodes | 1 hour |
| 6️⃣ | Phase 3 | AI Agent Node (the big feature) | 4-6 hours |
| 7️⃣ | Phase 6.2 | Agent steps migration | 30 min |
| 8️⃣ | Phase 4.1-4.2 | Structured output + Sentiment nodes | 2 hours |
| 9️⃣ | Phase 4.3 | Vision/Multimodal node | 1-2 hours |
| 🔟 | Phase 4.4 | RAG / Embeddings node | 2-3 hours |
| 1️⃣1️⃣ | Phase 4.5 | TTS / STT nodes | 1 hour |
| 1️⃣2️⃣ | Phase 5.1 | AI Error Diagnosis | 2-3 hours |
| 1️⃣3️⃣ | Phase 5.2 | AI Workflow Builder | 3-4 hours |
| 1️⃣4️⃣ | Phase 7 | Full test suite | Throughout |

**Total estimated effort: ~3-4 days**

---

## Quick Reference: Laravel AI SDK API

```php
// Agent prompting
$response = (new MyAgent)->prompt('Hello!');

// With specific provider/model
$response = (new MyAgent)->prompt('Hello!', provider: Lab::Anthropic, model: 'claude-haiku');

// With failover
$response = (new MyAgent)->prompt('Hello!', provider: [Lab::OpenAI, Lab::Anthropic]);

// Conversation memory
$response = (new MyAgent)->forUser($user)->prompt('Hello!');
$response = (new MyAgent)->continue($conversationId, as: $user)->prompt('Follow up');

// Structured output
$response = (new StructuredAgent)->prompt('Analyze...');
$response['score']; // typed access

// Embeddings
$embeddings = Str::of('text')->toEmbeddings();
Embeddings::for(['text1', 'text2'])->generate(Lab::OpenAI);

// Images
$image = Image::of('A cat')->generate();
$image->url;

// Audio
$audio = Audio::of('Hello world')->generate();
$transcription = Transcription::for($audioPath)->generate();

// Testing
Ai::fake();
Ai::assertAgentPrompted(SalesCoach::class);
```

---

> **Ready to start?** I'll begin with **Phase 1** (install `laravel/ai`) and then move through each phase, writing code + tests for each step. Just say the word! 🚀
