<?php

/**
 * One clinical claim from the model's forced submit_answer tool call, and
 * the tool it says supports it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Verification;

final readonly class VerificationClaim
{
    private function __construct(
        public string $text,
        public ?string $sourceTool,
    ) {
    }

    /**
     * Parses one element of submit_answer's `claims` array. The model's
     * tool-call input is untrusted, schema-shaped JSON, not a guaranteed
     * type -- this is the one place that turns it into a typed value or
     * rejects it outright, so nothing downstream has to re-check shapes.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $text = $raw['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        $sourceTool = $raw['source_tool'] ?? null;
        if ($sourceTool !== null && !is_string($sourceTool)) {
            return null;
        }

        return new self(trim($text), $sourceTool);
    }
}
