# Changelog

All notable changes to Alpha Chat will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-08-18

### Added
- **Launcher offset.** Four new fields under Settings → Launcher set how far the
  floating button and panel sit from the bottom and side of the screen, in pixels,
  with separate values for desktop and mobile. The launcher was pinned 20px from
  the edges, so on themes with their own fixed bottom bar — a mobile nav, a share
  row, a cookie strip — it covered the controls underneath it and there was no way
  to move it. Values are clamped to 0-400px, and the panel's height subtracts the
  bottom offset so raising the widget cannot push the panel off the top of the
  screen. Defaults reproduce the previous position exactly. Applied as CSS custom
  properties evaluated per visitor, so the desktop/mobile split stays correct
  behind a full-page cache.

## [0.4.1] - 2026-08-18

### Fixed
- **Fatal error when the plugin update cache was cleared.** `clear_update_cache()`
  is hooked on `delete_site_transient_update_plugins` and deleted that same
  transient, which fired the action again; the hook re-entered itself until PHP
  exhausted memory ("Allowed memory size exhausted"). Any code path that deletes
  the `update_plugins` site transient triggered it, including `wp plugin list`
  after a cache flush. The method now holds a re-entry guard.

## [0.4.0] - 2026-08-17

### Added
- **Error log.** Errors and warnings are recorded to a new table and shown under
  Alpha Chat → Logs, with level filter, expandable context, source attribution, and
  a clear button. Provider failures used to be invisible unless the site owner read
  the PHP error log. Anything resembling a credential is stripped before an entry is
  stored, and entries are pruned daily to 30 days and 2000 rows.
- **AI conversation titles.** Conversations are named from their opening exchange by
  the configured chat provider, replacing a truncation of the first message. Runs as
  a scheduled action with a 24-token budget, so it never delays or endangers a reply,
  and each conversation is named once. Toggle under Behavior.
- **Per-device launcher visibility.** The floating button can be shown or hidden
  independently on desktop, tablet, and mobile. Applied by screen width in the
  browser, not by server-side user-agent detection, so it stays correct behind a
  full-page cache. Blocks and shortcodes are unaffected.
- `alpha_chat_pre_llm_provider` and `alpha_chat_pre_embedding_provider` filters, which
  replace a provider before the built-in one is constructed. The existing
  `alpha_chat_llm_provider` filter can only decorate an instance that already exists,
  so swapping in your own provider previously still required configuring an API key
  for a built-in provider you were not using.
- `alpha_chat_log_retention_days`, `alpha_chat_log_max_rows` and
  `alpha_chat_thread_titled` hooks.

### Fixed
- A conversation showed `0` messages and a bare uuid in the admin whenever a reply
  failed. The message count and title were only written after a successful
  completion, even though the visitor's message had already been stored. Both are now
  written when the message is stored, and the count is derived from the stored rows.
- Fallback titles contained a literal `&hellip;` entity instead of an ellipsis.
- Provider and configuration failures return HTTP 503 rather than 502. A CDN in front
  of the site replaces a 502 body with its own error page, so the actual reason never
  reached the widget or the site owner.

### Database
- Schema 1.6.0 adds the `alpha_chat_logs` table and a `title_generated` column on
  `alpha_chat_threads`. Applied automatically on upgrade.

## [0.3.2] - 2026-08-17

### Fixed
- Assistant replies showed raw Markdown. Models answer in Markdown, but the widget
  rendered the reply as plain text, so visitors saw literal `**bold**` and `-`
  bullets instead of formatting. Replies now render bold, italic, inline code,
  fenced code blocks, links, headings, and bullet and numbered lists.

### Security
- The Markdown renderer builds React elements rather than an HTML string, so reply
  text — which is shaped by retrieved site content and curated Q&A — can never
  inject markup. The widget still contains no `dangerouslySetInnerHTML`. Links are
  restricted to `http`, `https` and `mailto`; a `javascript:` URL renders as plain
  text and every link carries `rel="noopener noreferrer"`.

