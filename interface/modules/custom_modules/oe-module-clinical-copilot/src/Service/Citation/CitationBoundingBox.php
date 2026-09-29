<?php

/**
 * A citation's bounding box on one page of its source PDF: a 0-based page
 * index plus a box normalized to 0.0-1.0 against page width/height
 * (resolution-independent, so a pdf.js-rendered canvas at any zoom level can
 * scale it directly onto pixel coordinates).
 *
 * Model-authored (Claude estimates it visually from the PDF page it reads
 * during extraction) -- see Citation's own docblock for why `documentId` is
 * handled differently (backend-stamped, never model-invented).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Citation;

final readonly class CitationBoundingBox
{
    private function __construct(
        public int $page,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
    ) {
    }

    /**
     * Parses an untrusted `bbox` payload. Any structural problem --
     * non-numeric coordinates, a negative page, an inverted or out-of-range
     * box -- parses to `null` rather than throwing, matching Citation's
     * tolerant-parse convention for its other fields.
     */
    public static function fromMixed(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $page = $raw['page'] ?? null;
        $x0 = self::floatOrNull($raw['x0'] ?? null);
        $y0 = self::floatOrNull($raw['y0'] ?? null);
        $x1 = self::floatOrNull($raw['x1'] ?? null);
        $y1 = self::floatOrNull($raw['y1'] ?? null);

        if (!is_int($page) || $page < 0) {
            return null;
        }

        if ($x0 === null || $y0 === null || $x1 === null || $y1 === null) {
            return null;
        }

        if ($x0 < 0.0 || $y0 < 0.0 || $x1 > 1.0 || $y1 > 1.0 || $x0 >= $x1 || $y0 >= $y1) {
            return null;
        }

        return new self($page, $x0, $y0, $x1, $y1);
    }

    /**
     * @return array{page: int, x0: float, y0: float, x1: float, y1: float}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'x0' => $this->x0,
            'y0' => $this->y0,
            'x1' => $this->x1,
            'y1' => $this->y1,
        ];
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
