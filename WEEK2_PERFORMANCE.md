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

## Live sample run (2026-10-05, after the span fixes)

A controlled run through the same code path as the controllers (Supervisor,
`StepRecorder`, `LangfuseTracer`), so step timings come from the fixed spans.
Traces are in Langfuse under environment `week2-sample`. Patient: the dev
stack's chart-richest synthetic patient (pid 39, 4 synthetic documents
extracted: 2 intake forms from `intake-forms/`, 2 generated lab PDFs from
2026-03 and 2026-09 marked "SYNTHETIC TEST DATA"). 22 questions: 6 chart-only,
6 document, 5 evidence, 5 mixed. Spend: about $7.4 including the 4 extractions
and a 2-question diagnostic.

### End-to-end, per question

| Category | n | Latency p50 | p95 | Cost mean | Avg tokens in / out |
|---|---|---|---|---|---|
| Chart-only | 6 | 31.2 s | 45.7 s | $0.286 | 42,833 / 2,870 |
| Document | 6 | 27.2 s | 30.1 s | $0.235 | 36,479 / 2,092 |
| Evidence | 5 | 30.9 s | 80.1 s | $0.309 | 49,531 / 2,447 |
| Mixed | 5 | 63.2 s | 107.7 s | $0.412 | 59,502 / 4,578 |
| **All** | 22 | **30.9 s** | 80.1 s | **$0.306** | 46,411 / 2,950 |

These are higher than the historical Week 2 table above ($0.20, 27.9 s p50):
this patient has a large chart (every chart tool returns up to its 60-row cap),
and the questions are broader. Do not compare the two tables as a trend; they
are different patients and question sets.

### Measured per-step latency (Langfuse, 17 retrieval calls)

| Step | n | p50 | p95 | Max |
|---|---|---|---|---|
| `voyage.embed` | 17 | 0.20 s | 42.5 s | 42.5 s |
| `voyage.rerank` | 17 | 0.14 s | 0.22 s | 0.22 s |
| `retrieval.dense_rank` | 17 | 9 ms | 11 ms | 11 ms |
| `retrieval.fulltext` | 17 | 1 ms | 3 ms | 3 ms |
| `handoff:consult_evidence_worker` | 17 | 0.36 s | 42.7 s | 42.7 s |
| `handoff:consult_chart_worker` | 21 | 13 ms | 16 ms | 44 ms |
| `handoff:consult_document_worker` | 17 | 2 ms | 5 ms | 5 ms |
| Extraction (VLM call) | 4 | 9.2 s | 12.0 s | 12.0 s |

- **Retrieval and routing are cheap when Voyage answers**: median 2.2% of a
  retrieval turn (1.7% excluding rate-limit stalls). Handoffs themselves cost
  milliseconds. Roughly 97%+ of a typical turn is therefore the Anthropic
  calls (inferred as the remainder; the chat `anthropic.messages` generation
  span still covers the whole request, so model time is not measured directly).
- **Voyage rate limiting is the one real retrieval risk**: 3 of 17 embed calls
  stalled at 21.3 s, 42.4 s and 42.5 s, i.e. one or two of `VoyageClient`'s
  21 s sleeps on HTTP 429 (free-tier 3 requests/minute). They hit 3 of 10
  retrieval turns and account for the 80.1 s and 107.7 s outliers. Adding a
  payment method to the Voyage account lifts this limit.
- **Extraction**: intake forms 6.6 to 7.7 s ($0.026-$0.031), lab PDFs 10.6 to
  12.0 s ($0.034-$0.037); about 2.4-2.5k input and 0.56-0.98k output tokens
  each. Required-field completeness was 1.0 on all four. These are clean
  synthetic documents, so this says the pipeline works, not how it handles
  messy scans.

### Quality signal found during the run: verification fallbacks

Only **7 of 22** answers passed citation verification (chart 2/6, document
1/6, evidence 3/5, mixed 1/5); the rest returned the safe fallback reply. The
dev DB's own log shows the same pattern from earlier sessions (6 passed, 8
failed in the previous three days), so this predates the span work. A
diagnostic re-run showed it is non-deterministic: the identical medications
question failed once with `claim cites 'get_medications' with an incomplete
citation` and passed on the next try. So the verifier is correctly rejecting
claims where the model omits part of the citation, but at this rate the demo
will often show the fallback reply instead of an answer. Not diagnosed further
here: which citation field is omitted, and whether a stricter prompt or a
single retry on failure fixes it. Worth a dedicated look before grading.

## Per-step latency (initial data, superseded by the live sample above)

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

## What the initial data says

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

## Gaps in the initial data (several now fixed; see the instrumentation section)

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

## Instrumentation added after the initial data

Commits `d907dd1be`, `6b021ac96`, `5709993cb` and `cfff1cf52` fixed the gaps in
the initial section; the live sample above is the first data collected with
them. The initial historical tables are kept as-is and are not retroactively
improved.

- Handoff and tool spans now time the worker's real work.
- `voyage.embed`, `retrieval.dense_rank`, `retrieval.fulltext` and
  `voyage.rerank` are child spans with counts and scores only.
- Document extraction has its own trace (`clinical-copilot.extract`) with model,
  tokens, latency, `schema_valid` and required-field completeness, a
  deterministic proxy for extraction confidence rather than a model-reported
  score.
- Still not captured: Voyage token usage and cost, per-call Anthropic HTTP
  timings (so model time is inferred, not measured), and retrieval-hit counts on
  the trace root.

## Suggested follow-ups

- Investigate the 7/22 verification pass rate (log which citation field is
  missing; try a prompt tightening or one retry before falling back).
- Remove the Voyage rate-limit stalls (payment method on the Voyage account, or
  cache query embeddings).
- Record Voyage token usage and per-call Anthropic request timings so model time
  is measured rather than inferred.
- Trim Week 2 context (fewer or shorter guideline chunks, a summary of extracted
  fields); turns send 36k-60k input tokens.