### Notes
- A visitor's own message is still rendered verbatim, not as Markdown.

## [0.3.1] - 2026-08-17

### Fixed
- Chat failed with every reasoning model. The stored temperature was sent on each
  request, and reasoning models (`gpt-5*`, `o1*`, `o3*`, `o4*`) reject any sampling
  value other than the default, failing the whole request with
  `'temperature' does not support 0.7 with this model`. `temperature`, `top_p`,
  `presence_penalty` and `frequency_penalty` are now omitted for those models
  instead of being sent with a value they refuse. Conventional models such as
  `gpt-4o` are unaffected and still receive the configured values.

### Added
- `alpha_chat_is_reasoning_model` filter, for a model the bundled prefix list does
  not cover yet.

## [0.3.0] - 2026-08-17

Security and correctness release from a full audit of the plugin. Please read the
upgrade notes below if your site sits behind a proxy other than Cloudflare, or if
anything calls the contact endpoint directly.

### Security
- Rate limiting no longer trusts `X-Forwarded-For` from arbitrary callers. The header
  was accepted unconditionally, so rotating it gave every request its own bucket and
  left provider spend effectively uncapped. Forwarding headers are now honoured only
  when the request actually arrives from a trusted proxy. Cloudflare's published edge
  ranges are trusted out of the box; anything else is declared with the
  `ALPHA_CHAT_TRUSTED_PROXIES` constant or the `alpha_chat_trusted_proxies` filter.
- Added a site-wide chat and contact ceiling that no client-supplied identifier can
  partition, as a backstop on total provider spend.
- `POST /contact` now requires a valid `wp_rest` nonce. It was previously open to
  unauthenticated callers and sent mail on every request.
- Resuming a conversation now requires the caller to own it. A thread uuid alone used
  to be enough, and history is replayed into the prompt, so a leaked uuid exposed the
  earlier conversation.
- The current-page context injected into the prompt is now restricted to this site.
  An off-site `origin_url` with an attacker-chosen `origin_title` could previously
  write arbitrary text into the system prompt.
- Transcript CSV export now neutralises spreadsheet formula injection from
  visitor-authored message content.
- Masked API keys are a fixed width and no longer reveal the real key length.

### Fixed
- A single chat message could trigger more than one paid completion. The widget
  retried across transports after the server had already answered, duplicating both
  the provider charge and the stored thread history. It now retries only when the
  request never reached the plugin.
- Transcript CSV export returned a JSON-encoded string rather than a CSV file, so the
  download could not be opened as a spreadsheet. It now streams real CSV, row by row,
  and no longer loads the whole archive into memory.
- The "Add to Alpha Chat" post row action rendered a button whose handler was never
  loaded, so clicking it did nothing.
- Stored API keys could not be cleared from the settings screen.
- A chat widget on a cached page recovers from an expired nonce instead of failing
  with a message that a reload could not fix.
- Restoring a post from the trash returns it to the knowledge base.
- `delete_post` no longer runs knowledge-base cleanup for revisions and autosaves.
- Update checks cache failures, so an unreachable licence server no longer adds a
  blocking request to every admin page load.
- Token counting handles CJK text, which was undercounted roughly fourfold and
  produced oversized chunks.
- Follow-up detection no longer treats every short message as a follow-up, which was
  dragging the previous topic into retrieval after a clean subject change.
- Uninstall cleans every site on a multisite network and unschedules its Action
  Scheduler jobs; network activation installs tables on every site.

### Changed
- Curated Q&A is now ranked against the question and capped before it enters the
  prompt, instead of sending every enabled entry on every message.
- Settings are resolved once per request rather than rebuilt on each read.
- Retrieval scans a bounded candidate set when the fulltext index returns nothing.
- Errors and warnings are logged without requiring `WP_DEBUG`.
- Outbound fetches for the FAQ importer go through `wp_safe_remote_request`, and the
  pre-flight check covers IPv6 and literal addresses.
