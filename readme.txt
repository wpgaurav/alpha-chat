=== Alpha Chat ===
Contributors: alphachat
Tags: ai, chatbot, openai, anthropic, rag, gpt, claude, grok, deepseek
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot for WordPress. Grounds answers in your own content, ships with a Shadow-DOM-isolated widget, built-in vector store, and a contact form that emails your inbox.

== Description ==

Alpha Chat turns your WordPress content into a conversational interface. It splits your posts, pages, and any public custom post type into chunks, embeds them with your chosen provider, and retrieves the most relevant passages at query time so every answer is grounded in your own site. When it can't answer, it skips the LLM call (no tokens spent) and offers the visitor a contact form that lands in your admin email.

= Why it's different =

* **Your content, not the model's training data.** Retrieval-augmented generation keeps answers on-site.
* **No external vector database.** A MySQL-backed store with cosine similarity in PHP is the default. No Pinecone, no Qdrant, no subscription.
* **Performance-first.** The floating launcher is opt-in; chat assets only load on pages that use the block or [alpha_chat] shortcode. Script uses `defer`. Empty retrievals never call the LLM.
* **Theme-proof widget.** Rendered inside a Shadow DOM with styles inlined — no theme CSS can override it.
* **Token-frugal.** If retrieval returns nothing, the visitor gets your fallback message without any provider request.
* **Privacy-respecting.** No telemetry. Your site contacts gauravtiwari.org only when you activate, deactivate, or check a free license for protected updates. Uninstalling cleans up every table and option.

= Features =

* OpenAI (GPT-5.6 Luna, Terra, Sol), Anthropic (Claude Opus 4.7, Sonnet 4.6, Haiku 4.5), xAI (Grok 4.6 / 4.5), and DeepSeek (V4 Flash / Pro) chat providers. Embeddings are independent: OpenAI or Voyage AI. Moderation is optional and only uses OpenAI when that key is set.
* OpenAI text-embedding-3-small / -large for embeddings.
* Quick presets: Fast / Balanced / Quality — sets model, temperature, and response length in one click.
* Site-database vector store (default) with pluggable `VectorStore` interface.
* Gutenberg block and `[alpha_chat]` shortcode for inline embedding.
* Floating launcher with customizable nudge text, position (left / center / right), brand name, and color scheme.
* Source cards attached to each reply (title + thumbnail + link to the post).
* In-chat contact form (Name + Email + Message) when the visitor still needs help; stored in `wp_alpha_chat_contacts` and emailed to the configured admin address via `wp_mail()`.
* Knowledge Base admin: filter by post type + indexed state, batch select, bulk index / remove, one-click "Index remaining" for catching up new content.
* Action Scheduler-based async indexing with save_post / trash hooks.
* Moderation via OpenAI moderation endpoint.
* Conversation history with token + usage + sources metadata and CSV export.
* Rate-limited endpoints (30 chat req/min per session, 5 contact submissions/hour per IP).
* Filters and actions throughout: `alpha_chat_llm_provider`, `alpha_chat_embedding_provider`, `alpha_chat_vector_store`, `alpha_chat_indexable_post_types`, `alpha_chat_retrieved_chunks`, `alpha_chat_display_widget`, and more.

= Philosophy =

* No upsells, no ads, no telemetry.
* GPLv2-or-later, clean-room build.
* Explicit opt-in before anything is sent to an external provider.

== Installation ==

1. Upload `alpha-chat.zip` via **Plugins → Add New → Upload Plugin**, or copy the plugin folder to `/wp-content/plugins/alpha-chat`.
2. Activate it from the Plugins screen.
3. Go to **Alpha Chat → Settings**. Pick chat and embedding providers independently. Add an OpenAI key only if you use OpenAI for chat, embeddings, or moderation. Voyage can embed without OpenAI.
4. Open the **Knowledge Base** tab, filter *Not indexed*, and click **Index remaining** to ingest your site.
5. Drop the **Alpha Chat** block or `[alpha_chat]` on any page, or enable **Behavior → Show floating launcher site-wide**.
6. Open **Alpha Chat → License** and activate the free lifetime key from your FluentCart receipt or account to receive protected updates.

== Frequently Asked Questions ==

= Does it send my content to external services? =

Only to the provider you configure. When you add a post to the knowledge base, its text is embedded via your chosen provider. When a visitor chats, their message plus the matching context chunks are sent to the chat provider. Nothing is sent anywhere else.

= Does it work without an external vector database? =

Yes. The default store is a MySQL table with cosine similarity computed in PHP. Comfortable up to tens of thousands of chunks on typical hosts. If you outgrow it, implement the `VectorStore` interface and swap via the `alpha_chat_vector_store` filter.

