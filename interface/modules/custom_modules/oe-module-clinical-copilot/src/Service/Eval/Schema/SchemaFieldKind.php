<?php

/**
 * How a required schema field's presence is judged -- purely internal
 * dispatch for SchemaValidator, never serialized, so this is a unit enum
 * rather than a backed one. See
 * .claude/skills/eval-schema-validator/SKILL.md's "Field Shape Rules"
 * section: getting Scalar vs ListField wrong is the most likely way to
 * misimplement this eval (an empty `allergies` list is valid content, not
 * a missing field).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema;

enum SchemaFieldKind
{
    /**
     * Present and, if a string, non-empty after trimming. A non-string
     * scalar (a number, a bool) only needs to be non-null.
     */
    case Scalar;

    /**
     * Present and non-null, but an empty string is valid -- for a field where
     * blank is itself the honest value. A lab report prints no flag for a
     * normal result, so a blank `abnormal_flag` means "not flagged", not
     * "missing"; failing it would reject most real panels.
     */
    case PresentScalar;

    /**
     * Present and an array. Empty is valid -- e.g. "no known allergies".
     */
    case ListField;

    /**
     * Present and a non-empty array/object.
     */
    case ObjectField;
}
