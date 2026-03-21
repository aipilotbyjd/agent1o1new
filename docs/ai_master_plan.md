# 🧠 AI Master Implementation Plan — Agent1o1

> **This is the definitive, combined plan.** It covers the full AI upgrade from where you are today → a production-ready, multi-provider AI platform with autonomous agents, structured output, RAG, vision, audio, error diagnosis, and a workflow builder — all done properly using the official Laravel AI SDK.

---

## 📍 Where You Are Today

### What Already Works

| Component | File | Status |
|---|---|---|
| OpenAI chat completion, classifier, summarizer, embeddings, image gen | [OpenAiNode.php](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php) | ✅ Working (raw HTTP) |
| Node seeder entries (`ai.llm`, `ai.text_classifier`, `ai.summarizer`) | [NodeSeeder.php](file:///Users/jaydeep/Herd/agent1o1/database/seeders/NodeSeeder.php#L111-220) | ✅ Present |
| Registry aliases (`ai.llm` → `openai.chat_completion`, etc.) | [NodeRegistry.php](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeRegistry.php#L62-65) | ✅ Working |
| AI credential types (openai, anthropic, gemini, groq, mistral, etc.) | [CredentialTypeSeeder.php](file:///Users/jaydeep/Herd/agent1o1/database/seeders/CredentialTypeSeeder.php#L55-141) | ✅ Already seeded |
| AI Fix Suggestion model | [AiFixSuggestion.php](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiFixSuggestion.php) | ⚠️ Model exists, not wired up |
| AI Generation Log model | [AiGenerationLog.php](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiGenerationLog.php) | ⚠️ Model exists, not wired up |
| AI migration files | `create_ai_fix_suggestions_table`, `create_ai_generation_logs_table` | ✅ Migrated |
| AI category in node catalog | [NodeCategorySeeder.php](file:///Users/jaydeep/Herd/agent1o1/database/seeders/NodeCategorySeeder.php#L24-31) | ✅ Present |
| OpenAI test suite | [OpenAiNodeTest.php](file:///Users/jaydeep/Herd/agent1o1/tests/Feature/Engine/Nodes/Apps/OpenAi/OpenAiNodeTest.php) | ✅ 7 tests passing |
| [.env](file:///Users/jaydeep/Herd/agent1o1/.env) configuration | Missing `OPENAI_API_KEY` and all other AI keys | ❌ Not configured |

### What's Wrong / Needs Cleanup

| Issue | Details |
|---|---|
| **OpenAI-only lock-in** | All AI nodes hardcoded to OpenAI. Can't use Anthropic, Gemini, Groq, etc. |
| **Raw HTTP calls** | Manual HTTP to OpenAI. No retries, no failover, no standard patterns |
| **No tool-calling / agents** | Can't build autonomous AI loops. The #1 missing feature |
| **No structured output** | Faking JSON via prompt engineering (`"respond in JSON"`). Fragile |
| **No conversation memory** | Every LLM call is stateless. Can't build chatbots or multi-step agents |
| **AI models not wired up** | [AiFixSuggestion](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiFixSuggestion.php#8-55) + [AiGenerationLog](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiGenerationLog.php#8-55) exist but nothing uses them |
| **Credential types already exist** | Anthropic, Gemini, Groq, Mistral, etc. already in CredentialTypeSeeder — good! |
| **No RAG / embeddings pipeline** | Has embeddings method but no vector store, no similarity search |
| **No image analysis / vision** | Can generate images but can't analyze them |
| **No audio / transcription** | No TTS or STT support |

---

## 🏗️ The Plan — 7 Phases

### Phase 1: Install `laravel/ai` SDK + Configure

> **Effort: 30 minutes | Risk: None**

**What:** Install the official Laravel AI SDK which replaces raw HTTP calls with a unified, multi-provider API.

**Steps:**

```bash
# 1. Install the SDK
composer require laravel/ai

# 2. Publish config + migrations
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --no-interaction

# 3. Run migrations (creates agent_conversations + agent_conversation_messages tables)
php artisan migrate
```

**Update [.env](file:///Users/jaydeep/Herd/agent1o1/.env):**
```env
# Add after existing OAuth keys
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
XAI_API_KEY=
GROQ_API_KEY=
MISTRAL_API_KEY=
```

**Verify:** `config/ai.php` exists with provider config.

**Test:** Run existing test suite — nothing should break since we haven't changed any code yet.

---

### Phase 2: Build New Multi-Provider `LlmNode` + Clean Up Old Code

> **Effort: 3-4 hours | Risk: Medium (replaces existing node)**

**What:** Create a new `LlmNode` using the `laravel/ai` SDK that supports ALL providers, then migrate the old [OpenAiNode](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#10-214) code away.

#### Step 2.1: Create `LlmNode`

**New file:** `app/Engine/Nodes/Apps/Ai/LlmNode.php`

This single node handles all LLM operations across all providers:
- `chat_completion` — Using SDK Agent with `prompt()`
- `text_classifier` — Using SDK Agent with `HasStructuredOutput`
- [summarizer](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#129-157) — Using SDK Agent with specific instructions
- [embeddings](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#158-184) — Using SDK `Embeddings::for()->generate()`
- `image_generation` — Using SDK `Image::of()->generate()`

Key difference from [OpenAiNode](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#10-214): The `provider` and `model` come from node config, not hardcoded.

#### Step 2.2: Create SDK Agents for Each Operation

**New directory:** `app/Ai/Agents/`

| Agent | File | Purpose |
|---|---|---|
| `ChatAgent` | `app/Ai/Agents/ChatAgent.php` | Simple prompt → response |
| `TextClassifierAgent` | `app/Ai/Agents/TextClassifierAgent.php` | Structured output: `{category, confidence}` |
| `SummarizerAgent` | `app/Ai/Agents/SummarizerAgent.php` | Summarize with format control |

Each agent implements `Laravel\Ai\Contracts\Agent` and uses `Promptable` trait.

#### Step 2.3: Update NodeRegistry

**File:** [NodeRegistry.php](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeRegistry.php)

Changes to [appDirectoryMap()](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeRegistry.php#192-231):
```php
// Add new Ai directory mapping
'ai' => ['Ai', 'LlmNode'],

// Keep OpenAi mapping for backward compatibility
'openai' => ['OpenAi', 'OpenAiNode'],
```

Changes to `$aliases`:
```php
// OLD (remove):
// 'ai.llm' => 'openai.chat_completion',
// 'ai.text_classifier' => 'openai.text_classifier',
// 'ai.summarizer' => 'openai.summarizer',

// NEW (add):
'ai.llm' => 'ai.chat_completion',
'ai.text_classifier' => 'ai.text_classifier',
'ai.summarizer' => 'ai.summarizer',
'ai.embeddings' => 'ai.embeddings',
'ai.image_generation' => 'ai.image_generation',
'ai.sentiment' => 'ai.sentiment',
'ai.vision' => 'ai.vision',
'ai.agent' => 'ai.agent',
'ai.transcribe' => 'ai.transcribe',
'ai.tts' => 'ai.tts',
'ai.structured_extract' => 'ai.structured_extract',
```

#### Step 2.4: Update NodeSeeder

**File:** [NodeSeeder.php](file:///Users/jaydeep/Herd/agent1o1/database/seeders/NodeSeeder.php#L111-220)

Update existing 3 AI nodes:
- Change `credential_type` from `'openai'` to `null` (SDK handles credentials via `config/ai.php`)
- Add `provider` and `model` dropdowns to config_schema
- Keep backward-compatible output_schema

#### Step 2.5: Handle the Old [OpenAiNode](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#10-214)

**KEEP** [OpenAiNode.php](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php) as-is. Don't delete it.

**Why:** Any existing saved workflows might reference `openai.chat_completion` directly. The registry will still resolve it. We deprecate it in docs but don't break it. Old tests continue to pass. We'll write NEW tests for `LlmNode`.

#### Step 2.6: Write Tests

**New file:** `tests/Feature/Engine/Nodes/Apps/Ai/LlmNodeTest.php`

- Tests use `Ai::fake()` from the SDK — no HTTP faking needed
- Test each operation: chat, classify, summarize, embed, image
- Test provider switching (openai → anthropic → gemini)
- Test failover

**Existing file:** [OpenAiNodeTest.php](file:///Users/jaydeep/Herd/agent1o1/tests/Feature/Engine/Nodes/Apps/OpenAi/OpenAiNodeTest.php) — **keep as-is**, all 7 tests should still pass.

---

### Phase 3: AI Agent Node — Autonomous Tool-Calling

> **Effort: 5-6 hours | Risk: Medium (new feature, complex)**

This is the flagship feature. An AI Agent that autonomously decides which of your workflow nodes to call.

#### Step 3.1: Create `WorkflowNodeTool`

**New file:** `app/Ai/Tools/WorkflowNodeTool.php`

This wraps ANY of your existing engine nodes as a Laravel AI SDK `Tool`:

```php
class WorkflowNodeTool implements Tool
{
    public function __construct(
        private string $nodeType,           // 'slack.send_message'
        private string $toolDescription,    // 'Send a message to a Slack channel'
        private array $parameterSchema,     // Converted from config_schema
        private ?array $credentials = null, // Resolved from workspace
    ) {}

    public function description(): Stringable|string { ... }
    public function handle(Request $request): Stringable|string { ... }
    public function schema(JsonSchema $schema): array { ... }
}
```

The [handle()](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Contracts/NodeHandler.php#10-14) method:
1. Creates a `NodePayload` from the SDK `Request`
2. Resolves the node handler via `NodeRegistry::resolve()`
3. Executes the node
4. Returns the [NodeResult](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeResult.php#7-89) output as a string

#### Step 3.2: Create Schema Converter Service

**New file:** `app/Ai/Services/SchemaConverter.php`

Converts your existing `config_schema` (JSON Schema format) to Laravel AI SDK `JsonSchema` format:

```php
class SchemaConverter
{
    /**
     * Convert a NodeSeeder config_schema to Laravel AI SDK schema format.
     */
    public static function toAiSdkSchema(array $configSchema, JsonSchema $schema): array { ... }
}
```

#### Step 3.3: Create `WorkflowAgent`

**New file:** `app/Ai/Agents/WorkflowAgent.php`

```php
#[MaxSteps(15)]
#[Timeout(180)]
class WorkflowAgent implements Agent, HasTools, Conversational
{
    use Promptable;

    public function __construct(
        private string $systemPrompt,
        private array $availableTools,  // WorkflowNodeTool[]
        private ?int $maxSteps = 15,
    ) {}

    public function instructions(): Stringable|string { ... }
    public function tools(): iterable { ... }
}
```

#### Step 3.4: Create `AiAgentNode` Engine Node

**New file:** `app/Engine/Nodes/Apps/Ai/AiAgentNode.php`

This is the engine node that goes in workflows. It:

1. Reads config: `system_prompt`, `provider`, `model`, `tools[]`, `max_steps`, `temperature`
2. For each tool in `tools[]`:
   - Looks up the node definition in the DB
   - Creates a `WorkflowNodeTool` instance
   - Attaches matching workspace credentials
3. Creates a `WorkflowAgent` with all tools
4. Calls `$agent->prompt($inputPrompt)`
5. Logs each tool call step to `ai_agent_steps` table
6. Returns final response as output

#### Step 3.5: Add Seeder Entry

Add to NodeSeeder:
```php
[
    'category' => 'ai',
    'type' => 'ai.agent',
    'name' => 'AI Agent',
    'description' => 'Autonomous AI agent that decides which tools to use to complete a task.',
    'icon' => 'sparkles',
    'color' => '#8B5CF6',
    'node_kind' => 'action',
    'is_premium' => true,
    'config_schema' => [
        'type' => 'object',
        'properties' => [
            'provider' => [
                'type' => 'string',
                'enum' => ['openai', 'anthropic', 'gemini', 'groq', 'xai', 'mistral', 'ollama'],
                'default' => 'openai',
            ],
            'model' => ['type' => 'string', 'default' => 'gpt-4o'],
            'system_prompt' => ['type' => 'string'],
            'max_steps' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 25],
            'tools' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Node types the agent can use as tools',
            ],
            'temperature' => ['type' => 'number', 'default' => 0.7],
        ],
        'required' => ['system_prompt', 'tools'],
    ],
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'prompt' => ['type' => 'string'],
            'context' => ['type' => 'object'],
        ],
        'required' => ['prompt'],
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'response' => ['type' => 'string'],
            'tool_calls' => ['type' => 'array'],
            'tokens_used' => ['type' => 'integer'],
        ],
    ],
],
```

#### Step 3.6: Create Agent Steps Migration

```bash
php artisan make:migration create_ai_agent_steps_table --no-interaction
```

```php
Schema::create('ai_agent_steps', function (Blueprint $table) {
    $table->id();
    $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
    $table->string('execution_node_key');
    $table->unsignedSmallInteger('step_number');
    $table->string('action');           // 'tool_call' or 'final_answer'
    $table->string('tool_name')->nullable();
    $table->json('tool_input')->nullable();
    $table->json('tool_output')->nullable();
    $table->text('llm_reasoning')->nullable();
    $table->unsignedInteger('tokens_used')->default(0);
    $table->unsignedInteger('duration_ms')->default(0);
    $table->timestamps();
});
```

#### Step 3.7: Create `AiAgentStep` Model

```bash
php artisan make:model AiAgentStep --no-interaction
```

#### Step 3.8: Write Tests

**New file:** `tests/Feature/Engine/Nodes/Apps/Ai/AiAgentNodeTest.php`

Tests:
- Agent receives prompt and returns response (no tools)
- Agent calls a single tool and returns result
- Agent calls multiple tools in sequence
- Agent respects `max_steps` limit
- Agent handles tool execution errors gracefully
- Agent step logging to `ai_agent_steps` table

---

### Phase 4: Additional AI Nodes

> **Effort: 6-8 hours total | Risk: Low (all follow the same pattern)**

All of these go in `app/Engine/Nodes/Apps/Ai/LlmNode.php` as operations, plus their matching SDK agents.

#### 4.1: Structured Output (`ai.structured_extract`)

| Item | Detail |
|---|---|
| **Agent** | `app/Ai/Agents/StructuredExtractAgent.php` — implements `HasStructuredOutput` |
| **Input** | Raw text + JSON schema definition from config |
| **Output** | Structured JSON matching the schema |
| **Use case** | Parse invoices, extract contacts from emails, convert unstructured → structured |
| **Seeder** | New entry in NodeSeeder with `config_schema` that accepts a [schema](file:///Users/jaydeep/Herd/agent1o1/database/seeders/CredentialTypeSeeder.php#24-29) field |

#### 4.2: Sentiment Analysis (`ai.sentiment`)

| Item | Detail |
|---|---|
| **Agent** | `app/Ai/Agents/SentimentAgent.php` — implements `HasStructuredOutput` |
| **Output schema** | `{sentiment: 'positive'|'negative'|'neutral', score: 0-1, emotions: string[]}` |
| **Use case** | Customer feedback routing, social media monitoring |

#### 4.3: Vision / Image Analysis (`ai.vision`)

| Item | Detail |
|---|---|
| **Agent** | `app/Ai/Agents/VisionAgent.php` — uses SDK attachments for images |
| **Input** | Image URL or base64 + prompt |
| **Output** | Text description, extracted text (OCR), analysis |
| **Providers** | OpenAI (GPT-4o), Anthropic (Claude), Gemini (native multimodal) |

#### 4.4: Embeddings (`ai.embeddings`)

| Item | Detail |
|---|---|
| **Method** | Uses SDK `Embeddings::for()->generate()` directly |
| **Seeder** | New entry with model + dimensions config |
| **Output** | Vector array + usage stats |

#### 4.5: TTS — Text to Speech (`ai.tts`)

| Item | Detail |
|---|---|
| **Method** | Uses SDK `Audio::of('text')->generate()` |
| **Config** | Voice selection, speed, provider |
| **Output** | Audio file URL |

#### 4.6: STT — Speech to Text (`ai.transcribe`)

| Item | Detail |
|---|---|
| **Method** | Uses SDK `Transcription::for($path)->generate()` |
| **Input** | Audio file URL or path |
| **Output** | Transcribed text + confidence |

---

### Phase 5: Wire Up Platform AI Features

> **Effort: 6-8 hours | Risk: Low-Medium**

#### 5.1: AI Error Diagnosis Agent

**Wire up the existing [AiFixSuggestion](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiFixSuggestion.php) model.**

**New file:** `app/Ai/Agents/ErrorDiagnosisAgent.php`

```php
#[MaxSteps(5)]
#[UseCheapestModel]
class ErrorDiagnosisAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private string $errorMessage,
        private string $nodeType,
        private array $nodeConfig,
        private array $inputData,
    ) {}

    public function instructions(): string
    {
        return 'You are a workflow debugging expert. Analyze the error, diagnose the root cause, and suggest fixes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'diagnosis' => $schema->string()->required(),
            'suggestions' => $schema->array()->items(
                $schema->object()->properties([
                    'title' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                    'fix_config' => $schema->object(),
                ])
            )->required(),
        ];
    }
}
```

**Integration point:** Listen for `ExecutionNodeFailed` event → dispatch `DiagnoseFailedNode` job → save to `ai_fix_suggestions`.

**New files:**
- `app/Ai/Agents/ErrorDiagnosisAgent.php`
- `app/Jobs/DiagnoseFailedNode.php`
- `app/Listeners/TriggerAiDiagnosis.php`

#### 5.2: AI Workflow Builder Agent

**Wire up the existing [AiGenerationLog](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiGenerationLog.php) model.**

**New files:**
- `app/Ai/Agents/WorkflowBuilderAgent.php`
- `app/Ai/Tools/ListAvailableNodesTool.php` — lists all nodes from `nodes` table
- `app/Ai/Tools/InspectNodeSchemaTool.php` — returns config_schema for a node type
- `app/Http/Controllers/Api/AiWorkflowController.php` — API endpoint

**Flow:**
1. User sends: `POST /api/workspaces/{id}/ai/generate-workflow` with `{prompt: "..."}`
2. `WorkflowBuilderAgent` uses tools to discover available nodes
3. Generates workflow JSON (nodes + edges + configs)
4. Saves to `ai_generation_logs`
5. Returns the generated workflow for user review

#### 5.3: AI Workflow Description Generator

**New file:** `app/Ai/Agents/WorkflowDescriptionAgent.php`

Simple agent that reads a workflow's node graph and generates a human-readable description + documentation.

---

### Phase 6: Database Migrations

> **Effort: 1 hour | Risk: None**

#### New Migrations Needed

| Migration | Purpose | Created By |
|---|---|---|
| `create_ai_agent_steps_table` | Log each tool-call step an agent takes | Phase 3.6 |

#### Already Exists — No Changes Needed

| Migration | Status |
|---|---|
| `create_ai_fix_suggestions_table` | ✅ Already migrated |
| `create_ai_generation_logs_table` | ✅ Already migrated |
| `agent_conversations` | ✅ Created by `laravel/ai` SDK (Phase 1) |
| `agent_conversation_messages` | ✅ Created by `laravel/ai` SDK (Phase 1) |

#### No Credential Migration Needed!

Looking at [CredentialTypeSeeder.php](file:///Users/jaydeep/Herd/agent1o1/database/seeders/CredentialTypeSeeder.php#L55-141), you **already have** credential types for: OpenAI, Anthropic, Google AI (Gemini), Azure OpenAI, Groq, Hugging Face, Mistral, Cohere, Perplexity. ✨

---

### Phase 7: Full Test Suite

> **Effort: Throughout | Risk: None**

#### Test Files

| File | Tests | Uses |
|---|---|---|
| `tests/Feature/Engine/Nodes/Apps/Ai/LlmNodeTest.php` | Chat, classify, summarize with multi-provider | `Ai::fake()` |
| `tests/Feature/Engine/Nodes/Apps/Ai/AiAgentNodeTest.php` | Tool-calling loop, max steps, error handling | `Ai::fake()` |
| `tests/Feature/Engine/Nodes/Apps/Ai/EmbeddingsTest.php` | Embedding generation | `Ai::fake()` |
| `tests/Feature/Engine/Nodes/Apps/Ai/ImageTest.php` | Image gen + analysis | `Ai::fake()` |
| `tests/Feature/Engine/Nodes/Apps/Ai/AudioTest.php` | TTS + STT | `Ai::fake()` |
| `tests/Feature/Ai/WorkflowNodeToolTest.php` | Wrap engine nodes as tools | Unit |
| `tests/Feature/Ai/SchemaConverterTest.php` | JSON Schema → SDK schema | Unit |
| `tests/Feature/Ai/ErrorDiagnosisTest.php` | Diagnose + save suggestion | `Ai::fake()` |
| `tests/Feature/Ai/WorkflowBuilderTest.php` | NL → workflow JSON | `Ai::fake()` |

**Existing tests to keep unchanged:**
- [OpenAiNodeTest.php](file:///Users/jaydeep/Herd/agent1o1/tests/Feature/Engine/Nodes/Apps/OpenAi/OpenAiNodeTest.php) — 7 tests, all should still pass

---

## 📁 Complete File Structure (New + Changed)

```
app/
├── Ai/                                    # ← NEW DIRECTORY
│   ├── Agents/
│   │   ├── ChatAgent.php                  # Simple LLM prompt
│   │   ├── TextClassifierAgent.php        # Structured: {category, confidence}
│   │   ├── SummarizerAgent.php            # Summarize with format
│   │   ├── SentimentAgent.php             # Structured: {sentiment, score, emotions}
│   │   ├── VisionAgent.php                # Image analysis with attachments
│   │   ├── StructuredExtractAgent.php     # Dynamic structured output
│   │   ├── WorkflowAgent.php              # Main autonomous agent (Phase 3)
│   │   ├── ErrorDiagnosisAgent.php        # Diagnose failed nodes (Phase 5)
│   │   ├── WorkflowBuilderAgent.php       # NL → workflow JSON (Phase 5)
│   │   └── WorkflowDescriptionAgent.php   # Describe workflow graphs (Phase 5)
│   ├── Tools/
│   │   ├── WorkflowNodeTool.php           # Wraps engine nodes as AI tools
│   │   ├── ListAvailableNodesTool.php     # For workflow builder
│   │   └── InspectNodeSchemaTool.php      # For workflow builder
│   └── Services/
│       └── SchemaConverter.php            # config_schema → SDK JsonSchema
│
├── Engine/
│   └── Nodes/
│       └── Apps/
│           ├── Ai/                        # ← NEW DIRECTORY
│           │   └── LlmNode.php            # Multi-provider, multi-operation
│           └── OpenAi/
│               └── OpenAiNode.php         # ← KEEP (deprecated, backward compat)
│
├── Jobs/
│   └── DiagnoseFailedNode.php             # ← NEW (Phase 5)
│
├── Listeners/
│   └── TriggerAiDiagnosis.php             # ← NEW (Phase 5)
│
├── Http/Controllers/Api/
│   └── AiWorkflowController.php           # ← NEW (Phase 5)
│
├── Models/
│   ├── AiFixSuggestion.php                # ← EXISTS (wire up in Phase 5)
│   ├── AiGenerationLog.php                # ← EXISTS (wire up in Phase 5)
│   └── AiAgentStep.php                    # ← NEW (Phase 3)
│
config/
│   └── ai.php                             # ← NEW (published by laravel/ai)
│
database/migrations/
│   └── xxxx_create_ai_agent_steps_table.php  # ← NEW
│
tests/Feature/
│   ├── Engine/Nodes/Apps/
│   │   ├── Ai/
│   │   │   ├── LlmNodeTest.php            # ← NEW
│   │   │   ├── AiAgentNodeTest.php        # ← NEW
│   │   │   ├── EmbeddingsTest.php         # ← NEW
│   │   │   ├── ImageTest.php              # ← NEW
│   │   │   └── AudioTest.php              # ← NEW
│   │   └── OpenAi/
│   │       └── OpenAiNodeTest.php         # ← KEEP (unchanged)
│   └── Ai/
│       ├── WorkflowNodeToolTest.php       # ← NEW
│       ├── SchemaConverterTest.php        # ← NEW
│       ├── ErrorDiagnosisTest.php         # ← NEW
│       └── WorkflowBuilderTest.php        # ← NEW
```

**Files changed (not new):**
- [NodeRegistry.php](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeRegistry.php) — add `'ai'` to [appDirectoryMap()](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeRegistry.php#192-231) + update aliases
- [NodeSeeder.php](file:///Users/jaydeep/Herd/agent1o1/database/seeders/NodeSeeder.php) — add new AI node entries + update existing 3
- [.env](file:///Users/jaydeep/Herd/agent1o1/.env) + [.env.example](file:///Users/jaydeep/Herd/agent1o1/.env.example) — add AI provider API keys
- [bootstrap/app.php](file:///Users/jaydeep/Herd/agent1o1/bootstrap/app.php) — register event listener for AI diagnosis (Phase 5)

---

## ⚡ Execution Order

| # | Phase | What | Effort | Depends On |
|---|---|---|---|---|
| 1 | **Phase 1** | `composer require laravel/ai` + publish + migrate + [.env](file:///Users/jaydeep/Herd/agent1o1/.env) | 30 min | Nothing |
| 2 | **Phase 2.1-2.2** | Create `LlmNode` + SDK Agents (Chat, Classifier, Summarizer) | 2-3 hrs | Phase 1 |
| 3 | **Phase 2.3** | Update NodeRegistry ([appDirectoryMap](file:///Users/jaydeep/Herd/agent1o1/app/Engine/NodeRegistry.php#192-231) + aliases) | 30 min | Phase 2.1 |
| 4 | **Phase 2.4** | Update NodeSeeder (add provider/model to existing AI nodes) | 1 hr | Phase 2.3 |
| 5 | **Phase 2.6** | Write `LlmNodeTest.php` + run all tests | 1 hr | Phase 2.4 |
| 6 | **Phase 3.1-3.3** | Create `WorkflowNodeTool` + `SchemaConverter` + `WorkflowAgent` | 2-3 hrs | Phase 2 |
| 7 | **Phase 3.4** | Create `AiAgentNode` (operation in `LlmNode`) | 2 hrs | Phase 3.1-3.3 |
| 8 | **Phase 3.5-3.7** | Seeder entry + migration + `AiAgentStep` model | 1 hr | Phase 3.4 |
| 9 | **Phase 3.8** | Write `AiAgentNodeTest.php` | 1 hr | Phase 3.7 |
| 10 | **Phase 4** | Structured output, sentiment, vision, embeddings, TTS, STT | 4-6 hrs | Phase 2 |
| 11 | **Phase 5.1** | Wire up [AiFixSuggestion](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiFixSuggestion.php#8-55) with `ErrorDiagnosisAgent` | 2-3 hrs | Phase 2 |
| 12 | **Phase 5.2** | Wire up [AiGenerationLog](file:///Users/jaydeep/Herd/agent1o1/app/Models/AiGenerationLog.php#8-55) with `WorkflowBuilderAgent` | 3-4 hrs | Phase 3 |
| 13 | **Phase 5.3** | Workflow description generator | 1 hr | Phase 2 |
| 14 | **Phase 7** | Full test suite pass + Pint formatting | 1-2 hrs | All |

**Total: ~3-4 days** of focused work.

---

## 🔒 Safety Guardrails

| Guardrail | Implementation |
|---|---|
| **Max steps** | `#[MaxSteps(15)]` on WorkflowAgent. Configurable per-node via `config.max_steps` |
| **Timeout** | `#[Timeout(180)]` on agents. SDK enforces HTTP timeout |
| **Token budget** | Track via `ai_agent_steps.tokens_used`. Alert when approaching limits |
| **Approved tools only** | Agent can ONLY call tools listed in `config.tools[]` |
| **Credential scoping** | Tools get credentials from the workspace, not globally |
| **Failover** | SDK supports `provider: [Lab::OpenAI, Lab::Anthropic]` automatic failover |
| **Cost tracking** | Log `tokens_used` on every agent step for billing/monitoring |
| **Human approval** | Future: integrate with existing `WorkflowApproval` model for high-risk tool calls |

---

## 🚫 What We're NOT Doing (And Why)

| Skipping | Reason |
|---|---|
| Deleting [OpenAiNode](file:///Users/jaydeep/Herd/agent1o1/app/Engine/Nodes/Apps/OpenAi/OpenAiNode.php#10-214) | Backward compatibility. Existing workflows referencing `openai.*` types must keep working |
| New credential migrations | Already have all AI providers in CredentialTypeSeeder |
| LangChain / heavy framework | `laravel/ai` SDK is lighter, first-party, tested, and sufficient |
| Multi-agent (supervisor/worker) | v2 feature. Start with single agent, add orchestration later |
| Vector store migration (pgvector) | Phase 4 RAG only. This is a separate conversation when you're ready |
| Frontend changes | This plan covers backend only. Frontend AI config UI is a separate task |

---

## ✅ Pre-Flight Checklist Before Starting

- [ ] PostgreSQL is running locally
- [ ] Redis is running locally
- [ ] `php artisan test --compact` passes (baseline)
- [ ] Git is clean (commit current state)
- [ ] Get at least an `OPENAI_API_KEY` ready for `config/ai.php`

---

> **Ready to start Phase 1?** I'll run `composer require laravel/ai`, publish config, run migrations, and update [.env](file:///Users/jaydeep/Herd/agent1o1/.env) — all non-destructive, all reversible. Say the word! 🚀