= Can I use Claude, Grok, or DeepSeek instead of OpenAI? =

Yes. Settings → Provider, pick Anthropic, xAI (Grok), or DeepSeek, then add that provider's API key. Embeddings are a separate setting: keep OpenAI or switch to Voyage AI. Turn moderation off if you do not have an OpenAI key. Claude, Grok, and DeepSeek do not offer embedding or moderation APIs.

= Will it slow my site down? =

By default, no. The floating launcher is opt-in. When off, chat assets (~20KB JS) only load on pages where you've placed the block or shortcode. The script is `defer`-loaded. Empty retrievals don't even call the LLM.

= My theme styles are breaking the widget =

They can't — the widget mounts inside a Shadow DOM with styles inlined and an `all: initial` reset at the root. If you see something off, open an issue.

= What happens if the AI doesn't know the answer? =

You configure a fallback message. If retrieval returns no relevant chunks, the visitor sees your fallback — no LLM call is made, so no tokens are spent. If the "contact form" is enabled, visitors also get a "Still need help? Email us" button after the first exchange.

= Can I swap in my own provider? =

Yes. Implement `AlphaChat\Providers\Contracts\LLMProvider`, `EmbeddingProvider`, or `VectorStore` and override via `alpha_chat_llm_provider`, `alpha_chat_embedding_provider`, or `alpha_chat_vector_store`.

= Which post types are indexed? =

All public post types except `attachment`. Use the `alpha_chat_indexable_post_types` filter to restrict or extend the list.

= Is visitor data kept private? =

IPs are hashed before storage. Thread and message tables store only the message content, a session hash, and optional user ID. Uninstalling the plugin removes every table, option, and scheduled job.

== Screenshots ==

1. Dashboard — messages and sessions activity over 14 days.
2. Knowledge Base — filter by post type + indexed state, batch index / remove, "Index remaining".
3. Conversations — thread list + message inspector with CSV export.
4. Contacts — submissions from the in-chat contact form.
5. Settings — preset picker, provider/model dropdowns, launcher position, widget colors, contact form options.
6. Widget — floating nudge prompt, chat panel with source cards.

== Changelog ==

= 0.3.1 =
* Fixed: chat failed on every reasoning model (GPT-5 family, o1/o3/o4). Those models reject any temperature other than the default, so the stored value made every request fail with "'temperature' does not support 0.7 with this model". Sampling fields are now omitted for reasoning models. Conventional models such as GPT-4o still receive your configured temperature and top_p.
* Added: `alpha_chat_is_reasoning_model` filter for models the bundled list does not cover yet.

= 0.3.0 =
Security and correctness release from a full audit.

* Security: rate limiting no longer trusts `X-Forwarded-For` from arbitrary callers, which previously let any caller bypass the cap and drive unbounded AI provider spend. Cloudflare edge ranges are trusted automatically; other proxies use the `ALPHA_CHAT_TRUSTED_PROXIES` constant.
* Security: added a site-wide ceiling on chat and contact requests that no client-supplied identifier can partition.
* Security: the contact endpoint now requires a WordPress REST nonce. It was open to unauthenticated callers and sent mail on every request.
* Security: resuming a conversation now requires ownership, so a leaked thread ID no longer exposes earlier messages.
* Security: current-page prompt context is restricted to your own site, closing a prompt-injection path through the page title and URL.
* Security: CSV export neutralises spreadsheet formula injection from visitor messages.
* Fixed: one chat message could bill more than one AI completion when the widget retried across transports.
* Fixed: Conversations CSV export produced a JSON string instead of a usable CSV file, and no longer loads the whole archive into memory.
* Fixed: the "Add to Alpha Chat" row action on Posts and Pages did nothing when clicked.
* Fixed: API keys can now be cleared from the settings screen.
* Fixed: the widget recovers automatically from an expired nonce on cached pages.
* Fixed: restoring a post from the trash returns it to the knowledge base.
* Fixed: update checks cache failures instead of adding a blocking request to every admin page load.
* Fixed: CJK token counting, multisite uninstall and network activation.
* Changed: curated Q&A is ranked and capped before entering the prompt instead of sending every entry on every message.
* Changed: errors are logged without requiring WP_DEBUG.

Upgrade note: if your site is behind a proxy other than Cloudflare, define ALPHA_CHAT_TRUSTED_PROXIES in wp-config.php. Anything calling /alpha-chat/v1/contact directly must now send an X-WP-Nonce header.

