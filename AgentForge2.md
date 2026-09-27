AgentForge
Clinical Co-Pilot - Week 2

Multimodal Evidence Agent: seeing clinical documents, routing work, and gating changes with evals

How to Use This Assignment
Your Week 1 agent already reads structured OpenEMR data, attributes claims, logs tool behavior, and has a starter eval suite. This week, you add two new capabilities: the agent can read real-world clinical documents, and it can route work across a small multi-agent graph without losing grounding.

Gate: Eval-driven CI is non-negotiable. A working demo that cannot block regressions has not met the Week 2 standard.

The Scenario
A primary care physician is prepping for a follow-up visit. The chart has structured OpenEMR data, but the important recent information is buried in a scanned lab PDF and a patient intake form uploaded by the front desk. The physician asks: What changed, what should I pay attention to, and what evidence supports the recommendation?
Your Week 2 Clinical Co-Pilot must ingest the lab PDF and intake form, extract structured facts with citations, retrieve relevant guideline evidence, and return a grounded answer. The answer should be useful even if the document scan is imperfect, the patient record is incomplete, or the user asks a follow-up question.

Why this matters: Clinical agents fail when they cannot handle the messy inputs clinicians actually receive. Week 2 is about making the agent see, keeping the architecture small enough to reason about, and proving quality through automated evals.

The Hard Problems

Vision extraction without invention
A VLM can read a scanned form, but it can also hallucinate field labels or overstate confidence. Your schema, source links, and verification strategy must make unsupported extracted facts visible.

Evidence grounding
Every answer must separate patient-record facts from guideline evidence. A medication or lab claim is not acceptable unless it points back to a source.

Multi-agent architecture
The goal is to give multiple workers clear responsibilities and make the supervisor's routing decisions inspectable.

Eval-driven development
You will use a 50-case golden set with boolean rubrics. The CI gate must catch regressions before they reach the demo.

FHIR and OpenEMR integrity
Uploaded documents and derived observations must round-trip through OpenEMR without creating duplicate or untraceable records.

HIPAA-minded development
Use only demo or synthetic data. Do not log raw PHI. Treat prompts, extracted fields, document images, traces, and screenshots as sensitive.

Key Metrics
As AI engineers, we must identify the key metrics that indicate our product is successful. The metrics you select should provide a strong signal that your product is delivering on your promise. The number of metrics and what the metrics measure is up to you.
Hard Gate: a markdown document (./KEY_METRICS.md) with your key metrics listed and your rationale for including it.

The Codebase
Build on the Week 1 fork, auth flow, tool layer, verification strategy, observability, and eval harness. Good Week 1 architecture should compound here; technical debt from Week 1 should be documented and resolved before adding new surface area.
The Week 2 repo may stay inside the same OpenEMR fork, but your README must clearly separate Week 1 baseline behavior from Week 2 multimodal behavior. Graders should be able to run the core Week 2 flow without guessing which branch, environment variable, or service is required.

Recommended Starting Steps
This is not a full medical-document AI platform. It is a controlled expansion of the Week 1 agent into two document types, two workers, and one regression gate.

| Name                       | Deliverable                                                                                  |
| -------------------------- | -------------------------------------------------------------------------------------------- |
| Ingest two document types  | Upload and extract a lab PDF and an intake form using strict schemas.                        |
| Build basic hybrid RAG     | Small guideline corpus indexed with keyword+dense retrieval and Cohere rerank or equivalent. |
| Add supervisor + 2 workers | Supervisor routes to intake-extractor and evidence-retriever with logged handoffs.           |
| Gate with eval-driven CI   | 50-case golden set, boolean rubrics, PR-blocking Git Hook.                                   |
| Integrate and demo         | Deployed app, source-grounded UI, latency/cost report, walkthrough video.                    |