- The settings response reports whether moderation is actually active, which requires
  an OpenAI key regardless of the chat provider.

### Added
- `alpha_chat_trusted_proxies`, `alpha_chat_trust_cloudflare`, `alpha_chat_rate_limit`,
  `alpha_chat_max_prompt_faqs`, `alpha_chat_fallback_candidates`,
  `alpha_chat_max_queued_posts` and `alpha_chat_should_log` filters.
- `GET /nonce` for widgets on cached pages.

### Upgrade notes
- Behind Cloudflare, nothing to do. Behind any other proxy or load balancer, declare
  it or anonymous visitors will share rate-limit buckets:
  `define( 'ALPHA_CHAT_TRUSTED_PROXIES', '10.0.0.0/8' );`
- Anything posting to `/alpha-chat/v1/contact` must now send an `X-WP-Nonce` header.
- `ChatController::is_rate_limited()` is deprecated in favour of
  `AlphaChat\Support\RateLimiter::hit()`.

## [0.2.1] - 2026-08-17

### Fixed
- Public chat no longer dies with `Unexpected token '<'` when `/wp-json/` returns HTML. The widget retries `?rest_route=/alpha-chat/v1/chat` and shows a retry message instead of the fallback answer plus a parse dump.

### Changed
- Dashboard cards, queue tiles, and the activity chart are interactive. Range is 7 / 14 / 30 days.
- Dashboard uses one `GET /dashboard` request. Queue polling only runs while jobs are active and the tab is visible.

## [0.2.0] - 2026-08-17

### Added
- Curated Q&A can be imported from page URLs. The plugin prefers the local post, then the WordPress REST API (`/wp-json/wp/v2/pages` and `/posts`), then HTML. It reads FAQPage JSON-LD, Rank Math / Yoast FAQ blocks, `<details>`, definition lists, accordion markup, and headings that end with `?`.
- `POST /faqs/preview` and `POST /faqs/import` (manage_options).
- `alpha_chat_faq_extracted` and `alpha_chat_page_context` filters.

### Changed
- Public chat uses a `wp_rest` nonce so WordPress cookie authentication no longer returns "Cookie check failed".
- Every chat request sends the current page URL and document title. Page resolution now handles fragments, `www`, the static front page, and path fallbacks. Retrieval prefers chunks from that post.
- Settings and Q&A admin spacing is looser: larger section padding, field gaps, and a clearer import panel.

## [0.1.8] - 2026-08-17

### Added
- Streaming chat at `POST /alpha-chat/v1/chat/stream` (SSE). The widget streams tokens and falls back to JSON `/chat` if the host buffers the stream.
- Hybrid retrieval: FULLTEXT candidate cut, follow-up query rewrite, and a score boost for the current page’s chunks.
- Source cards now show at most three posts the reply actually used. Admin metadata still stores the full `retrieved` set.
- Mapped Thinking control (`off` / `low` / `medium` / `high`) for OpenAI, Grok, and DeepSeek. Default and all presets are `low`. xhigh/max are not sent.
- Voyage AI as an independent embedding provider (`voyage-4-lite`, `voyage-4`, `voyage-4-large`). Chat, embeddings, and moderation are now separate settings.
- `alpha_chat_moderation_provider` filter and a no-op moderator when no OpenAI key is set.
- xAI (Grok) and DeepSeek as chat-only providers, using the same Chat Completions client as OpenAI.
- PHP `ModelCatalog` as the admin source of truth. `GET /settings` now returns `{ settings, stats, catalog }`.
- Settings secrets `xai_api_key` and `deepseek_api_key`.
- `alpha_chat_model_catalog` and `alpha_chat_http_timeout` filters.