= 0.2.1 =
* Fixed the widget treating HTML error pages as JSON ("Unexpected token <"). Chat now retries the rest_route URL and shows a plain retry message.
* Dashboard cards, queue tiles, and the activity chart are clickable. 7/14/30 day range and Refresh added.
* Dashboard loads stats, queue, and chart in one request.

= 0.2.0 =
* Import curated Q&A from page URLs via the WordPress REST API, FAQ schema, accordions, and question headings.
* Chat now sends the current page URL and title on every message and resolves homepage / pretty permalinks more reliably.
* Fixed "Cookie check failed" on public chat by using a standard WordPress REST nonce.
* Loosened settings spacing and section layout in the admin.

= 0.1.8 =
* Added GPT-5.6, Grok, DeepSeek chat and Voyage embeddings. OpenAI is no longer required unless you use it for chat, embeddings, or moderation.
* Added streaming replies, hybrid retrieval, tighter source cards, and a mapped Thinking control (default low).
* Chat endpoints now require the frontend nonce.

= 0.1.7 =
* Added a free lifetime FluentCart license screen.
* Added protected automatic updates through the normal WordPress plugin updater.
* Moved the canonical download to the Alpha Chat FluentCart product and customer account.

= 0.1.6 =
* Removed the "outside knowledge is forbidden" restriction from the system prompt. The assistant now answers freely, falling back to general knowledge when the retrieved site context is thin.
* Every chat request injects the current page (URL, title, and up to 4,000 chars of content) into the prompt. "Explain this", "summarize this page", "what is this article about" now work without needing retrieval to match.

= 0.1.5 =
* Conversations now record the page URL the chat was started from. Visible in the Conversations admin tab and included in CSV exports.
* New `origin_url` column on `wp_alpha_chat_threads` (added automatically on plugin update via `dbDelta`).

= 0.1.4 =
* Conversational messages like "thanks", "hi", or "bye" no longer trigger the "Sorry, I couldn't find an answer" fallback. The LLM now handles small talk naturally and reserves the fallback message for genuine unanswerable factual questions.

= 0.1.3 =
* Frontend widget no longer pulls WordPress core JS scripts (`wp-element`, `wp-i18n`, `wp-hooks`, `wp-dom-ready`, `wp-escape-html`, `react`, `react-dom`). React is bundled directly into the widget.
* One script request on the frontend instead of seven — measurable reduction in render-blocking JS for sites where the widget is enabled site-wide.

= 0.1.2 =
* New **Q&A admin tab** — add curated question/answer pairs that the assistant always knows about (brand identity, pricing, contact info, policies). Backed by a new `wp_alpha_chat_faqs` table.
* Brand identity is now injected into the system prompt automatically. "Who are you?" and similar prompts get a coherent reply without needing retrieval.
* Retrieval gate widened: assistant calls the LLM whenever FAQs exist OR chunks are retrieved, even if only one is available.
* Frontend strips inline citation markers (`[1]`, `[2, 3]`) from replies — the source cards below remain.
* Source card links now carry UTM params (`utm_source=alpha_chat`, `utm_medium=chat_widget`, `utm_campaign=ai_answer`, `utm_referrer={host}`) so chatbot-driven traffic is attributable in analytics.

= 0.1.1 =
* Indexer skips re-embedding unchanged content (SHA-256 hash + model check) — "Reindex all" is now idempotent.
* New Dashboard queue panel with live pending / in-progress / complete / failed counts and a "Process now" button.
* New "Process queue now" button in Knowledge Base toolbar (runs one Action Scheduler batch synchronously).
* Fixed invisible chat panel close button on mobile; repositioned as a circular top-right icon.

= 0.1.0 =
* Initial public release.
* OpenAI (GPT-5.4 / GPT-5.4 mini / GPT-4.1) and Anthropic (Claude Opus 4.7 / Sonnet 4.6 / Haiku 4.5) chat providers.
* MySQL-backed vector store with cosine similarity.
* Shadow-DOM-isolated React widget.
* Gutenberg block and `[alpha_chat]` shortcode; floating launcher is opt-in.
* Customizable launcher position, nudge text, brand name, and color scheme.
* Source cards (title + thumbnail) attached to each reply.
* In-chat contact form (Name + Email + Message) with admin email notifications and Contacts admin tab.
* Knowledge Base batch actions: select rows, bulk index / remove, "Index remaining", filter by indexed state.
* All public post types indexable (filter via `alpha_chat_indexable_post_types`).
* Token-frugal: empty retrievals skip the LLM entirely.
* Rate limiting on chat (30/min per session) and contact (5/hour per IP).

== Upgrade Notice ==

= 0.1.0 =
First release.
