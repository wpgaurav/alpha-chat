# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Alpha Chat** — AI-powered chatbot for WordPress. Clean-room build. No code, branding, or vendor dependencies carried over from any prior project.

- PHP 8.2+ (floor enforced in `alpha-chat.php` and `Activator`).
- WordPress 6.5+.
- Node 20+ for asset builds.
- License: GPL-2.0-or-later.

## Commands

All expect deps installed: `composer install && npm install`.

| Task | Command |
| --- | --- |
| Start local WP | `npm run env:start` (wp-env on `http://localhost:8888`, PHP 8.2) |
| Stop / destroy | `npm run env:stop` / `npm run env:destroy` |
| WP-CLI in env | `npm run env:cli -- <args>` |
| JS watch build | `npm run start` |
| JS production build | `npm run build` |
| JS lint | `npm run lint:js` |
| PHP lint (WPCS) | `composer lint` |
| PHP lint autofix | `composer lint:fix` |
| PHP static analysis | `composer stan` (PHPStan level 8) |
| PHP tests | `composer test` |
| Single test | `composer test -- --filter=PluginTest` |
| All gates | `composer check` |
| Release ZIP | `npm run plugin-zip` (drops `alpha-chat.zip`) |

CI runs `composer lint`, `composer stan`, `composer test`, `npm run lint:js`, `npm run build` on PHP 8.2 and 8.3 (`.github/workflows/ci.yml`). Tagging `v*` triggers a release build (`.github/workflows/release.yml`).

## Architecture

### Plugin boot

`alpha-chat.php` is the entry. It guards on PHP 8.2, defines constants (`ALPHA_CHAT_VERSION`, `ALPHA_CHAT_FILE`, `ALPHA_CHAT_PATH`, `ALPHA_CHAT_URL`, `ALPHA_CHAT_MIN_WP`, `ALPHA_CHAT_MIN_PHP`), requires the Composer autoload, registers activation/deactivation hooks, then calls `AlphaChat\Plugin::instance()->boot()` on `plugins_loaded`.

Boot order inside `Plugin::boot()`:
1. Load text domain (`alpha-chat`, `/languages`).
2. Wire services on the DI container (`Support\Container`).
3. Register REST routes, post hooks, indexing scheduler, Gutenberg block, widget loader, admin menu/assets.
4. Fire `alpha_chat_booted` with the container.

Activation (`src/Activator.php`) installs DB schema via `Database\Schema::install()` and seeds `alpha_chat_settings`, `alpha_chat_db_version`, `alpha_chat_installed_at`. Uninstall (`uninstall.php`) drops the three tables, all plugin options, plugin post meta, and Action Scheduler entries in the `alpha-chat` group.

### Layout

```
alpha-chat/
├── alpha-chat.php                  # Plugin header + boot
├── src/
│   ├── Plugin.php                  # Singleton bootstrap with DI wiring
│   ├── Activator.php / Deactivator.php
│   ├── Support/                    # Container, Logger
│   ├── Database/Schema.php         # dbDelta schema + table names
│   ├── Settings/SettingsRepository # Option read/write + sanitize + secret masking
│   ├── Text/                       # Chunker, TokenCounter, Similarity (pack/unpack/cosine)
│   ├── Http/                       # HttpClient wrapper + HttpException
│   ├── Providers/
│   │   ├── Contracts/              # LLMProvider, EmbeddingProvider, VectorStore
│   │   ├── OpenAI/                 # OpenAIChat, OpenAIEmbeddings, OpenAIModeration
│   │   ├── Anthropic/              # AnthropicChat (Messages API)
│   │   ├── VectorStore/            # DatabaseVectorStore, QdrantVectorStore
│   │   └── ProviderFactory.php     # Resolves providers from settings (all filterable)
│   ├── KnowledgeBase/              # Indexer + PostHooks (save_post / delete_post / trash)
│   ├── Scheduler/ReindexScheduler  # Action Scheduler pipeline with triple fallback
│   ├── Chat/                       # ThreadRepository, MessageRepository, ChatService
│   ├── REST/                       # RouteRegistrar + 4 controllers
│   ├── Admin/                      # AdminMenu, AdminAssets, PostRowActions
│   ├── Block/BlockRegistrar.php    # Registers the Gutenberg block
│   └── Frontend/WidgetLoader.php   # Enqueues and mounts the floating widget
├── assets/
│   ├── admin/                      # React admin dashboard
│   ├── widget/                     # React frontend widget
│   └── block/                      # Gutenberg block (block.json + render.php)
├── build/                          # Compiled assets (generated, gitignored)
├── tests/
│   ├── bootstrap.php               # Brain\Monkey setup
│   └── Unit/                       # Unit tests
└── .github/workflows/              # ci.yml, release.yml
```

