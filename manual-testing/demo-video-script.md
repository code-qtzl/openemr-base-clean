# Demo Video Script: Clinical Co-Pilot (AgentForge2 Week 2)

Target length: **about 7 minutes**, screen recording with voice-over. Everything on
screen is synthetic data (Synthea patients plus the generated documents in
`demo-documents/`); no real PHI.

The script maps to AgentForge2's Stage 5 ("Integrate, Deploy, and Defend"): show the
deployed app, the two document types, source-grounded citations, hybrid RAG, the
supervisor's handoffs, observability, and the eval gate blocking a regression.

Lines starting with **SAY** are narration. **DO** is what to click or type.
**EXPECT** is what the screen should show (from real dry runs on 2026-10-05; the
model is stochastic, so wording varies). **IF NOT** is the recovery.

---

## Before you record

**Where:** record on the **deployed app**
(`https://openemr-production-a819.up.railway.app`), since the spec asks for a
deployed demo. Log in as `admin` with the Railway admin password (it is rotated
at boot and is not in the repo). The dev stack (`http://localhost:8300`,
`admin` / `pass`) is the fallback.

**Patient:** pid **2**, Eddie White (male, DOB 1975-12-22): type 2 diabetes,
hypertension, metabolic syndrome; chart medications include lisinopril and
naproxen. Open
`<base-url>/interface/patient_file/summary/demographics.php?set_pid=2` and confirm
the name before recording.

**Files to upload** (all in `demo-documents/`, each labelled synthetic):

| File | Doc type to choose |
|---|---|
| `intake-form-06-white.pdf` | Intake form |
| `lab-a1c-white-2026-09.pdf` (A1c 8.1%, 2026-09-14) | Lab PDF |
| `lab-egfr-white-2026-09.pdf` (eGFR 52, 2026-09-14) | Lab PDF |

Optional fourth, for the "what changed" beat: `lab-a1c-white-2026-03.pdf`
(A1c 7.4%, 2026-03-10).

**Rehearse once first.** Two things cost time on camera:
- Each answer takes **roughly 30-60 s** (the broad "what changed" question took
  up to ~3 minutes in testing). Plan to **cut or speed-up** the waits in editing
  and say so in the narration ("sped up").
- Guideline questions call Voyage, which rate-limits at 3 requests/minute without
  a payment method. A 429 adds 21 s sleeps. Ask one guideline question before
  recording to warm up, and do not fire two back to back.

**Tabs to have open, signed in:** the OpenEMR patient chart; Langfuse
(environment filter `production`); `<base-url>/chatbot-metrics/`; the GitHub
repo's Actions tab; PR #66 (https://github.com/code-qtzl/openemr-base-clean/pull/66);
a terminal at the repo root.

**Do not show:** `.env` files, API keys, the Langfuse keys page, the Railway admin
password, or browser autofill.

---

## Run of show

| Time | Scene |
|---|---|
| 0:00 | 1. The problem and the user |
| 0:35 | 2. Chart question and the "chart data read" line |
| 1:10 | 3. Upload an intake form and two lab PDFs |
| 2:30 | 4. Click-to-source: the bounding-box overlay |
| 3:15 | 5. The headline question: patient facts vs guideline evidence |
| 4:45 | 6. Saying "I don't know" |
| 5:15 | 7. Under the hood: handoffs, spans, cost |
| 6:15 | 8. The eval gate blocks a regression |
| 6:50 | 9. Close, with honest limits |

---

### 1. The problem and the user (0:00-0:35)

**DO:** Start on the OpenEMR chart for Eddie White with the Co-Pilot sidebar open.

**SAY:** "A primary care physician is prepping for a follow-up. The chart has
structured data, but the recent news is buried in a scanned lab PDF and an intake
form the front desk uploaded. The questions are: what changed, what should I pay
attention to, and what evidence supports it. This is the Clinical Co-Pilot, built on
OpenEMR. Week 1 gave it a grounded chart-reading agent; Week 2 lets it read
documents, retrieve guideline evidence, and prove its quality with an eval gate."

### 2. Chart question (0:35-1:10)

**DO:** Ask **"What conditions does this patient have on file?"**

**EXPECT:** A cited answer, and under it a line like
`Chart data read: get_active_problems, ...` naming the specific tools used.

**SAY:** "Every claim carries a machine-readable citation. This line names the
actual data source, not an internal worker name. If a claim isn't backed by a tool
called this turn, a verification layer replaces the answer with a safe fallback."

### 3. Upload documents (1:10-2:30)

**DO:** Click the attach icon, choose **Intake form**, upload
`intake-form-06-white.pdf`. Repeat with **Lab PDF** for
`lab-a1c-white-2026-09.pdf` and `lab-egfr-white-2026-09.pdf`.

**EXPECT:** A confirmation after each upload, about **7 s for an intake form and
10-12 s for a lab PDF**.

**SAY:** "Each upload is stored in OpenEMR as a real document, and a vision model
extracts it into a strict schema: lab fields like test name, value, unit,
reference range and collection date, or intake fields like demographics, chief
concern, medications and allergies. The result is checked against the schema before
anything is trusted, and each extraction is linked to its source document so there
are no duplicate or orphaned records."

**IF NOT:** an upload error means the document type was wrong or the file is not a
PDF/PNG/JPEG; re-upload. A "schema invalid" message is the validator working, not a
crash; say so and move on with another file.

### 4. Click-to-source (2:30-3:15)

**DO:** Ask **"What allergies are listed on the uploaded intake form?"** When the
answer appears, click a **Source N** chip on an intake-form claim.

