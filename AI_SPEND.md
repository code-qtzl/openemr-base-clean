# AI_SPEND.md — Clinical Co-Pilot Actual Cost & Scaling Projections

Closes `PUNCH_LIST_2.md` Item 1: a real, observed dollar cost, tied to a
specific run and git SHA, plus a scaling projection a hospital CTO could
sanity-check and adjust.

**Status: real measured cost, published-rate estimate. Not a confirmed
billed dollar amount** — see "What this is not," below.

---

## Methodology

3 sequential real questions, asked directly against `CopilotService::ask()`
(bypassing HTTP so token counts could be captured in-process rather than
inferred), against a real seeded demo patient (`pid=1`), real chart data,
`claude-opus-5`, `thinking: adaptive` — the exact production configuration
`CopilotChatController` uses. Token counts are `AskResult::inputTokens`/
`outputTokens`, the same numbers the Anthropic SDK itself reports
(`$message->usage->inputTokens`/`outputTokens`, summed across every turn of
the tool-calling loop) — not estimated, not sampled.

- **Date:** 2026-09-19
- **Git SHA:** `3f82c71789` (measured against this state; `CopilotService`/
  `ChartContextTools` are unaffected by commits after it)
- **Script:** `tests/loadtest/real-api-cost-smoke.php`
- **Model:** `claude-opus-5`
- **Pricing source:** Anthropic's own published rate as of this date
  (`https://claude.com/pricing`, redirected from `anthropic.com/pricing`):
  **$5.00 / MTok input, $25.00 / MTok output.**

This is a separate, smaller batch from `PERFORMANCE_BASELINE.md`'s 6-request
real-API smoke pass (that one measured latency via k6 hitting the live HTTP
endpoint, but k6 has no visibility into token counts). Both batches hit the
real Anthropic API against the same patient; between them, 9 real requests
back this document and `PERFORMANCE_BASELINE.md`'s real-API latency row.

## Actual observed cost, per question

| Question | Tool called | Input tokens | Output tokens | Cost | Latency |
|---|---|---:|---:|---:|---:|
| "What conditions does this patient have on file?" | `get_active_problems` | 15,526 | 1,435 | $0.1135 | 17.4s |
| "What medications is this patient currently taking?" | `get_medications` | 4,829 | 442 | $0.0352 | 7.8s |
| "What was the result of their most recent A1c, and is it trending up or down?" | `get_a1c_series` | 11,348 | 885 | $0.0789 | 13.4s |
| **Average** | | **10,568** | **921** | **$0.0759** | **12.9s** |

Cost = `(input_tokens / 1,000,000) × $5 + (output_tokens / 1,000,000) × $25`.

**Why the range is wide (2.2–3.2x between cheapest and most expensive):**
input tokens scale with how much chart data the called tool returns — a
patient's full active-problems list is bigger than their most recent A1c
value alone — and output tokens scale with how much the model has to
synthesize. A real patient with a longer problem list or medication history
than this demo patient will cost more per question than this average, not
less.

## Per-conversation cost (multi-turn, Tier 4.1)

The table above is single-question cost. `PUNCH_LIST.md` Tier 4.1 (multi-turn
conversation state) means a follow-up question's request re-sends the entire
prior conversation history as additional input tokens (`Conversation::
toMessages()` prepended to the new question in `CopilotService::ask()`) — so
a 2-question conversation costs **more than 2x** a single question, not
exactly 2x.

**This session did not directly measure a real 2-turn conversation's token
count** — doing so precisely is a cheap, obvious follow-up (repeat this
methodology with a second, history-carrying question). Until then, treat a
typical USERS.md UC2 conversation (initial synthesis + one follow-up) as
**roughly $0.16–$0.19** (average single-question cost, plus a second
question whose input tokens include the first exchange) — an estimate, not
a measured figure, and flagged as such.

## Scaling projections

Base rate: **$0.0759/question** (this document's measured average).
`USERS.md`'s Dr. Elena Ruiz sees **20–24 patients/day** (midpoint: 22).
Question volume per patient is an assumption, stated explicitly so it's
adjustable — not measured, since Metric 5 (Adoption Rate, `KEY_METRICS.md`)
has no real usage data yet:

- **Low (1.0 q/patient):** every visit gets one UC1 synthesis question, no
  UC2 follow-ups.
- **Mid (1.4 q/patient):** ~40% of visits also get a UC2 follow-up.
- **High (2.0 q/patient):** every visit gets both.

21 clinic days/month.

| Scale | Physicians | Low (1.0 q/pt) | Mid (1.4 q/pt) | High (2.0 q/pt) |
|---|---:|---:|---:|---:|
| One physician (Dr. Ruiz) | 1 | $35/mo | $49/mo | $70/mo |
| Small practice | 5 | $175/mo | $245/mo | $350/mo |
| Clinic / small hospital | 50 | $1,752/mo | $2,453/mo | $3,505/mo |
| Hospital system | 500 | $17,523/mo | $24,532/mo | $35,045/mo |

(22 patients/day × physicians × q/patient × 21 days/month × $0.0759/question.)

**These are not small numbers at hospital-system scale**, and that's the
point of measuring instead of assuming — a genuinely useful answer to "can
we afford this" has to be able to say so. Two concrete, unused-so-far levers
that would materially cut this, per Anthropic's own published pricing:

- **Prompt caching** — up to 90% off cached input tokens. `ChartContextTools`'
  four tool results and the system prompt are the same shape turn-over-turn
  within one conversation; caching them is a real, scoped optimization this
  integration doesn't do yet.
- **Batch processing** — 50% off, but asynchronous; not applicable to this
  agent's "physician waiting in the room" latency requirement (`AgentForge.md`
  Hard Problems: Speed vs. Completeness), so not recommended here.

