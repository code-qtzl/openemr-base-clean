# WEEK2_ARCHITECTURE.md — Clinical Co-Pilot Multimodal & Multi-Agent Extension

## Summary

AgentForge2 Week 2 extends the Week 1 single-agent chat co-pilot
(`ARCHITECTURE.md`) with document ingestion, hybrid retrieval over a
clinical-guideline corpus, and a small supervisor/worker agent graph.
Nothing here relaxes Week 1's verification contract — every new capability
still has to earn its way through the same citation-or-refuse rule, not run
alongside it as a separate, less-trusted path.

## Document ingestion & extraction

- `attach_and_extract` pipeline (`Service/Extraction/`): upload → Claude's
  native `document`/`image` content blocks (VLM) → self-validate against a
  strict schema (`SchemaValidator`) → store the source file in OpenEMR's own
  document store → persist derived fields, all linked
  (`DocumentIngestionPipeline`, `ExtractionPromptBuilder`,
  `SqlExtractedDocumentStore`). New table:
  `clinical_copilot_extracted_document`.
- Two required document types: `lab_pdf`, `intake_form`.
- A document-storage failure rolls back the already-inserted extraction row
  rather than leaving an orphan.

## Hybrid RAG over a guideline corpus

- A small clinical-guideline corpus (5 drug labels: metformin, glipizide,
  insulin glargine, empagliflozin, semaglutide), indexed with sparse+dense
  retrieval and reranked via Voyage — `EvidenceRetrieverWorker`.
- Deliberately small, per AgentForge2's "small guideline corpus"
  requirement — not a coverage gap to paper over. A question outside the
  corpus correctly falls back to "insufficient information" rather than
  answering from the model's general training knowledge; the verification
  layer enforces that the same way it enforces citations for chart data.

## Supervisor + workers (multi-agent graph)

- `Supervisor` (`Service/Supervisor/`) is the **live** chat entry point —
  `CopilotChatController` calls it directly, replacing Week 1's
  single-agent `CopilotService` tool loop.
- Three workers, each with a narrow responsibility: `ChartQaWorker`
  (structured chart data — Week 1's four read-only tools),
  `IntakeExtractorWorker` (previously-extracted documents via
  `get_extracted_documents`), `EvidenceRetrieverWorker` (guideline RAG via
  `search_guideline_evidence`).
- **Citation-granularity rule**: every claim must cite the specific tool a
  worker actually called (e.g. `get_medications`), never the worker's own
  dispatch name (`consult_chart_worker` etc. are never valid citations).
  This is enforced structurally by `ResponseVerifier`, not left to prompt
  instruction alone — see `Supervisor`'s own class docblock for the
  reasoning.

## Citation contract extension: click-to-source PDF overlay

AgentForge2 Core Requirement #5's "visual PDF bounding-box overlay":

- A `lab_pdf`/`intake_form` citation now carries `document_id` and a
  normalized `bbox` (`{page, x0, y0, x1, y1}`, 0.0–1.0) in addition to
  Week 1's five-field citation shape (`Citation::requiresDocumentLinkage()`).
  `chart_tool`/`guideline` citations never require either — there's no PDF
  page to point at.
- `document_id` is backend-stamped into the extracted fields after the real
  `documents.id` row exists — never model-invented. `bbox` is
  model-estimated visually during extraction.
- The chat UI renders a **Source N** chip on any such citation; clicking it
  streams the source PDF through OpenEMR core's existing
  session-authenticated `controller.php?document&retrieve` route (no new
  backend endpoint) and draws the highlight box via a vendored pdf.js.

## Eval-gate expansion

- Golden set: 50 cases → 52 (2 new bbox-linkage negative cases added
  alongside the overlay feature). Boolean rubrics across all 5 required
  categories (`schema_valid`, `citation_present`, `factually_consistent`,
  `safe_refusal`, `no_phi_in_logs`) — all currently at 100%.
- `.github/workflows/eval-gate.yml` runs the gate on every push/PR to
  `main`. See `interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/README.md`
  for the validator layer itself.

## Known tradeoffs & accepted risks (Week 2–specific)

- **bbox is an estimate, not a precise crop.** Claude visually approximates
  the cited region from the page image during extraction; expect it to
  loosely bracket the right area, not tightly frame individual glyphs.
- **Pre-overlay documents have no bbox.** Any document extracted before the
  overlay feature shipped (before commit `25af3929e`) has no
  `document_id`/`bbox` embedded in its stored fields, so citations to those
  older documents won't produce a clickable chip — re-upload to exercise
  the overlay.
- **Voyage rate limit.** The account tier currently in use allows 3
  requests/minute without a payment method on file. The client retries
  automatically (up to 5 attempts, ~21s apart), so guideline questions
  still succeed — just slower under rapid-fire manual testing.
- **RAG corpus coverage is narrow by design** (see above) — this is the
  same "honest refusal over fabrication" tradeoff Week 1 made for chart
  data, applied to guideline evidence.

## Where Week 1 ends and Week 2 begins

- **Week 1 baseline**: single-agent `CopilotService` tool loop, four
  read-only chart tools, citation-forced verification, correlation-id
  tracing, Langfuse observability. See `ARCHITECTURE.md`.
- **Week 2 additions**: everything above — document ingestion, hybrid RAG,
  the `Supervisor` multi-agent graph as the live entry point, the
  citation/bbox overlay, and the expanded eval gate.
  `CopilotChatController` has called `Supervisor` instead of
  `CopilotService` since commit `d313556b8`; no branch or environment
  variable switch is needed to see Week 2 behavior — it's the only live
  path on `main`.
