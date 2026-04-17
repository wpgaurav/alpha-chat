# Alpha Chat

AI-powered chatbot for WordPress. Indexes your site content, answers visitor questions with retrieval-augmented generation, ships with a site-database vector store, pluggable providers, and a shadow-DOM-isolated chat widget.

## Highlights

- **Grounded answers** — RAG over your posts, pages, and any public custom post type. Cites the sources it used.
- **No external services required** — vector store lives in `wp_alpha_chat_chunks` (MySQL). Only the LLM + embedding provider calls leave your site.
- **Providers** — OpenAI (`gpt-5.4`, `gpt-5.4-mini`, `gpt-4.1`) and Anthropic (`claude-opus-4-7`, `claude-sonnet-4-6`, `claude-haiku-4-5`). Embeddings via OpenAI `text-embedding-3-small` / `-large`.
- **Token-frugal** — empty retrieval returns the configurable fallback without calling the LLM.
- **Performance-first** — floating launcher is opt-in. Chat assets only load on pages using the block or `[alpha_chat]` shortcode. Script uses `defer`.
- **Theme-proof widget** — mounts inside a Shadow DOM with all styles inlined; no host CSS bleeds in.
- **Contact form** — if a visitor still needs help, Name / Email / Message get stored and emailed to the admin via `wp_mail()`.
- **Rate-limited** — transient-backed limits on chat (30/min per session) and contact (5/hr per IP).

## Requirements

- WordPress 6.5+
- PHP 8.2+
- Node 20+ (for building assets during development)

## Install

1. Upload `alpha-chat.zip` via **Plugins → Add New → Upload Plugin**, or clone this repo into `wp-content/plugins/alpha-chat` and run `composer install --no-dev && npm install && npm run build`.
2. Activate.
3. Go to **Alpha Chat → Settings** and add your OpenAI API key (required for embeddings + moderation). Add an Anthropic key only if you pick Claude as the chat model.
4. **Knowledge Base** tab → filter *Not indexed* → **Index remaining** to ingest the site.
5. Add `[alpha_chat]` to any page (or drop in the "Alpha Chat" Gutenberg block), or turn on **Settings → Behavior → Show floating launcher site-wide**.

## Admin

- **Dashboard** — activity metrics + 14-day sparkline.
- **Knowledge Base** — list every public post type; filter by type and by indexed/not-indexed; select rows to batch index or remove; "Index remaining" queues all unindexed items via Action Scheduler.
- **Conversations** — thread history with per-message tokens + usage + sources; CSV export.
- **Contacts** — submissions from the in-chat contact form.
- **Settings** — provider, model, preset (Fast/Balanced/Quality), system prompt, welcome/fallback copy, launcher position (left/center/right), nudge text, brand name, contact form, widget colors (accent / panel / user bubble / assistant bubble), advanced tuning (temperature, top_p, max response tokens, similarity threshold, max context chunks).

## Architecture

```
alpha-chat/
├── alpha-chat.php                       # plugin entry + PHP/WP guards
├── src/
│   ├── Plugin.php                       # DI container + boot
│   ├── Activator.php / Deactivator.php
│   ├── Support/                         # Container, Logger, AssetManifest
│   ├── Database/Schema.php              # chunks / threads / messages / contacts + maybe_upgrade()
│   ├── Settings/SettingsRepository.php  # options, sanitize, secret masking
│   ├── Text/                            # Chunker, TokenCounter, Similarity (pack/unpack/cosine)
│   ├── Http/HttpClient.php              # wp_remote_post wrapper
│   ├── Providers/
│   │   ├── Contracts/                   # LLMProvider, EmbeddingProvider, VectorStore
│   │   ├── OpenAI/                      # Chat, Embeddings, Moderation
│   │   ├── Anthropic/AnthropicChat.php
│   │   ├── VectorStore/DatabaseVectorStore.php
│   │   └── ProviderFactory.php          # resolves from settings, filterable
│   ├── KnowledgeBase/                   # Indexer + PostHooks (save/delete/trash)
│   ├── Scheduler/ReindexScheduler.php   # Action Scheduler pipeline
│   ├── Chat/                            # ThreadRepository, MessageRepository, ContactRepository, ChatService
│   ├── REST/                            # RouteRegistrar + 5 controllers
│   ├── Admin/                           # AdminMenu, AdminAssets, PostRowActions
│   ├── Block/BlockRegistrar.php         # Gutenberg block + render_callback (inline mount)
│   └── Frontend/WidgetLoader.php        # shortcode + maybe_enqueue + localize
├── assets/
│   ├── admin/                           # React dashboard
│   ├── widget/                          # React widget (shadow DOM + inline CSS)
│   └── block/                           # Gutenberg block metadata + editor
├── build/                               # compiled bundles (generated)
└── tests/Unit/                          # PHPUnit + Brain\Monkey
```