**EXPECT:** The original PDF page opens with a highlighted box over the region the
value came from. The four allergies on the form are mold, house dust mite, tree
pollen and cow's milk (no drug allergies).

**SAY:** "Each document claim carries a document id and a bounding box. Clicking it
shows the clinician exactly where in the original PDF the value came from, so they
can verify it instead of trusting it."

**IF NOT:** if the overlay says "Could not load that document", refresh and retry; do
not claim it worked.

### 5. The headline question (3:15-4:45)

**DO:** Ask **"Is metformin appropriate for this patient given the latest eGFR?"**
(about 30 s, the most reliable on camera). If time allows and you have patience for a
long wait, ask **"What changed in the labs, what should I pay attention to, and what
evidence supports it?"** instead and speed through the wait.

**EXPECT:** An answer that finds the uploaded eGFR of 52 (flagged low), cites the FDA
metformin label (contraindicated below eGFR 30, so 52 does not meet that threshold),
notes metformin is not on the chart list, and flags the medications on the chart.
Citation chips of different types appear: chart, lab PDF and guideline.

**SAY:** "Notice the answer separates what comes from this patient's record (the
eGFR from the uploaded lab, the medication list) from what comes from guideline
evidence (the label text). Behind this are three workers: one reads chart data, one
reads extracted documents, one retrieves guideline evidence. The supervisor decides
which to consult. Retrieval is hybrid: keyword plus embeddings, fused, then
reranked, and only the top results reach the model. It also reports where its
sources disagree rather than smoothing it over."

**Optional second beat:** ask **"What does the intake form say the current medications
are, and do they match the chart?"** The model flags that the intake form lists
albuterol and methotrexate that the chart does not. This is a real discrepancy in the
synthetic data and a good example of the agent surfacing something to reconcile.

### 6. Saying "I don't know" (4:45-5:15)

**DO:** Ask **"Any interaction concerns between ibuprofen and lisinopril?"**

**EXPECT:** An honest "not enough information" style answer. Neither drug is in the
small guideline corpus (five drug labels: metformin, glipizide, insulin glargine,
empagliflozin, semaglutide).

**SAY:** "The corpus is deliberately small. When the evidence isn't there, the agent
says so instead of answering from general knowledge. That is the safe-refusal
behaviour the eval gate also tests."

### 7. Under the hood (5:15-6:15)

**DO:** Switch to Langfuse and open the trace for the metformin question. Then show
`<base-url>/chatbot-metrics/`.

**SAY (Langfuse):** "Each request is one trace. You can see the supervisor's
handoffs to each worker, the Voyage embed and rerank steps with timing, and token
usage and cost. These carry counts and scores only, never patient text. Document
extraction has its own trace with latency, tokens and a completeness score: how
many of the required schema fields came back present."

**SAY (numbers, from `WEEK2_PERFORMANCE.md`):** "In a 22-question live sample, median
latency was about 31 seconds and cost about 31 cents per question. Retrieval and
routing were around 2% of that; nearly all the time is the model. The one real
retrieval risk was Voyage rate limiting on the free tier. Extraction took 7 to 12
seconds and about 3 cents per document."

**SAY (dashboard):** "This dashboard tracks eval results, key metrics, cost and
alerts."

### 8. The eval gate (6:15-6:50)

**DO:** In the terminal run `composer eval-gate` (or show a recent passing run in the
Actions tab). Then open PR #66.

**EXPECT:** `Eval gate: PASS` with 54 golden-set cases and the five categories
(`schema_valid`, `citation_present`, `factually_consistent`, `safe_refusal`,
`no_phi_in_logs`) at 100%. PR #66 shows a **failed** `Eval Gate` check and a blocked
merge.

**SAY:** "A 54-case golden set with boolean rubrics gates every change. To prove it
blocks regressions I opened a deliberately broken PR that weakened the citation
check. The gate dropped that category below its threshold and GitHub blocked the
merge. It also runs as a local pre-push hook."

### 9. Close (6:50-7:20)

**SAY (honest limits, say them):** "Things I would call out: answers take around half
a minute, longer when several workers run. A lab panel is split into one result per
test, but the upload is all-or-nothing and capped at 50 results, so one malformed row
rejects the whole panel. The guideline corpus covers five drugs. And I found while testing that most answers were being
rejected by the citation verifier because of a schema inconsistency; I fixed it and
the pass rate on a before/after test went from 3 of 8 to 8 of 8."

**SAY (close):** "This keeps the Week 1 principle that nothing reaches the clinician
without a source, and extends it to documents and guideline evidence, with automated
evals to keep it that way."

---

## If something goes wrong on camera

| Symptom | Likely cause | What to do |
|---|---|---|
| Reply is the generic safe fallback | The verifier rejected an incomplete citation (non-deterministic) | Ask again; mention that this is the safety net working |
| Guideline answer takes 40-90 s | Voyage 429 retry sleeps | Cut the wait; ask only one guideline question per minute |
| "Co-pilot not configured" | Anthropic key missing in the environment | Switch to the dev stack |
| Overlay says "Could not load that document" | Session/route issue | Refresh; if it persists, skip the overlay and say it is shown in the manual-testing guide |
| Evidence answer says no information on a metformin question | Corpus not seeded in that environment | Seed it (see `manual-testing/supervisor-rag-live-testing.md`) |

## After recording

- Skim the recording for keys, passwords or real names before uploading.
- Delete the extra extractions you created if recording on the dev stack:
  `DELETE FROM clinical_copilot_extracted_document WHERE pid = 2;`
  (the uploaded documents themselves remain in the chart, which is harmless).
- Add the video link to the README's Clinical Co-Pilot section.