Stage 1 - Ingest Lab PDF and Intake Form
Implement a document ingestion flow that accepts a file, associates it with a patient, stores the source document in OpenEMR, extracts structured JSON, and links every derived fact back to the source. Required document types are a lab PDF and an intake form.
Stage 2 - Build Basic Hybrid RAG
Create a small clinical-guideline corpus relevant to your user profile. Use keyword plus vector retrieval, rerank the candidate chunks, and return evidence snippets with source metadata. ColQwen2 and multi-vector indexing are stretch; the core requirement is a reliable hybrid retriever.
Stage 3 - Add Supervisor + 2 Workers
Implement a small graph: one supervisor, one intake-extractor worker, and one evidence-retriever worker. The supervisor should decide when extraction is needed, when evidence retrieval is needed, and when the final answer is ready. Keep handoffs explicit.
Stage 4 - Build the Eval Gate
Create 50 synthetic or demo cases that exercise extraction, evidence retrieval, citations, refusals, and missing-data behavior. Use boolean rubrics, not 1-10 ratings. CI must fail on meaningful regression.
Stage 5 - Integrate, Deploy, and Defend
Expose the Week 2 flow in the deployed app, capture observability traces, record a demo, and prepare to explain why each capability maps back to the Week 1 user and workflow.

Core Agent Requirements

1. Document ingestion and extraction
   Implement attach_and_extract(patient_id, file_path, doc_type) or an equivalent tool. It must support lab_pdf and intake_form. It must store the source document in OpenEMR, return strict-schema JSON, and persist derived facts as appropriate FHIR resources or OpenEMR records.

2. Structured schemas
   Use Pydantic, Zod, or equivalent strict schemas. Required lab fields include at least test name, value, unit, reference range, collection date, abnormal flag, and source citation. Required intake fields include demographics fields, chief concern, current medications, allergies, family history, and source citation.

3. Basic hybrid RAG plus rerank
   Index a small clinical-guideline corpus. Retrieve with sparse+dense search, rerank candidate chunks with Cohere Rerank or an equivalent reranker, and feed only the top grounded evidence to the answer model.

4. Supervisor plus two workers
   Use LangGraph, the OpenAI Agents SDK, or another inspectable orchestration framework. Required workers are intake-extractor and evidence-retriever. A critic agent is extension work, not core.

5. Citation contract
   Every clinical claim in the final response must include machine-readable citation metadata. Minimum citation shape: {source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value}. A visual PDF bounding-box overlay is required.

6. Eval-driven CI gate
   Build a 50-case golden set and a PR-blocking Git Hook. Boolean rubric categories must include schema_valid, citation_present, factually_consistent, safe_refusal, and no_phi_in_logs. The build must fail if any category regresses by more than 5% or drops below the pass threshold.

7. Observability and cost tracking
   Each encounter must log tool sequence, latency by step, token usage, cost estimate, retrieval hits, extraction confidence, and eval outcome. Logs must not contain raw PHI.

Hard Gate: During grading, we will introduce a small regression and confirm your CI gate fails. If the eval gate does not block the regression, the Week 2 build does not pass.

Core Deliverables

- Two document types: lab PDF and intake form.
- One supervisor and two workers: intake-extractor and evidence-retriever.
- Basic hybrid RAG plus rerank over a small guideline corpus.
- 50-case golden dataset with boolean rubrics.
- PR-blocking eval CI and an observable deployed demo.
- Critic agent that rejects uncited claims or unsafe action suggestions.
- Click-to-source UI for citation snippets, with a simple document preview.
- A third document type such as referral fax or medication list.
- Lab trend chart widget that uses extracted Observation data.
- Contextual retrieval improvements such as better chunking, query rewriting, or domain-specific filters.

Common Pitfalls and Watch-Outs

- Trying to support five document types before two work reliably.
- Using a VLM answer directly without schema validation or source metadata.
- Letting the supervisor become a black box. Handoffs must be logged and explainable.
- Using llm-as-a-judge without clear rubric. Use boolean rubrics so failures are actionable.
- Logging raw document text, patient identifiers, or screenshots to SaaS observability tools.