### Changed
- OpenAI chat models are now GPT-5.6 Luna / Terra / Sol. New installs default to `gpt-5.6-luna`.
- Retired OpenAI IDs (`gpt-5.4-mini`, `gpt-5.4`, `gpt-4.1`) remap on settings read so the dropdown is never blank; the new ID is persisted on Save.
- Provider HTTP timeout raised from 30s to 60s.
- OpenAI is no longer required for embeddings or chat. Retrieval search only uses chunks from the active embedding model.
- Public `/chat` and `/chat/stream` require the `alpha_chat_frontend` nonce the widget already localizes.

## [0.1.7] - 2026-08-01

### Added
- Free lifetime FluentCart licensing with an Alpha Chat license screen.
- Protected automatic updates through the normal WordPress plugin updater.

### Changed
- The canonical download is now delivered through the Alpha Chat FluentCart product and customer account.

## [0.1.6] - 2026-04-18

### Changed
- System prompt no longer restricts the assistant to "context only" knowledge. The LLM can now draw on general knowledge to explain, clarify, or summarize when retrieval is thin.
- Every chat request injects the current page (URL, title, up to 4,000 chars of post content via `url_to_postid()`) into the system prompt so "explain this", "summarize this article", and similar queries work without vector-store hits.

## [0.1.5] - 2026-04-17

### Added
- Threads now capture the originating page URL (`origin_url`) when a conversation begins. Frontend widget sends `window.location.href` with the first chat request; admin Conversations tab displays it as a clickable link in the detail panel; CSV export includes it as a new column.
- New `origin_url VARCHAR(500)` column on `wp_alpha_chat_threads`. Schema version bumped to `1.4.0` — `dbDelta` adds the column automatically on existing installs.

## [0.1.4] - 2026-04-17

### Changed
- Removed the hard "no retrieval → fallback" gate in `ChatService`. The LLM is now always called and instructed to handle conversational messages (greetings, thanks, goodbyes, small talk) naturally, reserving the configured fallback message verbatim only for genuine unanswerable factual questions.

### Fixed
- "Thanks", "hi", "bye", and similar conversational messages no longer return the "Sorry, I couldn't find an answer" fallback.

## [0.1.3] - 2026-04-17

### Changed
- Frontend widget bundles React directly instead of relying on WordPress core's `wp-element` / `wp-dom-ready` script handles. The widget no longer enqueues `react`, `react-dom`, `react-jsx-runtime`, `wp-element`, `wp-i18n`, `wp-hooks`, `wp-dom-ready`, or `wp-escape-html` on the frontend.

### Performance
- Frontend widget loads as a single ~48 KiB gzipped script instead of seven separate WordPress core script requests.

## [0.1.2] - 2026-04-17

### Added
- **Q&A admin tab** for curated question/answer pairs. New `wp_alpha_chat_faqs` table, REST CRUD at `/faqs`, and context-injection into every chat request.
- Source links now carry UTM parameters (`utm_source=alpha_chat`, `utm_medium=chat_widget`, `utm_campaign=ai_answer`, `utm_referrer={host}`).

### Changed
- Brand identity is prepended to the system prompt on every request. "Who are you?" and similar prompts now resolve without needing RAG context.
- Retrieval gate widened: LLM is called when either chunks OR FAQs are available; fallback only when both are empty.
- System prompt now explicitly tells the LLM **not** to emit bracketed citation markers — frontend strips any that slip through.

### Fixed
- Identity-style questions returning fallback even with an indexed site.

## [0.1.1] - 2026-04-17

### Added
- `Indexer::index_post` now stores a SHA-256 content hash + embedding model per post and **skips re-embedding when nothing has changed**. Makes "Reindex all" idempotent and cheap.
- New `GET /knowledge-base/queue` endpoint returning `pending / in_progress / complete / failed` counts for the `alpha-chat` Action Scheduler group.
- New `POST /knowledge-base/queue` endpoint runs one Action Scheduler batch synchronously (useful when `DISABLE_WP_CRON` is set).
- Dashboard shows live indexing queue counts with auto-refresh every 5s while work is in flight, plus a one-click "Process now" button.
- Knowledge Base toolbar gains a "Process queue now" button.

