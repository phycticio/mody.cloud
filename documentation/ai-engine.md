# AI engine

## Decision (from the briefing)

| Item | Value |
| --- | --- |
| Model | `mistralai/Ministral-3-14B-Instruct-2512` |
| Provider | Infomaniak AI Services. Execution in Switzerland, sovereign data. |
| API | OpenAI-compatible standard |
| Base URL (briefing) | `https://api.infomaniak.com/ai-services/v1` |
| Authentication | Header `Authorization: Bearer TOKEN_API` |
| Price | Input 0.003 EUR per 10K tokens, output 0.004 EUR per 10K tokens |
| Cost per generated site | About 0.002 EUR (negligible) |
| Escalation | If the 14B model breaks syntax, retry with `google/gemma-4-31B-it` |

> **Open question: verify the base URL.** Public integrations of Infomaniak's AI API document a base URL that includes a product ID, for example `https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1`, with an older `/1/` variant in circulation. That differs from the briefing. Confirm against [Infomaniak's API reference](https://developer.infomaniak.com/docs/api) before Milestone 1.1, and read the base URL, model and token from environment variables instead of hardcoding them.

## What the agent does

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

> **Open question: Spectra block names.** Confirm the exact registered names against the installed Spectra version before building the validator (older Spectra versions register blocks under the `uagb/` namespace). The validator should check against the real block registry, not a copied list.

## Master prompt rules

- Use ONLY blocks from the whitelist (`core/*` and `spectra/*`). Never invent block names.
- Return EXCLUSIVELY the serialized code. No explanations, no Markdown, no backticks.
- Include few-shot examples of correct blocks in the system prompt.
- Low temperature (0.1) to maximize syntax validity.
- Validate the output with a parser before inserting it. Retry if the JSON is broken.
- Write the system prompt and every instruction to the model in English (project language rule, see [constraints.md](constraints.md)). The language of the generated page content is an open question, see [brand-profile.md](brand-profile.md#open-questions).

The system prompt is assembled from the brand profile on every call. Use the theme's named colors, never loose hex values. See [brand-profile.md](brand-profile.md#message-to-the-model).

## Validation pipeline

1. Parse every `<!-- wp:name {json} -->` and closing comment. Fail on unbalanced blocks or unparseable JSON.
2. Reject any block name outside the whitelist.
3. Reject loose hex colors. Colors must be palette slugs (`primary`, `secondary`, `accent`, `base`, `contrast`).
4. On failure, retry. After the configured attempts, escalate to the larger model. After that, report the failure; never insert unvalidated output.

Steps 3 and 4 are an interpretation of the briefing's "use the theme's named colors" and "retry if broken" rules.

## First concrete step: Milestone 1.1

Implement `hello_ai.py`:

- Connects to `mistralai/Ministral-3-14B-Instruct-2512` through the Infomaniak API.
- Reads the token from an environment variable (never committed).
- Returns one valid Gutenberg paragraph block.
- Validates the serialized syntax before moving on to Milestone 1.2.

Python 3.9+. Where the agent code lives in the repository is not decided. Proposal: an `agent/` directory at the repository root.
