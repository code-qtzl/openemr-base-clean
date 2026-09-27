<?php

/**
 * Production VoyageClientFactory: builds a real VoyageClient over a
 * plain Guzzle client.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Evidence;

use GuzzleHttp\Client as GuzzleClient;

final class DefaultVoyageClientFactory implements VoyageClientFactory
{
    /** Seconds to wait for a TCP connection to Voyage before giving up. */
    private const CONNECT_TIMEOUT_SECONDS = 10.0;

    /**
     * Seconds to wait for one HTTP round trip to Voyage before giving up.
     * Both embed() and rerank() calls here are single, small, non-streaming
     * requests (a handful of short text chunks, never a whole document), so
     * this is well above what a normal call needs and only bounds a
     * genuinely hung connection.
     */
    private const REQUEST_TIMEOUT_SECONDS = 15.0;

    public function create(string $apiKey, string $correlationId): VoyageClient
    {
        $http = new GuzzleClient([
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::REQUEST_TIMEOUT_SECONDS,
        ]);

        return new VoyageClient($http, $apiKey, $correlationId);
    }
}