### Fixed
- Chat panel close button was invisible on mobile — SVG had no explicit size inside the shadow DOM's `all: initial` root. Added a global `svg` rule + sized close icon; close is now a circular button in the header top-right.
- Re-queued bulk jobs no longer re-embed already-indexed content (hash guard).

### Changed
- Removed `:host` reset for all SVGs globally, ensuring any icon placed in the widget renders at its CSS size.

### Added

- Initial public release.
- Plugin bootstrap with PSR-4 autoloading, DI container, and PHP 8.2 floor.
- Custom tables: `alpha_chat_chunks`, `alpha_chat_threads`, `alpha_chat_messages`, `alpha_chat_contacts`. `Schema::maybe_upgrade()` runs `dbDelta` on boot for idempotent migrations.
- Pluggable provider interfaces: `LLMProvider`, `EmbeddingProvider`, `VectorStore`.
- OpenAI chat (GPT-5.4 / GPT-5.4 mini / GPT-4.1), embeddings, and moderation integrations.
- Anthropic Messages API chat (Claude Opus 4.7 / Sonnet 4.6 / Haiku 4.5).
- Database-backed vector store (site DB, no external service) with PHP-side cosine similarity.
- Knowledge base indexer with paragraph/sentence-aware chunker and token counter.
- Indexes **all public post types** (filterable via `alpha_chat_indexable_post_types`).
- Action Scheduler async reindex pipeline with `save_post`, `delete_post`, and `wp_trash_post` hooks.
- REST API under `alpha-chat/v1`: `/chat`, `/contact`, `/contacts`, `/settings`, `/knowledge-base`, `/knowledge-base/bulk`, `/knowledge-base/index-remaining`, `/knowledge-base/post-types`, `/threads`, `/threads/chart`, `/threads/export`, `/ping`.
- Admin React app: Dashboard, Knowledge Base, Conversations, Contacts, Settings.
- Knowledge Base admin: post-type filter, indexed/not-indexed filter, batch-select bulk actions (index/remove), "Index remaining" button to queue unindexed items.
- Settings UI with presets (Fast / Balanced / Quality), provider + model dropdowns, widget design colors, launcher position (left / center / right), customizable nudge text and brand name, contact form toggles + notify email.
- Frontend chat widget mounted inside a **Shadow DOM** with inline styles and `all: initial` reset — fully isolated from theme/plugin CSS.
- Minimal SVG chat icon + customizable nudge pill beside the launcher.
- Gutenberg block and `[alpha_chat]` shortcode for inline embedding.
- Floating launcher is **opt-in** (off by default) — when off, chat assets load only on pages with the block or shortcode. Script uses `defer` strategy.
- Source cards in assistant replies (title + thumbnail + link to post).
- **Token-frugal retrieval**: empty retrieval returns configurable fallback without calling the LLM.
- In-chat contact form (Name + Email + Message) — stores in `wp_alpha_chat_contacts`, sends email via `wp_mail()` to configurable notify address (falls back to `admin_email`), with `Reply-To: visitor`.
- Settings API with secret masking (bullet placeholder preserves existing values).
- Capability-gated admin REST endpoints. Public chat and contact endpoints with transient-backed rate limiting (30 chat req/min per session, 5 contact/hour per IP).
- Stale-error cleanup: saving a valid OpenAI API key clears `_alpha_chat_index_error` post meta so previously-failed items can be retried.
- Uninstall routine drops all plugin tables, options, post meta, and scheduled Action Scheduler jobs.
- PHPUnit + Brain\Monkey unit tests for Chunker, TokenCounter, Similarity, and SettingsRepository.
- PHPStan level 8, WordPress Coding Standards, and CI workflows for PHP 8.2 / 8.3.
- Release workflow triggered on `v*` tags — builds a production ZIP and attaches it to the GitHub release.
