<?php

/**
 * One-time (re-runnable) seed script for EvidenceRetrieverWorker's guideline
 * corpus. Reads the checked-in static snapshot at
 * bin/data/guideline-corpus-source.json (openFDA structured product label
 * sections for a small, USERS.md UC3-relevant drug set -- see that file's
 * own $comment for licensing/sourcing notes), chunks each section, embeds
 * every chunk via Voyage, and writes the result into
 * clinical_copilot_guideline_chunk.
 *
 * Deliberately a manual, offline script rather than a live per-request
 * fetch: it keeps the exact republished label text auditable and reviewed
 * once, keeps retrieval latency independent of openFDA's own availability,
 * and keeps corpus updates a deliberate, reviewable act (re-run this script)
 * rather than a silent drift whenever openFDA's data changes underneath the
 * running application.
 *
 * Chunking strategy: one chunk per (source_id, section) -- the label's own
 * section boundary is the primary chunk unit, since it is already a
 * coherent semantic unit and the corpus is small enough that finer-grained
 * chunking would add complexity without a real retrieval-quality benefit.
 * A section whose text exceeds MAX_CHUNK_CHARS falls back to fixed-size
 * chunks with a small overlap, purely as an overflow rule for an unusually
 * long section -- none of the sections in the current snapshot are long
 * enough to trigger it, but the corpus is expected to grow.
 *
 * Idempotent: existing rows for a source_id are deleted and replaced on
 * each run, so re-seeding after editing the source JSON or changing the
 * chunking logic always leaves the table consistent with the script's
 * current behavior, never a stale mix of old and new chunks for the same
 * source.
 *
 * Requires OPENEMR__VOYAGE_API_KEY to be configured -- see
 * DefaultGuidelineEvidenceRetriever::apiKey() for the same env-var
 * convention CopilotService::apiKey() uses for Anthropic.
 *
 * Run as the web user, not root (see OpenEMR\Common\Command\RootCliGuard):
 *   php interface/modules/custom_modules/oe-module-clinical-copilot/bin/seed-guideline-corpus.php
 *
 * $_GET['site'] below is required, not optional: interface/globals.php only
 * reads it when non-empty (interface/globals.php:273-274) and otherwise
 * falls through to $_SERVER['HTTP_HOST'] (undefined outside an HTTP
 * request) -- confirmed empirically that the resulting empty site id is
 * NOT silently defaulted to 'default' (is_dir() against the bare sites/
 * directory returns true, so the empty string is used as-is and the
 * request is rejected as an invalid site id). This is the same
 * CLI-bootstrap-into-globals.php bridge
 * interface/modules/custom_modules/oe-module-faxsms/library/
 * run_notifications.php uses for the identical reason; this script's
 * PHPStan exceptions are added to the same baseline files as that
 * precedent, for the same reason -- see this file's own PHPStan baseline
 * entries.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

/**
 * Chunking logic, extracted into a class rather than a global-namespace
 * function (this codebase forbids the latter outside tests/) even though
 * this file is never autoloaded -- it is `require`d once, top to bottom.
 */
final class GuidelineCorpusSeederChunker
{
    /** Section text longer than this falls back to fixed-size chunking. */
    private const MAX_CHUNK_CHARS = 1500;
    private const CHUNK_SIZE_CHARS = 800;
    private const CHUNK_OVERLAP_CHARS = 100;

    /** @return list<string> One or more chunks for this section's text, in order. */
    public static function chunk(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHUNK_CHARS) {
            return [$text];
        }

        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;
        while ($start < $length) {
            $chunks[] = mb_substr($text, $start, self::CHUNK_SIZE_CHARS);
            $start += self::CHUNK_SIZE_CHARS - self::CHUNK_OVERLAP_CHARS;
        }

        return $chunks;
    }
}

chdir(__DIR__ . '/../../../../..');
$_GET['site'] = 'default';
$ignoreAuth = true;
require_once 'interface/globals.php';

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEEnvBag;
use OpenEMR\Modules\ClinicalCopilot\Service\Evidence\DefaultVoyageClientFactory;

/** Same model VoyageClient::EMBED_MODEL uses -- kept in sync manually since it's a private implementation detail there. */
const SEED_EMBED_MODEL = 'voyage-3-large';

$apiKey = OEEnvBag::getInstance()->getString('OPENEMR__VOYAGE_API_KEY');
if ($apiKey === '') {
    fwrite(STDERR, "OPENEMR__VOYAGE_API_KEY is not configured -- cannot seed the guideline corpus.\n");
    exit(1);
}

$sourcePath = __DIR__ . '/data/guideline-corpus-source.json';
$decoded = json_decode((string) file_get_contents($sourcePath), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($decoded) || !is_array($decoded['documents'] ?? null)) {
    fwrite(STDERR, "guideline-corpus-source.json is missing a 'documents' array.\n");
    exit(1);
}

$voyage = (new DefaultVoyageClientFactory())->create($apiKey, 'seed-guideline-corpus');

$totalSources = 0;
$totalChunks = 0;

foreach ($decoded['documents'] as $document) {
    if (!is_array($document)) {
        continue;
    }

    $sourceId = $document['source_id'] ?? null;
    $sourceLabel = $document['source_label'] ?? null;
    $sections = $document['sections'] ?? null;
    if (!is_string($sourceId) || !is_string($sourceLabel) || !is_array($sections)) {
        fwrite(STDERR, "Skipping a malformed document entry (missing source_id/source_label/sections).\n");
        continue;
    }

    QueryUtils::sqlStatementThrowException(
        'DELETE FROM `clinical_copilot_guideline_chunk` WHERE `source_id` = ?',
        [$sourceId],
    );

    $chunkIndex = 0;
    foreach ($sections as $section => $text) {
        if (!is_string($section) || !is_string($text) || trim($text) === '') {
            continue;
        }

        foreach (GuidelineCorpusSeederChunker::chunk($text) as $chunkText) {
            $embedding = $voyage->embed([$chunkText], inputType: 'document')[0] ?? [];

            QueryUtils::sqlInsert(
                'INSERT INTO `clinical_copilot_guideline_chunk`
                    (`source_id`, `source_label`, `section`, `chunk_index`, `chunk_text`, `embedding_json`, `embedding_model`, `created_at`, `last_updated`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $sourceId,
                    $sourceLabel,
                    $section,
                    $chunkIndex,
                    $chunkText,
                    json_encode($embedding, JSON_THROW_ON_ERROR),
                    SEED_EMBED_MODEL,
                ],
            );

            ++$chunkIndex;
            ++$totalChunks;
        }
    }

    ++$totalSources;
    echo "Seeded {$sourceId} ({$chunkIndex} chunks).\n";
}

echo "\nGuideline corpus seeded: {$totalSources} source(s), {$totalChunks} chunk(s) total.\n";
