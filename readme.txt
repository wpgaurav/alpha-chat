=== Alpha Chat ===
Contributors: alphachat
Tags: ai, chatbot, openai, anthropic, rag, gpt, claude
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.1.2
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
* **Privacy-respecting.** No telemetry. No phone-home. No license key. Uninstalling cleans up every table, option, and scheduled job.

= Features =

* OpenAI (GPT-5.4, GPT-5.4 mini, GPT-4.1) and Anthropic (Claude Opus 4.7, Sonnet 4.6, Haiku 4.5) chat providers.
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
3. Go to **Alpha Chat → Settings** and paste your OpenAI API key (required for embeddings + moderation). Add an Anthropic key only if you pick Claude.
4. Open the **Knowledge Base** tab, filter *Not indexed*, and click **Index remaining** to ingest your site.
5. Drop the **Alpha Chat** block or `[alpha_chat]` on any page, or enable **Behavior → Show floating launcher site-wide**.

== Frequently Asked Questions ==

= Does it send my content to external services? =

Only to the provider you configure. When you add a post to the knowledge base, its text is embedded via your chosen provider. When a visitor chats, their message plus the matching context chunks are sent to the chat provider. Nothing is sent anywhere else.

= Does it work without an external vector database? =

Yes. The default store is a MySQL table with cosine similarity computed in PHP. Comfortable up to tens of thousands of chunks on typical hosts. If you outgrow it, implement the `VectorStore` interface and swap via the `alpha_chat_vector_store` filter.

= Can I use Claude instead of OpenAI? =

Yes. Settings → Provider → Anthropic, pick a model. You still need an OpenAI key because embeddings and moderation run on OpenAI.

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