### Conventions

- Namespace: `AlphaChat\` → `src/` (PSR-4). Test namespace: `AlphaChat\Tests\` → `tests/`.
- All PHP files start with `declare(strict_types=1);`.
- Prefer typed properties, enums, `readonly`, first-class callable syntax. Do not write PHP 7.4-compatible code.
- Indentation: tabs (size 4). Braces, quoting, whitespace follow WordPress Coding Standards via `phpcs.xml.dist`.
- Public hook API is prefixed `alpha_chat_` (filters/actions) — treat these as the extension surface and keep signatures stable.
- Globals, options, and table names all prefix with `alpha_chat_`.

### Data

Three tables (prefix `{wpdb->prefix}alpha_chat_`):
- `chunks` — source content chunks with embeddings (`source_type`, `source_id`, `chunk_index`, `content`, `embedding` BLOB, `content_hash`, `status`).
- `threads` — conversations (`uuid`, `user_id`, `session_hash`, `title`, `message_count`).
- `messages` — per-thread messages (`thread_id`, `role`, `content`, `token_count`, `metadata`).

Options: `alpha_chat_settings`, `alpha_chat_db_version`, `alpha_chat_installed_at`.

### REST

Namespace: `alpha-chat/v1`. Routes:

- `GET  /ping` — sanity check.
- `GET /POST /settings` — settings read/write. `manage_options` capability + `wp-nonce`. Secrets are masked on read; empty or bullet-only values on write preserve the stored secret.
- `GET /knowledge-base`, `POST /knowledge-base/{id}`, `DELETE /knowledge-base/{id}`, `POST /knowledge-base/reindex-all`.
- `GET /threads`, `GET /threads/{id}`, `DELETE /threads/{id}`, `GET /threads/chart`, `GET /threads/export` (CSV).
- `GET /POST /faqs`, `PUT /DELETE /faqs/{id}`, `POST /faqs/preview`, `POST /faqs/import`.
- `POST /chat` and `POST /chat/stream` — public endpoints. Require a `wp_rest` nonce in `X-WP-Nonce`. Send `origin_url` and `origin_title` so the current page is injected into the prompt.

Add new routes by hooking `alpha_chat_register_rest_routes` (fired with the namespace and container).

### Providers

Three interfaces live under `src/Providers/Contracts/`. Concrete implementations in `OpenAI/`, `Anthropic/`, `VectorStore/`. Resolution happens in `ProviderFactory` based on `settings->llm_provider` / `settings->vector_store`, and each result is wrapped in a filter so third-party code can swap them. Keep provider code behind these interfaces so the LLM, the embedding model, and the vector store can each be swapped independently.

### Retrieval

`Chat\ChatService::send()` orchestrates RAG:

1. Moderation check (if enabled) — fallback response on flag.
2. Load/create thread by uuid; persist user message.
3. Embed the query via `ProviderFactory::embeddings()`.
4. `VectorStore::search()` with `max_context_chunks` and `similarity_score_threshold`.
5. `alpha_chat_retrieved_chunks` filter.
6. Load last 12 messages for history; build `system + context + history + user` prompt.
7. `LLMProvider::complete()` with temperature / top_p / max_tokens from settings.
8. Persist assistant message with sources + usage in metadata; fire `alpha_chat_message_answered`.

## Rules of engagement

- **No Hyve / ThemeIsle / Codeinwp carryover.** This is a clean-room build. Don't import their names, namespaces, option keys, hook names, or code patterns. The older reference CLAUDE.md is gone for this reason.
- **No ads, no upsells, no telemetry.** FluentCart licensing is limited to free-key activation, deactivation, version checks, and signed update packages from gauravtiwari.org. Do not add any broader phone-home behavior.
- **Treat `build/` and `vendor/` as generated.** Don't hand-edit. Don't commit them (see `.gitignore`).
- **Prefer hooks over core patches.** When adding a capability, design the hook first, then the implementation.
- **Async work** goes through Action Scheduler (bundled via Composer `woocommerce/action-scheduler`) — not `wp_schedule_event`. Use the `as_schedule_single_action` / `as_enqueue_async_action` APIs.
