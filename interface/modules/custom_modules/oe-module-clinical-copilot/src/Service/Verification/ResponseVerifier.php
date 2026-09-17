<?php

/**
 * Minimum-viable source-attribution verification for a co-pilot answer.
 *
 * ARCHITECTURE.md describes forcing structured output that maps every
 * clinical claim to the tool call that supports it; nothing enforced that
 * before this class. CopilotService forces the model's final turn to call a
 * `submit_answer` tool (see its input schema) instead of returning free
 * text, so every answer arrives here as structured claims rather than prose
 * this class would have to parse.
 *
 * Verification is deliberately strict: a claim with no citation, a citation
 * to a tool that was not actually called this turn, or a citation to
 * get_medications when that call returned zero rows (PUNCH_LIST.md 1.4(b))
 * all reject the whole answer in favor of a safe fallback. Nothing partial
 * ever reaches the browser -- either every claim is grounded, or the
 * clinician sees an honest "not enough information" instead.
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
 * which is the tradeoff PUNCH_LIST.md 1.3 calls for at this MVP stage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Verification;

final class ResponseVerifier
{
    public const FALLBACK_REPLY = 'I do not have enough information in this chart to answer that confidently.';

    /**
     * A citation to this tool is only evidence of a *currently medicated*
     * claim when it actually returned rows -- see AUDIT_Extra.md's
     * Data-Quality Finding 1 and PUNCH_LIST.md 1.4(b). This guard is
     * unconditional: it does not matter what the model says, or how
     * confidently -- zero rows means no "patient is on X" claim survives.
     */
    private const MEDICATION_TOOL = 'get_medications';

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
            if ($claim->sourceTool === null || !in_array($claim->sourceTool, $calledTools, true)) {
                return VerificationOutcome::rejected(
                    self::FALLBACK_REPLY,
                    sprintf("claim cites tool '%s' that was not called this turn", $claim->sourceTool ?? '(none)'),
                );
            }

            if ($claim->sourceTool === self::MEDICATION_TOOL && ($toolRowCounts[self::MEDICATION_TOOL] ?? 0) === 0) {
                return VerificationOutcome::rejected(
                    self::FALLBACK_REPLY,
                    'claim cites get_medications but zero medication rows were returned',
                );
            }
        }

        $summary = self::stringOrEmpty($submitAnswerInput['summary'] ?? null);
        $texts = array_map(static fn (VerificationClaim $claim): string => $claim->text, $claims);
        $reply = trim(($summary !== '' ? $summary . "\n\n" : '') . implode("\n", $texts));

        return VerificationOutcome::passed($reply);
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
