<?php

namespace Database\Seeders;

use App\Models\CredentialType;
use Illuminate\Database\Seeder;

class CredentialTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [

            // ── AI ────────────────────────────────────────────────────
            [
                'type' => 'openai',
                'name' => 'OpenAI',
                'description' => 'Connect to OpenAI APIs (GPT, Whisper, DALL-E, Embeddings).',
                'icon' => 'sparkles',
                'color' => '#10a37f',
                'docs_url' => 'https://platform.openai.com/api-keys',
                'fields_schema' => [
                    'required' => ['api_key'],
                    'properties' => [
                        'api_key' => ['type' => 'string', 'label' => 'API Key', 'secret' => true],
                        'organization' => ['type' => 'string', 'label' => 'Organization ID (optional)', 'secret' => false],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'anthropic',
                'name' => 'Anthropic',
                'description' => 'Connect to Anthropic Claude models.',
                'icon' => 'cpu-chip',
                'color' => '#c96442',
                'docs_url' => 'https://console.anthropic.com/account/keys',
                'fields_schema' => [
                    'required' => ['api_key'],
                    'properties' => [
                        'api_key' => ['type' => 'string', 'label' => 'API Key', 'secret' => true],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'google_ai',
                'name' => 'Google AI (Gemini)',
                'description' => 'Connect to Google Gemini and related AI models.',
                'icon' => 'sparkles',
                'color' => '#4285f4',
                'docs_url' => 'https://aistudio.google.com/app/apikey',
                'fields_schema' => [
                    'required' => ['api_key'],
                    'properties' => [
                        'api_key' => ['type' => 'string', 'label' => 'API Key', 'secret' => true],
                    ],
                ],
                'test_config' => null,
                'oauth_config' => null,
            ],

            // ── OAuth2 Types ──────────────────────────────────────────
            [
                'type' => 'google_oauth2',
                'name' => 'Google OAuth2',
                'description' => 'Connect to Google services (Sheets, Drive, Gmail, etc.) via OAuth2.',
                'icon' => 'globe-alt',
                'color' => '#4285f4',
                'docs_url' => 'https://console.cloud.google.com/apis/credentials',
                'fields_schema' => [
                    'required' => [],
                    'properties' => [
                        'access_token' => ['type' => 'string', 'label' => 'Access Token', 'secret' => true],
                        'refresh_token' => ['type' => 'string', 'label' => 'Refresh Token', 'secret' => true],
                        'expires_in' => ['type' => 'integer', 'label' => 'Expires In'],
                    ],
                ],
                'test_config' => null,
                'oauth_config' => [
                    'provider' => 'google',
                    'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                    'token_url' => 'https://oauth2.googleapis.com/token',
                    'client_id' => env('GOOGLE_CLIENT_ID', ''),
                    'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
                    'scopes' => [
                        'https://www.googleapis.com/auth/spreadsheets',
                        'https://www.googleapis.com/auth/drive.file',
                    ],
                    'use_pkce' => false,
                ],
            ],
            [
                'type' => 'github_oauth2',
                'name' => 'GitHub OAuth2',
                'description' => 'Connect to GitHub via OAuth2 (broader permissions than a PAT).',
                'icon' => 'code-bracket',
                'color' => '#24292f',
                'docs_url' => 'https://docs.github.com/en/apps/oauth-apps/building-oauth-apps',
                'fields_schema' => [
                    'required' => [],
                    'properties' => [
                        'access_token' => ['type' => 'string', 'label' => 'Access Token', 'secret' => true],
                        'refresh_token' => ['type' => 'string', 'label' => 'Refresh Token', 'secret' => true],
                    ],
                ],
                'test_config' => null,
                'oauth_config' => [
                    'provider' => 'github',
                    'authorization_url' => 'https://github.com/login/oauth/authorize',
                    'token_url' => 'https://github.com/login/oauth/access_token',
                    'client_id' => env('GITHUB_CLIENT_ID', ''),
                    'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
                    'scopes' => ['repo', 'read:user'],
                    'use_pkce' => false,
                ],
            ],
            [
                'type' => 'slack_oauth2',
                'name' => 'Slack OAuth2',
                'description' => 'Connect to Slack via OAuth2 (for user-level permissions).',
                'icon' => 'chat-bubble-left',
                'color' => '#4a154b',
                'docs_url' => 'https://api.slack.com/authentication/oauth-v2',
                'fields_schema' => [
                    'required' => [],
                    'properties' => [
                        'access_token' => ['type' => 'string', 'label' => 'Access Token', 'secret' => true],
                    ],
                ],
                'test_config' => null,
                'oauth_config' => [
                    'provider' => 'slack',
                    'authorization_url' => 'https://slack.com/oauth/v2/authorize',
                    'token_url' => 'https://slack.com/api/oauth.v2.access',
                    'client_id' => env('SLACK_CLIENT_ID', ''),
                    'client_secret' => env('SLACK_CLIENT_SECRET', ''),
                    'scopes' => ['chat:write', 'channels:read'],
                    'use_pkce' => false,
                ],
            ],

            // ── Communication ─────────────────────────────────────────
            [
                'type' => 'slack',
                'name' => 'Slack',
                'description' => 'Connect to Slack using a Bot Token to send messages and interact with channels.',
                'icon' => 'chat-bubble-left',
                'color' => '#4a154b',
                'docs_url' => 'https://api.slack.com/authentication/token-types',
                'fields_schema' => [
                    'required' => ['bot_token'],
                    'properties' => [
                        'bot_token' => ['type' => 'string', 'label' => 'Bot Token', 'secret' => true, 'placeholder' => 'xoxb-...'],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'discord',
                'name' => 'Discord',
                'description' => 'Connect to Discord using a Bot Token or Webhook URL.',
                'icon' => 'chat-bubble-bottom-center-text',
                'color' => '#5865f2',
                'docs_url' => 'https://discord.com/developers/docs/topics/oauth2',
                'fields_schema' => [
                    'required' => ['bot_token'],
                    'properties' => [
                        'bot_token' => ['type' => 'string', 'label' => 'Bot Token', 'secret' => true],
                        'webhook_url' => ['type' => 'string', 'label' => 'Webhook URL (optional)', 'secret' => false],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'telegram',
                'name' => 'Telegram',
                'description' => 'Connect to the Telegram Bot API to send messages and notifications.',
                'icon' => 'paper-airplane',
                'color' => '#2ca5e0',
                'docs_url' => 'https://core.telegram.org/bots#how-do-i-create-a-bot',
                'fields_schema' => [
                    'required' => ['bot_token'],
                    'properties' => [
                        'bot_token' => ['type' => 'string', 'label' => 'Bot Token', 'secret' => true],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'smtp',
                'name' => 'SMTP',
                'description' => 'Send emails via any SMTP server (Gmail, Outlook, Mailgun, etc.).',
                'icon' => 'envelope',
                'color' => '#EA4335',
                'docs_url' => 'https://nodemailer.com/smtp/',
                'fields_schema' => [
                    'required' => ['host', 'port', 'user', 'password'],
                    'properties' => [
                        'host' => ['type' => 'string', 'label' => 'SMTP Host', 'secret' => false, 'placeholder' => 'smtp.gmail.com'],
                        'port' => ['type' => 'integer', 'label' => 'Port', 'secret' => false, 'placeholder' => '587'],
                        'user' => ['type' => 'string', 'label' => 'Username / Email', 'secret' => false],
                        'password' => ['type' => 'string', 'label' => 'Password', 'secret' => true],
                        'from_email' => ['type' => 'string', 'label' => 'From Email (optional)', 'secret' => false],
                        'from_name' => ['type' => 'string', 'label' => 'From Name (optional)', 'secret' => false],
                        'ssl' => ['type' => 'boolean', 'label' => 'Use SSL/TLS', 'secret' => false],
                    ],
                ],
                'test_config' => null,
            ],

            // ── Developer & Version Control ───────────────────────────
            [
                'type' => 'github',
                'name' => 'GitHub',
                'description' => 'Authenticate with GitHub using a Personal Access Token.',
                'icon' => 'code-bracket',
                'color' => '#24292f',
                'docs_url' => 'https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token',
                'fields_schema' => [
                    'required' => ['access_token'],
                    'properties' => [
                        'access_token' => ['type' => 'string', 'label' => 'Personal Access Token', 'secret' => true, 'placeholder' => 'ghp_...'],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'gitlab',
                'name' => 'GitLab',
                'description' => 'Authenticate with GitLab using a Personal Access Token.',
                'icon' => 'code-bracket',
                'color' => '#fc6d26',
                'docs_url' => 'https://docs.gitlab.com/ee/user/profile/personal_access_tokens.html',
                'fields_schema' => [
                    'required' => ['access_token'],
                    'properties' => [
                        'access_token' => ['type' => 'string', 'label' => 'Personal Access Token', 'secret' => true],
                        'server_url' => ['type' => 'string', 'label' => 'Server URL (leave blank for gitlab.com)', 'secret' => false],
                    ],
                ],
                'test_config' => null,
            ],

            // ── Productivity ──────────────────────────────────────────
            [
                'type' => 'notion',
                'name' => 'Notion',
                'description' => 'Connect to Notion using an internal integration token.',
                'icon' => 'document-text',
                'color' => '#000000',
                'docs_url' => 'https://developers.notion.com/docs/create-a-notion-integration',
                'fields_schema' => [
                    'required' => ['internal_integration_token'],
                    'properties' => [
                        'internal_integration_token' => ['type' => 'string', 'label' => 'Internal Integration Token', 'secret' => true, 'placeholder' => 'secret_...'],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'airtable',
                'name' => 'Airtable',
                'description' => 'Connect to Airtable using a Personal Access Token.',
                'icon' => 'table-cells',
                'color' => '#18bfff',
                'docs_url' => 'https://airtable.com/account',
                'fields_schema' => [
                    'required' => ['access_token'],
                    'properties' => [
                        'access_token' => ['type' => 'string', 'label' => 'Personal Access Token', 'secret' => true, 'placeholder' => 'pat...'],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'google_sheets',
                'name' => 'Google Sheets',
                'description' => 'Read and write Google Sheets data using a Service Account.',
                'icon' => 'table-cells',
                'color' => '#34a853',
                'docs_url' => 'https://developers.google.com/sheets/api/guides/authorizing',
                'fields_schema' => [
                    'required' => ['service_account_json'],
                    'properties' => [
                        'service_account_json' => ['type' => 'string', 'label' => 'Service Account JSON', 'secret' => true, 'multiline' => true],
                    ],
                ],
                'test_config' => null,
            ],

            // ── Databases ─────────────────────────────────────────────
            [
                'type' => 'postgres',
                'name' => 'PostgreSQL',
                'description' => 'Connect to a PostgreSQL database.',
                'icon' => 'circle-stack',
                'color' => '#336791',
                'docs_url' => 'https://www.postgresql.org/docs/current/libpq-connect.html',
                'fields_schema' => [
                    'required' => ['host', 'port', 'database', 'user', 'password'],
                    'properties' => [
                        'host' => ['type' => 'string', 'label' => 'Host', 'secret' => false, 'placeholder' => 'localhost'],
                        'port' => ['type' => 'integer', 'label' => 'Port', 'secret' => false, 'placeholder' => '5432'],
                        'database' => ['type' => 'string', 'label' => 'Database Name', 'secret' => false],
                        'user' => ['type' => 'string', 'label' => 'Username', 'secret' => false],
                        'password' => ['type' => 'string', 'label' => 'Password', 'secret' => true],
                        'ssl' => ['type' => 'boolean', 'label' => 'Use SSL', 'secret' => false],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'mysql',
                'name' => 'MySQL',
                'description' => 'Connect to a MySQL or MariaDB database.',
                'icon' => 'circle-stack',
                'color' => '#4479a1',
                'docs_url' => 'https://dev.mysql.com/doc/refman/8.0/en/connecting.html',
                'fields_schema' => [
                    'required' => ['host', 'port', 'database', 'user', 'password'],
                    'properties' => [
                        'host' => ['type' => 'string', 'label' => 'Host', 'secret' => false, 'placeholder' => 'localhost'],
                        'port' => ['type' => 'integer', 'label' => 'Port', 'secret' => false, 'placeholder' => '3306'],
                        'database' => ['type' => 'string', 'label' => 'Database Name', 'secret' => false],
                        'user' => ['type' => 'string', 'label' => 'Username', 'secret' => false],
                        'password' => ['type' => 'string', 'label' => 'Password', 'secret' => true],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'redis',
                'name' => 'Redis',
                'description' => 'Connect to a Redis server for caching and data operations.',
                'icon' => 'server',
                'color' => '#d82c20',
                'docs_url' => 'https://redis.io/docs/connect/',
                'fields_schema' => [
                    'required' => ['host', 'port'],
                    'properties' => [
                        'host' => ['type' => 'string', 'label' => 'Host', 'secret' => false, 'placeholder' => 'localhost'],
                        'port' => ['type' => 'integer', 'label' => 'Port', 'secret' => false, 'placeholder' => '6379'],
                        'password' => ['type' => 'string', 'label' => 'Password (optional)', 'secret' => true],
                        'db' => ['type' => 'integer', 'label' => 'Database Index', 'secret' => false, 'placeholder' => '0'],
                    ],
                ],
                'test_config' => null,
            ],

            // ── Payments & Finance ────────────────────────────────────
            [
                'type' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Connect to Stripe to manage payments, customers, and subscriptions.',
                'icon' => 'credit-card',
                'color' => '#635bff',
                'docs_url' => 'https://dashboard.stripe.com/apikeys',
                'fields_schema' => [
                    'required' => ['secret_key'],
                    'properties' => [
                        'secret_key' => ['type' => 'string', 'label' => 'Secret Key', 'secret' => true, 'placeholder' => 'sk_...'],
                        'webhook_secret' => ['type' => 'string', 'label' => 'Webhook Signing Secret (optional)', 'secret' => true, 'placeholder' => 'whsec_...'],
                    ],
                ],
                'test_config' => null,
            ],

            // ── SMS / Voice ───────────────────────────────────────────
            [
                'type' => 'twilio',
                'name' => 'Twilio',
                'description' => 'Send SMS, make calls, and use other Twilio communication APIs.',
                'icon' => 'phone',
                'color' => '#f22f46',
                'docs_url' => 'https://console.twilio.com/',
                'fields_schema' => [
                    'required' => ['account_sid', 'auth_token'],
                    'properties' => [
                        'account_sid' => ['type' => 'string', 'label' => 'Account SID', 'secret' => false, 'placeholder' => 'AC...'],
                        'auth_token' => ['type' => 'string', 'label' => 'Auth Token', 'secret' => true],
                        'from_number' => ['type' => 'string', 'label' => 'Default From Number (optional)', 'secret' => false, 'placeholder' => '+1...'],
                    ],
                ],
                'test_config' => null,
            ],

            // ── Generic Auth ──────────────────────────────────────────
            [
                'type' => 'http_header_auth',
                'name' => 'HTTP Header Auth',
                'description' => 'Authenticate HTTP requests using a custom header (e.g. Authorization: Bearer <token>).',
                'icon' => 'key',
                'color' => '#6b7280',
                'docs_url' => null,
                'fields_schema' => [
                    'required' => ['name', 'value'],
                    'properties' => [
                        'name' => ['type' => 'string', 'label' => 'Header Name', 'secret' => false, 'placeholder' => 'Authorization'],
                        'value' => ['type' => 'string', 'label' => 'Header Value', 'secret' => true, 'placeholder' => 'Bearer your-token'],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'http_basic_auth',
                'name' => 'HTTP Basic Auth',
                'description' => 'Authenticate HTTP requests using a username and password.',
                'icon' => 'lock-closed',
                'color' => '#6b7280',
                'docs_url' => null,
                'fields_schema' => [
                    'required' => ['user', 'password'],
                    'properties' => [
                        'user' => ['type' => 'string', 'label' => 'Username', 'secret' => false],
                        'password' => ['type' => 'string', 'label' => 'Password', 'secret' => true],
                    ],
                ],
                'test_config' => null,
            ],
            [
                'type' => 'http_query_auth',
                'name' => 'HTTP Query Auth',
                'description' => 'Authenticate HTTP requests by appending an API key as a query parameter.',
                'icon' => 'key',
                'color' => '#6b7280',
                'docs_url' => null,
                'fields_schema' => [
                    'required' => ['name', 'value'],
                    'properties' => [
                        'name' => ['type' => 'string', 'label' => 'Query Param Name', 'secret' => false, 'placeholder' => 'api_key'],
                        'value' => ['type' => 'string', 'label' => 'API Key Value', 'secret' => true],
                    ],
                ],
                'test_config' => null,
            ],
        ];

        foreach ($types as $type) {
            CredentialType::updateOrCreate(
                ['type' => $type['type']],
                $type,
            );
        }
    }
}
