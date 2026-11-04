# AI engine

## Decision

Decided 2026-11-04 by the maintainer, and verified the same day against Infomaniak with the project's token.

| Item | Value |
| --- | --- |
| Integration | The **WordPress AI Client** that ships with core (WordPress 7.0+, `wp_ai_client_prompt()`), with an Infomaniak provider in the `mody-platform` MU plugin. This replaces the Python agent service the MVP spec first planned. No separate container, no internal secret, no relay. |
| Provider | Infomaniak AI Services, OpenAI-compatible API. Execution in Switzerland. |
| Model | `Qwen/Qwen3.8-27B-FP8` (default). Override with `INFOMANIAK_AI_MODEL`. |
| Base URL | `https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1`, from `INFOMANIAK_AI_BASE_URL`. |
| Authentication | `Authorization: Bearer <token>`. The token is `INFOMANIAK_API_KEY`, which the AI Client reads from the environment by itself. |
| Thinking mode | Off for generation: pass `chat_template_kwargs.enable_thinking = false` as a custom option. Same output, about a fifth of the tokens. |
| Price | Not verified for Qwen. The briefing's figures (0.003 / 0.004 EUR per 10K input / output tokens) were for Ministral. Check Infomaniak's pricing before launch. |

## How it is wired

```text
WordPress (any site in the network)
  └─ wp_ai_client_prompt( … )                         core, wp-includes/ai-client.php
       └─ AiClient default registry
            └─ Mody\Platform\AI\InfomaniakProvider     app/web/mu-plugins/mody-platform/src/AI/
                 ├─ InfomaniakModelMetadataDirectory   GET https://api.infomaniak.com/1/ai/models (type = llm)
                 └─ InfomaniakTextGenerationModel      POST {INFOMANIAK_AI_BASE_URL}/chat/completions
```

- **Configuration is environment-only.** The MU plugin registers the provider on `init` (priority 5, before core reads the registry at 15) only when `INFOMANIAK_AI_BASE_URL` is set. Without it, nothing is registered and no connector card appears, so a server without the variables is unaffected.
- **Network-wide.** Every site in the Multisite network uses the same provider and token, with no per-site setup (checked on the `alpha` subsite).
- **Settings → Connectors** shows "Infomaniak AI" with the key source `env`. The token is never stored in the database (rule 6).
- **Model list.** The OpenAI-compatible `/models` endpoint works but lists every model without a type, embeddings included. The directory uses Infomaniak's native listing instead and keeps `type = llm`. The default model is listed first, because the AI Client uses the first suitable model when a prompt names none. The list is cached for a day.
- **JSON output.** Core sends the schema itself as `response_format.json_schema`. Infomaniak validates the OpenAI shape and rejects a request without `json_schema.name` (HTTP 400), so the model class wraps the schema as `{ "name": "response", "schema": … }`.
- Everything else (messages, tools, parsing) is core's OpenAI-compatible implementation.

Local setup: put both variables in `.env`, then `docker compose up -d wordpress`. The container reads `env_file` only when it is created, and PHP-FPM passes the environment through (`clear_env = no`).

## Calling the model

```php
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

$blocks = wp_ai_client_prompt( $user_prompt )
	->using_model_config( ModelConfig::fromArray( array(
		'customOptions' => array( 'chat_template_kwargs' => array( 'enable_thinking' => false ) ),
	) ) )
	->using_system_instruction( $system_prompt ) // Always built from the brand profile (rule 4).
	->using_temperature( 0.1 )
	->using_max_tokens( 800 )
	->generate_text(); // string, or WP_Error on failure.
```

- **Tools:** `using_function_declarations( new FunctionDeclaration( $name, $description, $json_schema ) )`, or `using_abilities( … )` to offer registered WordPress abilities (Abilities API) as tools. Read the calls from `generate_text_result()->toMessage()->getParts()`.
- **JSON:** `as_json_response( $json_schema )`.
- Call `using_model_config()` first: the other `using_*` calls add to the configuration it sets.
- The builder returns `WP_Error` instead of throwing.

## Verification (2026-11-04)

