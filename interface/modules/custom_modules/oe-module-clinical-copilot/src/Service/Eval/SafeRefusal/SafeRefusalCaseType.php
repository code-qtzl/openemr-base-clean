<?php

/**
 * The two directions a safe_refusal golden-set case can require: refusal
 * because the fixture's source data for the field is missing, low-
 * confidence, or absent; or a confident, cited answer because the fixture's
 * source data is grounded and available. Backed by the exact strings
 * .claude/skills/eval-safe-refusal-validator/SKILL.md's fixtures use, since
 * this value is serialized to/from golden-set JSON.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\SafeRefusal;

enum SafeRefusalCaseType: string
{
    case RefusalRequired = 'refusal_required';
    case ConfidentAnswerRequired = 'confident_answer_required';
}
