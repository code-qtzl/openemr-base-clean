<?php

/**
 * A file to send to Claude for extraction: its bytes, filename, and mime
 * type, restricted to the mime types this pipeline can turn into a valid
 * Anthropic content block. `application/pdf` maps to a native `document`
 * block (no PDF-to-image conversion needed -- the vendored SDK supports it
 * directly); the raster types map to `image` blocks.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Extraction;

use InvalidArgumentException;

final readonly class DocumentPayload
{
    /**
     * Anthropic content-block `type` per allowed mime type. Narrower than
     * OpenEMR's admin-configurable `files_white_list` (library/sanitize.inc.php)
     * -- this allowlist exists to guarantee a valid content-block shape for
     * the extraction call, not to replace that broader upload policy.
     *
     * @var array<string, string>
     */
    private const CONTENT_BLOCK_TYPE = [
        'application/pdf' => 'document',
        'image/png' => 'image',
        'image/jpeg' => 'image',
    ];

    private function __construct(
        public string $filename,
        public string $mimeType,
        public string $bytes,
    ) {
    }

    public static function fromBytes(string $filename, string $mimeType, string $bytes): self
    {
        if (!array_key_exists($mimeType, self::CONTENT_BLOCK_TYPE)) {
            throw new InvalidArgumentException(sprintf('Unsupported document mime type: %s', $mimeType));
        }

        if ($bytes === '') {
            throw new InvalidArgumentException('Document bytes must not be empty.');
        }

        return new self($filename, $mimeType, $bytes);
    }

    /**
     * @return array{type: string, source: array{type: string, media_type: string, data: string}}
     */
    public function toContentBlock(): array
    {
        return [
            'type' => self::CONTENT_BLOCK_TYPE[$this->mimeType],
            'source' => [
                'type' => 'base64',
                'media_type' => $this->mimeType,
                'data' => base64_encode($this->bytes),
            ],
        ];
    }
}
