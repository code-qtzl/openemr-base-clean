# ARCHITECTURE.md — Clinical Co-Pilot AI Integration Plan

## Summary

This document outlines the technical architecture, verification strategy, and engineering roadmap for integrating the Clinical Co-Pilot into OpenEMR. The system is designed as a tool-calling chat agent embedded within the patient demographics page, specifically engineered to isolate patient data and strictly control LLM context.

### Core Architecture & Framework Choices

- Patient-Scoped Tooling: The AI never receives the full patient chart in its prompt. Instead, it uses zero-argument tools (e.g., get_medications, get_a1c_series) strictly scoped server-side to the clinician's active session ID. This design guarantees the model cannot request or access another patient's data, eliminating cross-patient access risks by construction.

- Legacy Integration with Modern Contracts: The agent operates as a custom module on top of OpenEMR's legacy authorization substrate (globals.php, AclMain). However, data passed to the model is strictly typed into read-only Data Transfer Objects (DTOs) enforced by PHPStan, ensuring stable, predictable data shapes.

- Persistent State Management: Multi-turn conversation memory is persisted in a new database table (clinical_copilot_conversation) keyed by session and patient ID. This provides durable state that survives mid-visit timeouts, enabling contextual follow-up questions.

### Verification Strategy

- Source Attribution: The model's output is forced into a structured JSON schema mapping every clinical claim to a specific tool call. A post-hoc middleware check ensures the AI only cites tools it actually executed, stripping out fabricated claims before they reach the browser.

- Domain Constraint Rules: Explicit, hard-coded rules act as a safeguard against hallucinations. For instance, if the medication tool returns zero rows, the system programmatically prevents the AI from claiming the patient is on active medications.

### Performance Strategy & Observability

- Latency Optimization: The baseline configuration (Claude Opus, adaptive thinking, blocking requests) is too slow for clinical use. The architecture mandates benchmarking against faster models (like Claude Sonnet), reducing max tool iterations from eight to three, and implementing Server-Sent Events (SSE) streaming for real-time UI rendering.

- Telemetry: End-to-end correlation IDs trace every request. Langfuse is implemented for LLM-specific operational tracing (latency, tokens), while a native, self-hosted database table handles immutable compliance auditing.

### Known Tradeoffs & Accepted Risks

- Authorization: Version 1 relies on OpenEMR's broad role-based access rather than strict care-team scoping. This is an accepted risk for a solo-practitioner rollout but is documented as a hard blocker for multi-user deployments.

- Prompt Injection: Clinician-entered free-text fields flow into the LLM, creating an injection surface. This is partially mitigated via untrusted-data XML delimiters, but remains a known limitation.

- Compliance Infrastructure: The current Railway infrastructure lacks a Business Associate Agreement (BAA). The deployment is strictly limited to synthetic patient data until compliant hosting is procured.
