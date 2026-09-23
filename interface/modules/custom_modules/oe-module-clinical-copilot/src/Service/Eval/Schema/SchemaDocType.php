<?php

/**
 * The two document types AgentForge2 Core Requirement #1 requires
 * `attach_and_extract` to support. Backed by the exact strings already
 * used elsewhere in this module (citation `source_type`, Railway's
 * clinical_copilot_conversation seeding comments) and by the golden-set
 * JSON this value round-trips through.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Eval\Schema;

enum SchemaDocType: string
{
    case LabPdf = 'lab_pdf';
    case IntakeForm = 'intake_form';
}
