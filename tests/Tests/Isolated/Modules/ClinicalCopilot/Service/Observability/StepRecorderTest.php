<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Observability;

use OpenEMR\Modules\ClinicalCopilot\Service\Observability\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\Service\Observability\TelemetryStep;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/TelemetryStep.php';
require_once __DIR__ . '/../../../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Observability/StepRecorder.php';

class StepRecorderTest extends TestCase
{
    public function testMeasureReturnsTheResultAndRecordsATimedStepWithDescribedAttributes(): void
    {
        $recorder = new StepRecorder();

        $result = $recorder->measure(
            'voyage.rerank',
            static function (): array {
                usleep(2000);

                return [1, 2, 3];
            },
            static fn (array $items): array => ['returned' => count($items)],
        );

        self::assertSame([1, 2, 3], $result);
        $steps = $recorder->steps();
        self::assertCount(1, $steps);
        self::assertInstanceOf(TelemetryStep::class, $steps[0]);
        self::assertSame('voyage.rerank', $steps[0]->name);
        self::assertSame(['returned' => 3], $steps[0]->attributes);
        self::assertGreaterThan($steps[0]->startedAt, $steps[0]->endedAt);
    }

    public function testMeasureWithoutADescriberRecordsNoAttributes(): void
    {
        $recorder = new StepRecorder();
        $recorder->measure('x', static fn (): int => 1);

        self::assertSame([], $recorder->steps()[0]->attributes);
    }

    /**
     * An exception message can carry SQL or API detail, so only the class is
     * recorded; the exception itself still propagates to the caller.
     */
    public function testAThrowingStepIsRecordedAsFailedWithoutItsMessageAndRethrown(): void
    {
        $recorder = new StepRecorder();

        try {
            $recorder->measure('voyage.embed', static function (): never {
                throw new RuntimeException('secret SELECT * FROM patient_data');
            });
            self::fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame('secret SELECT * FROM patient_data', $e->getMessage());
        }

        $step = $recorder->steps()[0];
        self::assertSame(['failed' => true, 'exception' => RuntimeException::class], $step->attributes);
        self::assertStringNotContainsString('patient_data', json_encode($step->attributes, JSON_THROW_ON_ERROR));
    }

    public function testStepsAreKeptInExecutionOrder(): void
    {
        $recorder = new StepRecorder();
        $recorder->measure('a', static fn (): int => 1);
        $recorder->measure('b', static fn (): int => 2);

        self::assertSame(['a', 'b'], array_map(static fn (TelemetryStep $s): string => $s->name, $recorder->steps()));
    }
}
