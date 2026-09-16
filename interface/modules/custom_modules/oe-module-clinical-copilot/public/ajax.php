<?php

/**
 * Clinical Co-Pilot chat endpoint.
 *
 * Thin entry point: all handling, including CSRF, ACL and patient scoping,
 * lives in CopilotChatController.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Modules\ClinicalCopilot\Controller\CopilotChatController;

(new CopilotChatController())->handleRequest();