## Architectural changes needed at each scale

The dollar figures above assume today's architecture keeps working
unchanged as physician count grows. It doesn't. `railway.json` sets
`"numReplicas": 1` with no autoscaling — a single container serves every
concurrent chat request, and `AUDIT_Extra.md`'s Finding P5 names the
consequence: each co-pilot request pins an Apache/PHP-FPM worker for the
full duration of the blocking LLM call chain, not milliseconds.
`PERFORMANCE_BASELINE.md` measured this directly, not theoretically — k6
against this exact single-instance architecture, **mocked (instant) LLM
calls**: 10 VU is clean (0.0% error rate), 50 VU degrades sharply (**28.0%
error rate**, almost all `session_setup_errors` from Apache-worker/MySQL
connection-pool contention, p95 climbing from 304ms to 1.04s on the chat
call alone, 1.70s to 8.24s full-HTTP-flow). That 28% is a **floor**, not a
ceiling: the same report's real-API row shows real `claude-opus-5` calls
take 13.2–18.9s (p50–p99), roughly 40–60x the mocked stub — so a real
50-concurrent-user burst holds each worker far longer than the mocked test
did, and would degrade worse, not better.

Mapping that evidence onto this document's four scale points:

- **1 physician (Dr. Ruiz).** Worst-case concurrency is ~1 request at a
  time — nowhere near the 10-VU mark this architecture handles cleanly. No
  architectural change needed; today's single Railway instance is
  correctly sized for this scale.
- **5 physicians (small practice).** Peak concurrency (all 5 asking a
  question in the same few-minute window) is still comfortably under the
  measured-clean 10-VU threshold. No change required yet, but this is the
  first scale where the risk becomes plausible enough to watch rather than
  ignore: each real chat request now holds a worker for 13-19s, and it's
  the same worker pool ordinary EHR page loads use. Recommended action is
  monitoring (`ALERTS.md`'s latency/error thresholds), not a code change.
- **50 physicians (clinic/small hospital).** This is the exact regime
  `PERFORMANCE_BASELINE.md` measured — 50 concurrent users against this
  same no-autoscaling architecture produced a 28% error rate even with an
  *instant* mocked LLM call. Real chat calls holding workers for 13-19s
  each would saturate the pool faster and worse than that 28% figure.
  **Architectural change required at this scale, not optional**: implement
  Finding P5's remediation — move the co-pilot's blocking LLM call chain
  off the main request-handling Apache/PHP-FPM workers (an async
  queue/worker plus the SSE/streaming response this repo doesn't have yet),
  and/or add horizontal autoscaling (`railway.json`'s `numReplicas` beyond
  1, load-balanced). Without this, 50 physicians degrade both their own
  co-pilot latency and everyone's ordinary EHR page loads on the same
  starved worker pool.
- **500 physicians (hospital system).** Same P5 bottleneck, an order of
  magnitude worse — no amount of worker-pool tuning on a single container
  survives this volume. Requires the full remediation to already be in
  place: horizontal autoscaling as a standing requirement (not a burst
  response), the async-queue/SSE architecture from the 50-physician tier
  now mandatory, and MySQL connection-pool sizing scaled alongside the app
  tier (P5's error signature was mostly connection/session-setup
  contention, not chat-endpoint failures once a session was established).
  At this volume the cost side matters too: **prompt caching** (90% off
  cached input tokens — see "Scaling projections," above) stops being a
  nice-to-have and becomes the difference between ~$17.5k/mo and a
  meaningfully lower bill, since `ChartContextTools`' tool results and
  system prompt repeat turn-over-turn at real scale. Batch processing
  remains inapplicable at every scale — this agent is still answering a
  physician waiting in the room, not a queued job.

None of this is new work invented for this document — it's `AUDIT_Extra.md`
Finding P5 and `PERFORMANCE_BASELINE.md`'s own load-test numbers, connected
to the dollar figures above so a reader doesn't have to cross-reference
three documents to see where the architecture, not just the bill, has to
change.

## What this is not

- **Not a confirmed billed dollar amount.** This is measured token counts ×
  Anthropic's published per-token rate, not a screenshot of the Anthropic
  Console's actual invoice for this API key. If an exact billed figure is
  required (vs. this estimate), that needs the Anthropic Console's billing
  view for this project's key — get that from whoever holds that login
  directly, the same reasoning as the Railway credential note elsewhere in
  this project's history, rather than the agent fetching billing credentials
  itself.
- **Not inclusive of prompt caching or batch discounts** — see above; this is
  the un-optimized baseline cost, which is the right number to defend first.
- **Not a multi-turn-measured figure** — see "Per-conversation cost" above.
