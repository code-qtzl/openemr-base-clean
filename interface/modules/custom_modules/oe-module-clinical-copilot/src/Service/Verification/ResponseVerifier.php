<?php

/**
 * Minimum-viable source-attribution verification for a co-pilot answer.
 *
 * ARCHITECTURE.md describes forcing structured output that maps every
 * clinical claim to the tool call that supports it; nothing enforced that
 * before this class. CopilotService/Supervisor force the model's final turn
 * to call a `submit_answer` tool (see its input schema) instead of returning
 * free text, so every answer arrives here as structured claims rather than
 * prose this class would have to parse.
 *
 * Verification is deliberately strict: a claim with no citation, a citation
 * missing a required field, a citation to a tool that was not actually
 * called this turn, or a citation to get_medications/search_guideline_evidence
 * when that call returned zero rows (PUNCH_LIST.md 1.4(b)) all reject the
 * whole answer in favor of a safe fallback. Nothing partial ever reaches the
 * browser -- either every claim is grounded, or the clinician sees an honest
 * "not enough information" instead.
 *
 * CITATION SHAPE: every claim's citation carries the same five-field contract
 * the citation_present eval gate validates (source_type, source_id,
 * page_or_section, field_or_chunk_id, quote_or_value -- see
 * Service\Citation\Citation). `source_id` is always, for every source_type,
 * exactly what a bare `source_tool` string used to hold: the specific
 * data-source key called this turn (e.g. get_medications,
 * get_extracted_documents, search_guideline_evidence) -- never a worker's own
 * name (consult_chart_worker/consult_document_worker/consult_evidence_worker
 * are not valid citations; see Supervisor.php's CITATION GRANULARITY note).
 *
 * `source_type` taxonomy: `chart_tool` (structured OpenEMR data, source_id =
 * the literal tool name), `lab_pdf`/`intake_form` (document-extraction
 * citations, source_id = get_extracted_documents), `guideline` (guideline
 * evidence, source_id = search_guideline_evidence).
 *
 * KNOWN, DELIBERATE DIVERGENCE from CitationValidator's eval rubric: a
 * `chart_tool` citation is not required to carry `page_or_section` here (a
 * database row has no page), even though CitationValidator's blanket
 * five-field rubric requires it unconditionally for every source_type. Every
 * other source_type (lab_pdf, intake_form, guideline) still requires all
 * five fields. This means a `chart_tool` claim that passes this live gate
 * could still show up as citation_present: false if ever re-run through the
 * eval validator -- an accepted, documented gap, not an oversight.
 *
 * Known MVP limitation: the get_medications zero-row guard does not
 * distinguish a claim asserting the patient IS on a drug from one correctly
 * reporting that NONE are on file -- both cite the same tool with the same
 * zero row count, and this class does not attempt to infer a claim's
 * polarity from its text (an unreliable thing to guess at). So a truthful
 * "no active medications recorded" claim routed through `claims` rather
 * than `insufficient_information` is rejected to the same fallback as a
 * hallucinated one would be. This is intentionally conservative: an
 * over-cautious "I don't know" is a worse answer but never an unsafe one,
 * which is the tradeoff PUNCH_LIST.md 1.3 calls for at this MVP stage. The
 * same reasoning now also applies to the search_guideline_evidence zero-row
 * guard below.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Verification;

use OpenEMR\Modules\ClinicalCopilot\Service\Citation\Citation;

final class ResponseVerifier
{
    public const FALLBACK_REPLY = 'I do not have enough information in this chart to answer that confidently.';

    private const CHART_TOOL_SOURCE_TYPE = 'chart_tool';

    /**
     * A citation to this tool is only evidence of a *currently medicated*
     * claim when it actually returned rows -- see AUDIT_Extra.md's
     * Data-Quality Finding 1 and PUNCH_LIST.md 1.4(b). This guard is
     * unconditional: it does not matter what the model says, or how
     * confidently -- zero rows means no "patient is on X" claim survives.
     */
    private const MEDICATION_SOURCE_ID = 'get_medications';

    /**
     * Same reasoning as MEDICATION_SOURCE_ID, for the guideline-evidence
     * worker: a claim quoting guideline text when the retrieval actually
     * returned zero chunks is exactly the fabricated-citation failure mode
     * this guard family exists to catch. Not generalized to every chart
     * tool (get_active_problems/get_recent_encounters/get_extracted_documents)
     * -- that is a broader, deliberately separate change, not done here.
     */
    private const GUIDELINE_SOURCE_ID = 'search_guideline_evidence';

    /**
     * @param array<string, mixed> $submitAnswerInput Raw input of the
     *                                                 model's forced
     *                                                 submit_answer tool
     *                                                 call; untrusted model
     *                                                 output, not yet
     *                                                 validated. An empty
     *                                                 array (no submit_answer
     *                                                 call was made at all)
     *                                                 is a valid input and
     *                                                 rejects like any other
     *                                                 unparseable response.
     * @param list<string> $calledTools Tool names actually invoked this turn.
     * @param array<string, int> $toolRowCounts Row count returned by each
     *                                          called tool, keyed by name.
     */
    public static function verify(array $submitAnswerInput, array $calledTools, array $toolRowCounts): VerificationOutcome
    {
        if (($submitAnswerInput['insufficient_information'] ?? null) === true) {
            $summary = self::stringOrEmpty($submitAnswerInput['summary'] ?? null);

            return VerificationOutcome::passed($summary !== '' ? $summary : self::FALLBACK_REPLY);
        }

        $rawClaims = $submitAnswerInput['claims'] ?? null;
        if (!is_array($rawClaims) || $rawClaims === []) {
            return VerificationOutcome::rejected(self::FALLBACK_REPLY, 'no cited claims for a definitive answer');
        }

        $claims = [];
        foreach ($rawClaims as $rawClaim) {
            $claim = VerificationClaim::fromMixed($rawClaim);
            if ($claim === null) {
                return VerificationOutcome::rejected(self::FALLBACK_REPLY, 'malformed claim in model output');
            }

            $claims[] = $claim;
        }

        foreach ($claims as $claim) {
            $citation = $claim->citation;
            $sourceId = $citation?->sourceId;

            if ($citation === null || $sourceId === null || !in_array($sourceId, $calledTools, true)) {
                return VerificationOutcome::rejected(
                    self::FALLBACK_REPLY,
                    sprintf("claim cites tool '%s' that was not called this turn", $sourceId ?? '(none)'),
                );
            }

            if (!self::hasRequiredCitationFields($citation)) {
                return VerificationOutcome::rejected(
                    self::FALLBACK_REPLY,
                    sprintf("claim cites '%s' with an incomplete citation", $sourceId),
                );
            }

            if ($sourceId === self::MEDICATION_SOURCE_ID && ($toolRowCounts[self::MEDICATION_SOURCE_ID] ?? 0) === 0) {
                return VerificationOutcome::rejected(
                    self::FALLBACK_REPLY,
                    'claim cites get_medications but zero medication rows were returned',
                );
            }

            if ($sourceId === self::GUIDELINE_SOURCE_ID && ($toolRowCounts[self::GUIDELINE_SOURCE_ID] ?? 0) === 0) {
                return VerificationOutcome::rejected(
                    self::FALLBACK_REPLY,
                    'claim cites search_guideline_evidence but zero guideline chunks were returned',
                );
            }
        }

        $summary = self::stringOrEmpty($submitAnswerInput['summary'] ?? null);
        $texts = array_map(static fn (VerificationClaim $claim): string => $claim->text, $claims);
        $reply = trim(($summary !== '' ? $summary . "\n\n" : '') . implode("\n", $texts));

        return VerificationOutcome::passed($reply);
    }

    /**
     * source_type/source_id/quote_or_value/field_or_chunk_id are always
     * required; page_or_section is required for every source_type except
     * chart_tool (a database row has no page) -- see class docblock's
     * "KNOWN, DELIBERATE DIVERGENCE" note.
     */
    private static function hasRequiredCitationFields(Citation $citation): bool
    {
        if (self::isBlank($citation->sourceType) || self::isBlank($citation->sourceId) || self::isBlank($citation->quoteOrValue)) {
            return false;
        }

        if (self::isBlank($citation->fieldOrChunkId)) {
            return false;
        }

        if ($citation->sourceType !== self::CHART_TOOL_SOURCE_TYPE && self::isBlank($citation->pageOrSection)) {
            return false;
        }

        return true;
    }

    private static function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
