# Manual Testing: Supervisor + Hybrid RAG, Live

What this covers: the three pieces of work landed this session —

1. Citation contract migration (every claim now carries `{source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value}`, not a bare tool name).
2. `EvidenceRetrieverWorker` — hybrid (sparse+dense) RAG over a small guideline corpus (5 drug labels: metformin, glipizide, insulin glargine, empagliflozin, semaglutide), reranked via Voyage.
3. `Supervisor` wired in as the **live** chat entry point (`CopilotChatController` no longer calls `CopilotService`).

Automated coverage (PHPUnit, PHPStan, PHPCS) already passed for all of this. This doc is for confirming it by hand, through the real UI, the way a user actually experiences it.

## Prerequisites

- Dev stack running: `cd docker/development-easy && docker compose up --detach --wait`
- Real API keys present in `docker/development-easy/.env` (gitignored):
  - `OPENEMR__COPILOT_API_KEY` (Anthropic)
  - `OPENEMR__VOYAGE_API_KEY` (Voyage — needed for the RAG worker specifically)
  - If you just added/changed either key, recreate the container so it picks them up: `docker compose up --detach --wait --force-recreate openemr`
- Guideline corpus seeded (one-time, idempotent — re-running is safe):
  ```
  docker exec -w /var/www/localhost/htdocs/openemr -u apache development-easy-openemr-1 \
    php interface/modules/custom_modules/oe-module-clinical-copilot/bin/seed-guideline-corpus.php
  ```
  Expect: `Guideline corpus seeded: 5 source(s), 20 chunk(s) total.`
- App reachable at **http://localhost:8300/** (or https://localhost:9300/), login `admin` / `pass`.

## Test 1 — Basic chart-data question (proves `ChartQaWorker` via `Supervisor`)

1. Log in, open any patient's chart (Patient List → pick one, or jump straight to a known Synthea-seeded demo patient at `http://localhost:8300/interface/patient_file/summary/demographics.php?set_pid=2`).
2. The Co-Pilot sidebar should be open by default on the right. If closed, click the reopen tab.
3. Ask: **"What active problems does this patient have?"**
4. Expect: a grounded reply, and underneath it a line like `Chart data read: get_active_problems, ...` naming the specific tool(s) consulted — **not** `consult_chart_worker` (that's the internal dispatch name; citations must always name the granular tool per the citation-granularity rule).

## Test 2 — Document question (proves `IntakeExtractorWorker`)

1. In the same sidebar, click the attach/upload icon.
2. Upload one of the synthetic fixtures from `intake-forms/` (repo root) — pick one under `existing-patient/` matching the patient you have open (pid 2, 7, 18, 20, or 25).
3. Confirm the upload succeeds (a confirmation message appears in the log).
4. Ask a follow-up in the **same conversation**: **"What did the uploaded document show?"**
5. Expect: the reply cites the document's extracted fields, and the tool log shows `get_extracted_documents`.

## Test 3 — Guideline/medication-safety question (proves `EvidenceRetrieverWorker` / RAG — the new capability)

1. Ask: **"Is metformin safe for a patient with severe renal impairment?"**
2. Expect: a reply citing label language (e.g. "contraindicated in patients with severe renal impairment"), and the tool log includes `search_guideline_evidence`.
3. Try a second one outside the seeded corpus, e.g. **"Any interaction concerns between ibuprofen and lisinopril?"** — since neither drug is in the 5-label corpus, expect an honest "I do not have enough information" rather than a fabricated answer. This is the correct, intended behavior, not a bug.

## Test 4 — Multi-turn conversation

1. In one sitting, ask a first question, then a second question that only makes sense as a follow-up (e.g. "...and how long has that been on the list?").
2. Expect: the second reply is coherent with the first — proves `Supervisor` receives and uses conversation history the same way `CopilotService` did.

## Test 5 — Cross-patient isolation

1. With a conversation already going for patient A, switch to a different patient (Patient List → select someone else).
2. Ask a new question.
3. Expect: no trace of patient A's conversation or data appears — a fresh context for patient B.

## How to confirm it's really the new live path (not a cached/fallback behavior)

- The "Chart data read: ..." line under each reply lists the actual tools consulted that turn — seeing `search_guideline_evidence` appear at all is proof `EvidenceRetrieverWorker` fired, since that tool did not exist before this session's work.
- Server-side, tail the PHP error log for the correlation id shown if you want to trace a specific request: `openemr-cmd php-log` (alias `pl`), or check `clinical_copilot_log` in phpMyAdmin (http://localhost:8310/) — every row has a `correlation_id` column.

## Known limitations while testing

- **Voyage rate limit**: without a payment method on the Voyage account, the API allows only 3 requests/minute. The client retries automatically (up to 5 attempts, ~21s apart), so a guideline question still succeeds — it may just take a few extra seconds if you've been firing guideline questions rapidly. If you see a guideline question fail outright, wait ~30s and retry.
- **Corpus coverage**: only the 5 drugs listed above are seeded. A guideline question about any other drug will correctly fall back to "insufficient information" rather than answer from general knowledge — that's the verification layer working as designed, not a gap to "fix."
- **Non-guideline questions never call Voyage** — only `search_guideline_evidence` does, so most testing won't hit the rate limit at all.

## Troubleshooting

- **Sidebar says the co-pilot is "not configured"**: `OPENEMR__COPILOT_API_KEY` is missing/empty in `docker/development-easy/.env`. Add it, then `docker compose up --detach --wait --force-recreate openemr`.
- **A guideline question always returns "insufficient information," even for metformin**: confirm the corpus is actually seeded (`SELECT COUNT(*) FROM clinical_copilot_guideline_chunk;` should return 20) and that `OPENEMR__VOYAGE_API_KEY` is set and valid.
- **Nothing happens when you click Send**: open the browser console — a CSRF/session issue usually shows as a 403 on the `ajax.php` request; refreshing the page gets a fresh CSRF token.
