<?php

/**
 * Test double for VoyageClientFactory: scripts embed()/rerank() responses
 * instead of calling the real Voyage API. Same role
 * ScriptedAnthropicClientFactory plays for the LLM call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Fixtures\ClinicalCopilot;

require_once __DIR__ . '/../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Evidence/VoyageClientFactory.php';
require_once __DIR__ . '/../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Evidence/VoyageClient.php';

use OpenEMR\Modules\ClinicalCopilot\Service\Evidence\VoyageClient;
use OpenEMR\Modules\ClinicalCopilot\Service\Evidence\VoyageClientFactory;

final class ScriptedVoyageClientFactory implements VoyageClientFactory
{
    private ?FakeVoyageTransporter $lastTransporter = null;

    /**
     * @param list<array<string, mixed>> $embedResponses
     * @param list<array<string, mixed>> $rerankResponses
     */
    public function __construct(
        private readonly array $embedResponses,
        private readonly array $rerankResponses,
    ) {
    }

    public function create(string $apiKey, string $correlationId): VoyageClient
    {
        $this->lastTransporter = new FakeVoyageTransporter($this->embedResponses, $this->rerankResponses);

        // No-op sleep: none of these fakes ever return 429, but this keeps
        // it that way even if a future test scripts one, rather than a real
        // test run silently blocking for real retry-delay seconds.
        return new VoyageClient($this->lastTransporter, $apiKey, $correlationId, static function (int $seconds): void {
        });
    }

    public function lastTransporter(): ?FakeVoyageTransporter
    {
        return $this->lastTransporter;
    }
}
