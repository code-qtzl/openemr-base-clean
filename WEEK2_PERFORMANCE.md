# Week 2 latency & cost report

Source: Langfuse Cloud (US region) observations for this project, pulled via
the v2 observations API on 2026-10-05. Window: 2026-09-17 → 2026-09-28
(530 traces, 1,762 observations). Only latency, token counts and Langfuse's
computed cost were read; no prompt or response content was exported, and the
only data is synthetic Synthea records. Model on every generation:
`claude-opus-5` (Langfuse price table: $5 / MTok input, $25 / MTok output).

Week 1 baselines (mocked-LLM capacity, per-question spend, scaling) stay in
[PERFORMANCE_BASELINE.md](PERFORMANCE_BASELINE.md) and
[AI_SPEND.md](AI_SPEND.md). This file covers the Week 2 flow only.

## How traces were classified

- **Real-API trace**: more than 1,000 input tokens. This drops 4 stub traces
  (30 input / 15 output tokens, 0 s) and mocked load-test traffic, leaving
  53 of 530 traces.
- **Week 2 trace**: contains a `handoff:consult_*_worker` span, a
  `search_guideline_evidence` call or a `get_extracted_documents` call
  (11 traces, 2026-09-23 → 09-28). Everything else real is the Week 1 style
  chart-only path (42 traces, 2026-09-17 → 09-23).

## End-to-end, real API

| Path | n | Latency p50 | Latency p95 | Cost / question (mean) | Cost max | Avg tokens in / out |
|---|---|---|---|---|---|---|
| Week 1 style (chart tools only) | 42 | 11.3 s | 22.1 s | $0.086 | $0.189 | 12,561 / 910 |
| **Week 2, all routes** | 11 | **27.9 s** | 45.4 s | **$0.202** | $0.306 | 28,390 / 2,397 |
| Week 2, chart worker only | 7 | 27.9 s | 31.4 s | $0.182 | $0.202 | 24,993 / 2,299 |
| Week 2, document worker | 1 | 19.3 s | n/a | $0.127 | n/a | 15,804 / 1,900 |
| Week 2, evidence worker | 1 | 29.7 s | n/a | $0.273 | n/a | 44,053 / 2,111 |
| Week 2, all three workers | 2 | 42.6 s | 45.4 s | $0.272 | $0.306 | 38,742 / 3,135 |

Total real-API spend recorded in Langfuse across the window: **$5.81**
($3.59 Week 1 style, $2.22 Week 2).

## Per-step latency

**Per-step timing in these traces is not reliable, so no step breakdown is
claimed.** Two measurement issues in the instrumentation that produced them:

- The single `anthropic.messages` generation span is synthesized after the
  fact and spans the **entire request** (`LangfuseTracer::traceAsk` sets its
  start/end to the request start/end). It includes tool time, retrieval and
  any Voyage retry sleeps, so it cannot be read as model-only latency.
- On the Supervisor path, `Supervisor::consultWorker` starts its timer
  *after* the worker's `consult()` has already run (the call is evaluated as
  an argument), so `handoff:*` spans and the `get_*` / `search_guideline_evidence`
  / `get_extracted_documents` spans bracket nothing and read about 0-1 ms.
  The Week 1 path (`CopilotService`) times its tool calls correctly; the
  Supervisor path, the live Week 2 entry point, does not.

What can be said: end-to-end latency and per-trace token usage and cost
(table above) are accurate, because they come from the request wall clock and
the Anthropic usage totals. How that time splits between model calls,
retrieval and Voyage is unknown until the spans below are fixed.

## What this says

1. **Week 2 roughly doubles latency and cost versus Week 1**: p50 27.9 s vs
   11.3 s and $0.20 vs $0.09 per question. The driver is context size, not
   routing: Week 2 turns send ~28k input tokens versus ~12.5k. Even
   chart-worker-only turns average ~25k, so the Supervisor path itself
   roughly doubles input before documents or guideline chunks are added; the
   exact cause was not isolated from these traces.
2. **Routing overhead is not measured**: handoff spans currently time
   nothing (see above), so no claim is made about it either way.
3. **Mixed questions are the worst case**: 42.6 s p50 and $0.27 per question
   when all three workers run. That is above a comfortable interactive
   threshold for a clinician mid-visit.
4. **Cost is small in absolute terms**: even the worst observed question was
   $0.31. At the Week 2 mean, 1,000 questions ≈ $202.

## Gaps and caveats (read before citing)

- **Small sample**: 11 real Week 2 traces. p95 over n = 11 is effectively the
  max, and the document-worker and evidence-worker rows are n = 1.
- **No VLM extraction telemetry**: intake/lab extraction runs in the upload
  path and produces no Langfuse generation. There is one `anthropic.messages`
  generation per trace, so extraction latency, tokens and cost are not
  captured here. 14 intake-form extractions exist in the dev DB, none from a
  lab PDF, and none have timing recorded.
- **No Voyage telemetry**: embedding and rerank calls are not separate spans,
  and `search_guideline_evidence`'s ~0 ms is an artifact of the timer bug
  above. The Voyage client also sleeps 21 s on a 429 (free-tier rate limit),
  which would sit invisibly inside the request total. Voyage cost is absent
  from the totals above.
- **Not in traces**: extraction confidence, retrieval-hit counts and eval
  outcome per encounter (Core Requirement #7) are not visible in the
  observation metadata that was exported.
- **Dev-stack and demo traffic**, not a production load profile; concurrent
  behaviour is covered separately in PERFORMANCE_BASELINE.md.
- Cost comes from Langfuse's price table, not Anthropic's invoice.

## Instrumentation added after this report

Commits `d907dd1be`, `6b021ac96` and the extraction-trace commit that follows
them fix the gaps above for **new** traffic. The numbers in this report were
captured before them and are not retroactively improved.

- Handoff and tool spans now time the worker's real work.
- `voyage.embed`, `retrieval.dense_rank`, `retrieval.fulltext` and
  `voyage.rerank` appear as child spans with counts and scores only. A Voyage
  429 retry sleep (21 s) shows up as a long `voyage.embed` or `voyage.rerank`.
- Document extraction now has its own trace (`clinical-copilot.extract`):
  model, tokens, latency, `schema_valid`, and **extraction completeness**
  (required schema fields present and well-formed, out of the doc type's
  required fields, plus the missing field names). This is a deterministic proxy
  for "extraction confidence", not a model-reported score.
- Still not captured: Voyage token/cost, per-call Anthropic HTTP timings
  (the `anthropic.messages` generation on chat traces still spans the whole
  request), and retrieval-hit counts on the trace root.

## Suggested follow-ups

- Re-run a larger real-API sample (about 20-30 mixed questions plus a few
  lab-PDF and intake uploads) now that the spans exist, and replace this
  report's per-step section with measured values. A few dollars at the Week 2
  mean of about $0.20 per question.
- Record Voyage token usage and per-call Anthropic request timings so the
  generation span can be split from tool and retrieval time.
- Look at trimming Week 2 context (fewer or shorter guideline chunks, a summary
  of extracted fields) to pull the 28k-token average down.
