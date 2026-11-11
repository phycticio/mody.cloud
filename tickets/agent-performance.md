# The agent: faster, measured, and cache-friendly

**Builds on:** [agent-whole-page-sections.md](agent-whole-page-sections.md) · **Status:** built locally on 2026-11-11 and reviewed (13 findings fixed: caps on sections per message and section size, the one-job lock renewed with progress, classic HTML kept, retries told why, owner text kept out of prompt tags and metrics, tests and docs), deployed the same day.

## Asked for
The maintainer asked "What is your recommendation for better responses and performance of the agent?", then "Go ahead with all recommendations" (2026-11-11). There were six recommendations:
1. long pages changed in parallel;
2. only the context a message needs;
3. the fixed part of each prompt first;
4. measuring every turn and a fixed evaluation set;
5. repairs instead of retries;
6. a stronger model for writing.

## Outcome
Details and numbers: [ai-engine.md, Performance and measurement](../documentation/ai-engine.md#performance-and-measurement-2026-11-11).
1. **Built.** Three sections at a time, the largest first, and a full retry loop for any section that fails. The 56 KB landing page went from 50 s to about 30 s; its one 14 KB section bounds the time.
2. **Not built, on evidence.** Infomaniak caches repeated prompt beginnings: 98% of the agent's prompt is cached from the second message, and an answer takes about 1 s instead of 4. Choosing context per message would lose that.
3. **Built.** The writers' prompts put what changes per request (block cards, media) last. The agent's prompt was already ordered that way.
4. **Built.**
   - `Agent\TurnMetrics`: one `turn` row per message, with time, calls, tokens, the cache and the writer.
   - `scripts/agent-metrics.php`: the summary, which also runs in production.
   - `scripts/agent-eval.php`: 31 real requests on a throwaway site. Baseline after the review: 31 of 31, median 1.9 s, 82% of the prompt cached, 14 writer answers and 0 rejected.
5. **Partly built.** Markdown code fences are now repaired. The local log could not show which real mistakes are common, and reading the production log was not allowed in this session. The turn rows will collect them; review them after a few weeks.
6. **Built as a setting; no change of model.** `INFOMANIAK_AI_WRITER_MODEL`. Seven models compared: the current Qwen 27B was the fastest and among the most reliable; Qwen3.5-397B was valid but three times slower; the others failed some or all pages.

## Open questions
- **Production log:** reading it would show which writer mistakes are worth repairing in code. It needs the maintainer's permission, or the maintainer can run `scripts/agent-metrics.php` there.
- **Very large sections:** a section of more than about 10 KB, such as a long FAQ, could be split into its blocks for parallel edits. Worth it only if long pages are common.
