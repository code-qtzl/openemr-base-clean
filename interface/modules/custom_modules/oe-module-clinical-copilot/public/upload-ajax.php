<?php

/**
 * Clinical Co-Pilot document-upload endpoint.
 *
 * Thin entry point: all handling, including CSRF, ACL and patient scoping,
 * lives in CopilotDocumentUploadController.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Modules\ClinicalCopilot\Controller\CopilotDocumentUploadController;

(new CopilotDocumentUploadController())->handleRequest();
