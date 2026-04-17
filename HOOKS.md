# Alpha Chat — Hooks reference

Public hook surface for extensions. All hook names are prefixed with
`alpha_chat_`. Signatures are listed with their PHP types.

## Actions

### Lifecycle

| Hook | Fired when | Args |
| --- | --- | --- |
| `alpha_chat_activated` | Plugin activation finishes. | — |
| `alpha_chat_deactivated` | Plugin deactivation runs. | — |
| `alpha_chat_booted` | Plugin finishes wiring services. | `AlphaChat\Support\Container $container` |
| `alpha_chat_register_rest_routes` | REST namespace is about to register routes. | `string $namespace`, `AlphaChat\Support\Container $container` |

### Indexing

| Hook | Fired when | Args |
| --- | --- | --- |
| `alpha_chat_post_indexed` | A post is embedded and stored. | `int $post_id`, `int $chunk_count` |
| `alpha_chat_post_forgotten` | A post is removed from the knowledge base. | `int $post_id` |

### Chat

| Hook | Fired when | Args |
| --- | --- | --- |
| `alpha_chat_message_flagged` | A user message is flagged by moderation. | `string $message`, `array $moderation` |
| `alpha_chat_message_answered` | An assistant reply is persisted. | `int $thread_id`, `string $message`, `string $reply`, `array $sources` |
| `alpha_chat_unanswered_question` | A query produced no retrieved context. | `int $thread_id`, `string $message` |

## Filters

### Providers

| Hook | Purpose | Returns |
| --- | --- | --- |
| `alpha_chat_llm_provider` | Swap in a custom chat provider. | `AlphaChat\Providers\Contracts\LLMProvider` |
| `alpha_chat_embedding_provider` | Swap in a custom embedding provider. | `AlphaChat\Providers\Contracts\EmbeddingProvider` |
| `alpha_chat_vector_store` | Swap in a custom vector store. | `AlphaChat\Providers\Contracts\VectorStore` |

### Retrieval and prompting

| Hook | Purpose | Args |
| --- | --- | --- |
| `alpha_chat_retrieved_chunks` | Modify the retrieved chunks before they are sent to the LLM. | `list<array> $chunks`, `string $message` |

### Settings

| Hook | Purpose | Args |
| --- | --- | --- |
| `alpha_chat_default_settings` | Modify the default settings array. | `array $defaults` |
| `alpha_chat_settings_sanitize` | Modify sanitized settings before save. | `array $output`, `array $input` |

### UI

| Hook | Purpose | Args |
| --- | --- | --- |
| `alpha_chat_display_widget` | Decide whether to render the frontend widget on the current request. Default: knowledge base has at least one chunk. | `bool $default` |

## Example

```php
// Use a custom LLM for the chat pipeline.
add_filter(
    'alpha_chat_llm_provider',
    static function ( $default ) {
        return new \MyPlugin\Providers\LocalLLM();
    }
);

// Only show the widget on singular posts.
add_filter(
    'alpha_chat_display_widget',
    static fn ( bool $default ): bool => $default && is_singular( 'post' )
);
```