### Retrieval flow

`ChatService::send()`:
1. Moderation (if enabled) → fallback on flag.
2. Load/create thread, persist user message.
3. Embed query via `ProviderFactory::embeddings()`.
4. `VectorStore::search()` with `max_context_chunks` + `similarity_score_threshold`.
5. `alpha_chat_retrieved_chunks` filter runs; chunks are enriched with title / permalink / thumbnail.
6. **If chunks are empty → return fallback immediately, skip the LLM.**
7. Otherwise: build `system + context + history + user`, call `LLMProvider::complete()`, persist with source metadata.

## Develop

```bash
composer install
npm install
npm run env:start       # wp-env on http://localhost:8888 (PHP 8.2)
npm run start           # JS watch build
```

Quality gates:

```bash
composer lint           # PHPCS (WordPress Coding Standards)
composer stan           # PHPStan level 8
composer test           # PHPUnit
composer check          # all of the above
npm run lint:js
npm run build
npm run plugin-zip      # builds alpha-chat.zip
```

CI (`.github/workflows/ci.yml`) runs lint + stan + test on PHP 8.2 and 8.3 plus the JS build. Pushing a `v*` tag triggers a release build.

## Extension points

See `HOOKS.md` for the full list. Highlights:

| Hook | Kind | Purpose |
| --- | --- | --- |
| `alpha_chat_llm_provider` | filter | Swap the chat LLM. |
| `alpha_chat_embedding_provider` | filter | Swap the embedding model. |
| `alpha_chat_vector_store` | filter | Swap the vector store (default: site DB). |
| `alpha_chat_indexable_post_types` | filter | Restrict/extend which post types are indexable. |
| `alpha_chat_retrieved_chunks` | filter | Post-process chunks before they go to the prompt. |
| `alpha_chat_default_settings` | filter | Override defaults. |
| `alpha_chat_settings_sanitize` | filter | Hook sanitization. |
| `alpha_chat_display_widget` | filter | Decide whether the floating widget loads on the current request. |
| `alpha_chat_booted` | action | DI container is ready. |
| `alpha_chat_post_indexed` | action | A post finished indexing. |
| `alpha_chat_message_answered` | action | A reply was persisted. |
| `alpha_chat_contact_submitted` | action | A contact form was submitted. |
| `alpha_chat_unanswered_question` | action | Retrieval returned nothing. |
| `alpha_chat_message_flagged` | action | Moderation flagged a message. |

## Privacy

- Chat messages and visitor metadata (session hash of IP+UA, never the raw IP) are stored in `wp_alpha_chat_threads` and `wp_alpha_chat_messages`.
- Contact submissions are stored in `wp_alpha_chat_contacts` with a hashed IP.
- Chat and embedding requests go to the provider you configure. No telemetry is sent to any other endpoint.
- Uninstall (`uninstall.php`) drops all plugin tables, options, post meta, and scheduled Action Scheduler jobs.

## License

GPL-2.0-or-later.
