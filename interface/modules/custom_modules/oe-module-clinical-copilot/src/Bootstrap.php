<?php

/**
 * Clinical Co-Pilot module bootstrap class.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\PatientDemographics\RenderEvent;
use OpenEMR\Modules\ClinicalCopilot\Controller\CopilotPanelController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Bootstrap
{
    public const MODULE_NAME = 'oe-module-clinical-copilot';
    private const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-clinical-copilot';

    private readonly string $installPath;

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
        $this->installPath = OEGlobalsBag::getInstance()->getWebRoot() . self::MODULE_INSTALLATION_PATH;
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(
            RenderEvent::EVENT_SECTION_LIST_RENDER_BEFORE,
            $this->renderChartPanel(...)
        );
    }

    /**
     * Render the co-pilot panel above the patient summary cards.
     *
     * The panel itself holds no PHI -- it is an empty shell that talks to
     * public/ajax.php. Keeping the LLM round-trip off the page render is what
     * stops a slow model call from blocking the chart from loading.
     */
    public function renderChartPanel(RenderEvent $event): void
    {
        echo (new CopilotPanelController($this->installPath))->render();
    }
}