Run through `wp_ai_client_prompt()` on the local stack, and directly against the API.

| Check | Qwen/Qwen3.8-27B-FP8 | mistralai/Ministral-3-14B-Instruct-2512 |
| --- | --- | --- |
| Plain completion | Pass, about 0.5 s | Pass |
| Function calling (`tools`) | Pass: `update_design {"slug":"primary","color":"#FF7F50"}` | Pass |
| JSON-schema output | Pass | Pass |
| Gutenberg blocks | Pass: valid `core/heading` and `core/paragraph`, palette slugs, no hex, identical after `parse_blocks()` and `serialize_blocks()`. 0.7 s and 95 tokens with thinking off. 1.6 s and 461 tokens (374 of them reasoning) with it on. | **Fail:** Markdown fences, invented syntax, Spanish text |

Infomaniak's native listing marks Qwen 3.8 and most other LLMs as `coming_soon`, and only Ministral as `ready`. Qwen answered every test anyway. The MVP spec calls it a project-scoped build, which may explain the label. **Open question:** confirm with Infomaniak that this model is supported for production use.

## What the agent produces

The agent receives a natural-language description and must return the **serialized Gutenberg block format** (HTML comments carrying JSON). It must not return loose HTML or Markdown. In a block theme the same format generates page content and complete templates, because it is exactly what the theme's `templates/*.html` and `parts/*.html` files contain.

Example of valid output:

```html
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Welcome</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>A first paragraph.</p>
<!-- /wp:paragraph -->
```

## Initial block whitelist

```text
core:    paragraph, heading, buttons, column, columns, group, cover,
         image, list, quote, spacer, separator, social-links,
         navigation, site-logo, site-title, site-tagline, query, post-template
spectra: header, testimonial, counter, team-member, pricing-table,
         info-box, modal, google-maps, container
```

> **Open question: Spectra block names.** Confirm the exact registered names against the installed Spectra version before building the validator (older Spectra versions register blocks under the `uagb/` namespace). The validator checks against the live block registry (`WP_Block_Type_Registry`), not a copied list.

## Master prompt rules

- Use ONLY blocks from the whitelist (`core/*` and the Spectra blocks). Never invent block names.
- Return EXCLUSIVELY the serialized code. No explanations, no Markdown, no backticks.
- Include few-shot examples of correct blocks in the system prompt.
- Low temperature (0.1) to maximize syntax validity.
- Validate the output with a parser before inserting it. Retry if it is broken.
- Write the system prompt and every instruction to the model in English (project language rule, see [constraints.md](constraints.md)). The language of the generated page content is an open question, see [brand-profile.md](brand-profile.md#open-questions).

The system prompt is assembled from the brand profile on every call. Use the theme's named colors, never loose hex values. See [brand-profile.md](brand-profile.md#message-to-the-model).

## Validation pipeline

Runs in PHP, in the MU plugin, before anything is saved:

1. Parse with WordPress's own `parse_blocks()`. Fail on unbalanced blocks, unparseable attribute JSON, or output that does not survive `serialize_blocks()` unchanged.
2. Reject any block name that is not in the whitelist and registered in `WP_Block_Type_Registry`.
3. Reject loose hex colors. Colors must be palette slugs (`primary`, `secondary`, `accent`, `base`, `contrast`).
4. On failure, retry (2 attempts, per the MVP spec). After that, report the failure. Never insert unvalidated output.

Steps 3 and 4 are an interpretation of the briefing's "use the theme's named colors" and "retry if broken" rules. **Open question:** whether to escalate to a larger model before giving up. `Qwen/Qwen3.5-397B-A17B-FP8` is listed but untested. The briefing's `google/gemma-4-31B-it` is `coming_soon`.

## History

The briefing chose `mistralai/Ministral-3-14B-Instruct-2512`, a Python `hello_ai.py` first step, and the base URL `https://api.infomaniak.com/ai-services/v1`. The MVP spec (2026-11-01) moved to Qwen and a Python agent service. On 2026-11-04 the agent moved into WordPress, using the core AI Client, and the base URL above was confirmed. Ministral fails the block test.
