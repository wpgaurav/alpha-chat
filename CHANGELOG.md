# Changelog

All notable changes to Alpha Chat will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
