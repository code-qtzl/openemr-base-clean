# Manual Testing: Click-to-Source PDF Bounding-Box Overlay

What this covers: AgentForge2 Core Requirement #5's "visual PDF bounding-box
overlay" — the last piece of the citation contract. When a claim cites a
`lab_pdf`/`intake_form` document, the reply now shows a **Source N** chip;
clicking it opens the original PDF page with a highlighted box over the
region the value was extracted from.

Pieces landed this session:

1. `Citation`/`CitationBoundingBox` — `document_id` + `bbox` (`{page, x0, y0,
   x1, y1}`, normalized 0.0–1.0) added to the five-field citation contract,
   required only for `lab_pdf`/`intake_form` (`Citation::requiresDocumentLinkage()`).
2. `document_id` is stamped into `fields.source_citation` by
   `DocumentIngestionPipeline` after the real DB row exists — never
   model-invented. `bbox` is model-estimated during extraction
   (`ExtractionPromptBuilder`).
3. `document-viewer.js` (+ vendored pdf.js) — renders the cited PDF page on a
   canvas and draws the highlight box, streamed via OpenEMR core's existing
   session-authenticated `controller.php?document&retrieve` route (no new
   backend endpoint).
4. **A real bug found and fixed during live verification**: OpenEMR's legacy
   `Controller::act()` routing passes query params to
   `C_Document::retrieve_action()` *positionally* (by query-string order),
   not by name. The retrieve URL must carry only `document&retrieve` —
   `document-viewer.js` appends `patient_id`, `document_id`, `as_file`,
   `original_file` in that exact order. Getting this order wrong silently
   swaps all four arguments and the viewer fails with "Could not load that
   document" while the network tab shows a deceptive `200 OK`. See
   `CopilotPanelController`'s class docblock for the full mechanism, and
   `CopilotPanelControllerTest` for the regression guard.

Automated coverage (PHPUnit — 86 isolated + 3 DB-backed + the new
`CopilotPanelControllerTest` regression guard; PHPStan level 10; PHPCS;
ESLint; the 52-case eval gate at 100%) already passed for all of this. This
doc is for confirming it by hand, through the real UI, the way a clinician
actually experiences it.

## Prerequisites

- Dev stack running: `cd docker/development-easy && docker compose up --detach --wait`
- Real API key present in `docker/development-easy/.env` (gitignored):
  `OPENEMR__COPILOT_API_KEY` (Anthropic). If you just added/changed it,
  recreate the container: `docker compose up --detach --wait --force-recreate openemr`
- App reachable at **http://localhost:8300/** (or https://localhost:9300/),
  login `admin` / `pass`.
- A synthetic intake-form PDF to upload — any file under `intake-forms/` at
  the repo root. Use one under `existing-patient/` matched to the patient
  you open below (pid 2, 7, 18, 20, or 25) so the extracted demographics
  look sane, though the overlay mechanics work regardless of which fixture
  you pick.

## Test 1 — Upload → immediate source chip → overlay (the direct path)

1. Log in, open a Synthea-seeded demo patient, e.g.
   `http://localhost:8300/interface/patient_file/summary/demographics.php?set_pid=2`.
2. The Co-Pilot sidebar should be open by default on the right.
3. Click the attach/paperclip icon, select doc type **Intake form**, choose
   a PDF from `intake-forms/existing-patient/`, click **Upload**.
4. Wait for extraction (roughly 30–60s). Expect a confirmation message
   summarizing the extracted fields, followed by a **Source 1** chip.
5. Click **Source 1**. Expect: a modal titled "Source 1" opens, the PDF page
   renders, and a yellow-highlighted box appears over the region the
   extraction actually cites (for most intake-form fixtures, the
   demographics table near the top of page 1).
6. Close the modal via the **×** button, then reopen and close it again via
   **Escape** and by clicking the dark backdrop — all three should close it.

## Test 2 — Chat-cited source chip (proves the full Supervisor → claims path)

1. In the same conversation as Test 1, ask: **"What allergies does this
   patient have per the uploaded intake form?"**
2. Expect: a grounded reply with a **Source N** chip attached to it (not
   just the upload-confirmation message's chip).
3. Click that chip. Expect the same overlay behavior as Test 1, now proving
   the citation round-tripped through `Supervisor` → `ResponseVerifier` →
   `AskResult.claims` → `CopilotChatController`'s JSON response →
   `copilot.js`'s `appendSourceChips`, not just the upload endpoint's
   one-time response.

## Test 3 — Non-document citations get no chip

1. Ask a question answerable purely from structured chart data, e.g.
   **"What active problems does this patient have?"**
2. Expect: a grounded reply with **no** Source chip — `chart_tool` citations
   have no PDF page to point at (`Citation::requiresDocumentLinkage()` is
   `false` for them), so `copilot.js`'s `isDocumentCitation` correctly
   filters them out.

## Test 4 — Multiple documents, multiple chips

1. Upload a second document (a different fixture, or the other doc type —
   `lab_pdf`).
2. Ask a question whose grounded answer draws on both uploaded documents.
3. Expect: multiple **Source N** chips (numbered independently per claim),
   and each opens the *correct* document at the *correct* page/region — not
   the most-recently-uploaded one for every chip.

## How to confirm it's really working (not silently failing)

- A **200 OK** on the retrieve request is not proof of success — the bug
  this session fixed produced exactly that with an empty body. The real
  signal is the PDF actually rendering on canvas with a visible highlight
  box. If you want to check the network layer directly: open DevTools →
  Network, click a source chip, find the `controller.php?document&retrieve...`
  request, and confirm `Content-Type: application/pdf` and a non-zero
  `Content-Length` — not `text/html` with `Content-Length: 0`.
- `openemr-cmd php-log` (alias `pl`) will show `OpenEMR.ERROR: Document file
  not found or insufficient permissions` with `document_id`/`patient_id`
  values that look like the literal strings `"true"`/`"false"` if the
  positional-argument bug ever regresses.

## Known limitations while testing

- **bbox is model-estimated, not pixel-perfect.** Claude visually estimates
  the bounding box from the page image during extraction; expect it to
  loosely bracket the right region, not tightly crop to the exact glyph.
- **Only `lab_pdf`/`intake_form` citations get a bbox/chip.** `guideline`
  citations (from the evidence-retriever worker) never will — there's no
  PDF page in the guideline corpus to point at.
- **Documents extracted before this session's changes have no bbox.** Any
  `clinical_copilot_extracted_document` row created before this work won't
  have `document_id`/`bbox` embedded in `fields_json`, so citations to those
  older documents won't produce a clickable chip. Upload a fresh document to
  test the overlay.

## Troubleshooting

- **"Could not load that document" in the modal**: open DevTools → Network
  and check the retrieve request's query string. If `as_file`/
  `original_file` appear *before* `patient_id`/`document_id`, the
  positional-argument bug has regressed — see `CopilotPanelController`'s
  class docblock.
- **No Source chip appears at all after a document-grounded answer**:
  confirm the extracted document's `fields_json` actually has a `bbox`
  (`SELECT JSON_EXTRACT(fields_json, '$.fields.source_citation.bbox') FROM
  clinical_copilot_extracted_document WHERE id = ...`) — a `NULL` bbox means
  either the document predates this feature (see above) or the model
  declined to estimate one, in which case `ResponseVerifier` correctly
  rejects the citation as incomplete rather than showing a broken chip.
- **Sidebar says the co-pilot is "not configured"**: `OPENEMR__COPILOT_API_KEY`
  is missing/empty in `docker/development-easy/.env`. Add it, then
  `docker compose up --detach --wait --force-recreate openemr`.
