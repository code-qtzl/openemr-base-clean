<?php

/**
 * Regression coverage for CopilotPanelController's document-viewer retrieve
 * URL. Controller::act()'s legacy routing (library/classes/Controller.class.php)
 * passes query params to C_Document::retrieve_action() *positionally*, in
 * the query string's own order, not matched by name -- so the rendered base
 * URL must carry only `document&retrieve` and never bake in `as_file`/
 * `original_file` ahead of the `patient_id`/`document_id` that
 * document-viewer.js appends at click time. See CopilotPanelController's
 * class docblock for the live-verified failure mode this guards against.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Controller\CopilotPanelController;
use PHPUnit\Framework\TestCase;

final class CopilotPanelControllerTest extends TestCase
{
    private mixed $originalCsrfKey = null;

    protected function setUp(): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $this->originalCsrfKey = $session->get('csrf_private_key');
        CsrfUtils::setupCsrfKey($session);
    }

    public function testRetrieveUrlCarriesOnlyDocumentAndRetrieveNoTrailingFlags(): void
    {
        $installPath = OEGlobalsBag::getInstance()->getWebRoot()
            . '/interface/modules/custom_modules/oe-module-clinical-copilot';

        $html = (new CopilotPanelController($installPath))->render();

        if (str_contains($html, 'copilot-sidebar-unconfigured')) {
            self::markTestSkipped('CopilotService is not configured in this environment (no API key).');
        }

        self::assertMatchesRegularExpression(
            '/data-retrieve-url="[^"]*\/controller\.php\?document&amp;retrieve"/',
            $html,
            'The retrieve URL must end at "document&retrieve" -- appending as_file/original_file '
                . 'here would shift ahead of the patient_id/document_id that document-viewer.js '
                . 'appends, silently misassigning every positional argument to retrieve_action().'
        );

        self::assertStringNotContainsString('as_file', $html);
        self::assertStringNotContainsString('original_file', $html);
    }

    protected function tearDown(): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $session->remove('pid');
        if ($this->originalCsrfKey === null) {
            $session->remove('csrf_private_key');
        } else {
            $session->set('csrf_private_key', $this->originalCsrfKey);
        }
    }
}
