<?php

/**
 * DB-backed integration tests for CopilotDocumentUploadController --
 * AgentForge2 Core Requirement #1's attach_and_extract, exercised through
 * its actual HTTP-facing security boundary (mirroring
 * CopilotChatControllerTest's approach for the chat endpoint): CSRF, ACL,
 * rate limit, and patient-session checks, plus the end-to-end
 * upload -> extract -> validate -> persist flow with a scripted Anthropic
 * response (no real API call).
 *
 * buildResponse() is private by design, same reasoning as
 * CopilotChatController's; tests reach it via ReflectionMethod with a
 * hand-built Symfony Request carrying a real (test-mode) UploadedFile.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/FakeAnthropicTransporter.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ScriptedAnthropicClientFactory.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ClinicalCopilotFixtureManager.php';

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Controller\CopilotDocumentUploadController;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentExtractionService;
use OpenEMR\Modules\ClinicalCopilot\Service\Extraction\DocumentIngestionPipeline;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ScriptedAnthropicClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class CopilotDocumentUploadControllerTest extends TestCase
{
    private const VALID_LAB_FIELDS = [
        'test_name' => 'HbA1c',
        'value' => '7.2',
        'unit' => '%',
        'reference_range' => '4.0-5.6',
        'collection_date' => '2026-01-15',
        'abnormal_flag' => true,
        'source_citation' => [
            'source_type' => 'lab_pdf',
            'source_id' => 'lab-001',
            'page_or_section' => 'page_1',
            'field_or_chunk_id' => 'hba1c',
            'quote_or_value' => '7.2%',
        ],
    ];

    private ClinicalCopilotFixtureManager $fixtures;
    private SessionInterface $session;

    /** @var list<int> */
    private array $installedPids = [];

    /** @var list<int> */
    private array $extractionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<string> */
    private array $tempFiles = [];

    private mixed $originalPid = null;
    private mixed $originalAuthUser = null;
    private mixed $originalCsrfKey = null;

    protected function setUp(): void
    {
        $this->fixtures = new ClinicalCopilotFixtureManager();
        $this->session = SessionWrapperFactory::getInstance()->getActiveSession();
        $this->originalPid = $this->session->get('pid');
        $this->originalAuthUser = $this->session->get('authUser');
        $this->originalCsrfKey = $this->session->get('csrf_private_key');
    }

    protected function tearDown(): void
    {
        $this->restoreSessionValue('pid', $this->originalPid);
        $this->restoreSessionValue('authUser', $this->originalAuthUser);
        $this->restoreSessionValue('csrf_private_key', $this->originalCsrfKey);
        $this->session->remove('clinical_copilot_rate_limit');

        foreach ($this->documentIds as $id) {
            QueryUtils::sqlStatementThrowException('DELETE FROM `documents` WHERE `id` = ?', [$id]);
        }
        foreach ($this->extractionIds as $id) {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `clinical_copilot_extracted_document` WHERE `id` = ?',
                [$id],
            );
        }
        $this->fixtures->removeFixtures($this->installedPids);

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function staleCsrfTokenIsRejectedBeforeAnythingElse(): void
    {
        CsrfUtils::setupCsrfKey($this->session);

        $request = $this->uploadRequest('not-the-real-token', 'lab_pdf', $this->pdfFile());

        $response = $this->invokeBuildResponse(new CopilotDocumentUploadController(), $request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function userWithoutPatientsAclNeverReachesPatientData(): void
    {
        $pid = $this->fixtures->installPrimaryPatient();
        $this->installedPids[] = $pid;
        $this->session->set('pid', $pid);
        $this->session->set('authUser', 'copilot-test-user-with-no-acl-grant');
        $token = $this->csrfToken();

        $request = $this->uploadRequest($token, 'lab_pdf', $this->pdfFile());

        $response = $this->invokeBuildResponse(new CopilotDocumentUploadController(), $request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function requestOverTheRateLimitIsRejectedBeforeAnyCopilotWork(): void
    {
        $this->installPrimaryAndAuthenticate();
        $token = $this->csrfToken();
        $this->session->set('clinical_copilot_rate_limit', ['windowStart' => time(), 'count' => 15]);

        $request = $this->uploadRequest($token, 'lab_pdf', $this->pdfFile());

        $response = $this->invokeBuildResponse(new CopilotDocumentUploadController(), $request);

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function noPatientInSessionIsRejected(): void
    {
        $this->session->remove('pid');
        $this->session->set('authUser', 'admin');
        $token = $this->csrfToken();

        $request = $this->uploadRequest($token, 'lab_pdf', $this->pdfFile());

        $response = $this->invokeBuildResponse(new CopilotDocumentUploadController(), $request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function invalidDocTypeIsRejected(): void
    {
        $this->installPrimaryAndAuthenticate();
        $token = $this->csrfToken();

        $request = $this->uploadRequest($token, 'not_a_real_doc_type', $this->pdfFile());

        $response = $this->invokeBuildResponse(new CopilotDocumentUploadController(), $request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function missingFileIsRejected(): void
    {
        $this->installPrimaryAndAuthenticate();
        $token = $this->csrfToken();

        $request = Request::create('/upload-ajax.php', 'POST', [
            'csrf_token' => $token,
            'doc_type' => 'lab_pdf',
        ]);

        $response = $this->invokeBuildResponse(new CopilotDocumentUploadController(), $request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function schemaValidUploadSucceedsAndPersistsBothRows(): void
    {
        $pid = $this->installPrimaryAndAuthenticate();
        $token = $this->csrfToken();

        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => self::VALID_LAB_FIELDS,
        ], JSON_THROW_ON_ERROR));
        $pipeline = new DocumentIngestionPipeline(new DocumentExtractionService($factory));
        $controller = new CopilotDocumentUploadController($pipeline);

        $request = $this->uploadRequest($token, 'lab_pdf', $this->pdfFile());

        $response = $this->invokeBuildResponse($controller, $request);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertTrue($data['success']);
        self::assertSame('lab_pdf', $data['docType']);
        self::assertIsInt($data['documentId']);
        self::assertIsArray($data['extractionIds']);
        self::assertCount(1, $data['extractionIds']);
        self::assertIsInt($data['extractionIds'][0]);
        self::assertIsArray($data['results']);
        self::assertCount(1, $data['results']);
        $this->documentIds[] = $data['documentId'];
        $this->extractionIds[] = $data['extractionIds'][0];

        $documentRow = QueryUtils::querySingleRow(
            'SELECT `foreign_id` FROM `documents` WHERE `id` = ?',
            [$data['documentId']],
        );
        self::assertIsArray($documentRow);
        self::assertIsNumeric($documentRow['foreign_id']);
        self::assertSame($pid, (int) $documentRow['foreign_id']);

        $extractionRow = QueryUtils::querySingleRow(
            'SELECT `pid`, `document_id` FROM `clinical_copilot_extracted_document` WHERE `id` = ?',
            [$data['extractionIds'][0]],
        );
        self::assertIsArray($extractionRow);
        self::assertIsNumeric($extractionRow['pid']);
        self::assertSame($pid, (int) $extractionRow['pid']);
        self::assertIsNumeric($extractionRow['document_id']);
        self::assertSame($data['documentId'], (int) $extractionRow['document_id']);
    }

    #[Test]
    public function schemaInvalidUploadReturns422AndPersistsNothing(): void
    {
        $pid = $this->installPrimaryAndAuthenticate();
        $token = $this->csrfToken();

        $factory = (new ScriptedAnthropicClientFactory())->finalText(json_encode([
            'doc_type' => 'lab_pdf',
            'fields' => ['test_name' => 'HbA1c'],
        ], JSON_THROW_ON_ERROR));
        $pipeline = new DocumentIngestionPipeline(new DocumentExtractionService($factory));
        $controller = new CopilotDocumentUploadController($pipeline);

        $request = $this->uploadRequest($token, 'lab_pdf', $this->pdfFile());

        $response = $this->invokeBuildResponse($controller, $request);

        self::assertSame(422, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertFalse($data['success']);
        self::assertSame('schema_invalid', $data['reason']);

        $countRow = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `clinical_copilot_extracted_document` WHERE `pid` = ?',
            [$pid],
        );
        self::assertIsArray($countRow);
        self::assertIsNumeric($countRow['c']);
        self::assertSame(0, (int) $countRow['c']);
    }

    private function uploadRequest(string $token, string $docType, UploadedFile $file): Request
    {
        return Request::create(
            '/upload-ajax.php',
            'POST',
            ['csrf_token' => $token, 'doc_type' => $docType],
            [],
            ['document' => $file],
        );
    }

    /**
     * A minimal structurally-valid PDF -- enough for the server-side mime
     * detection CopilotDocumentUploadController relies on (UploadedFile::
     * getMimeType(), not the client-declared type) to recognize it as
     * application/pdf.
     */
    private function pdfFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'copilot-upload-test-');
        self::assertIsString($path);
        $this->tempFiles[] = $path;

        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 3 3]>>endobj\n"
            . "trailer<</Size 4/Root 1 0 R>>\n%%EOF";
        file_put_contents($path, $pdf);

        return new UploadedFile($path, 'lab.pdf', 'application/pdf', null, true);
    }

    private function installPrimaryAndAuthenticate(): int
    {
        $pid = $this->fixtures->installPrimaryPatient();
        $this->installedPids[] = $pid;
        $this->session->set('pid', $pid);
        $this->session->set('authUser', $this->adminUsername());

        return $pid;
    }

    private function adminUsername(): string
    {
        return getenv('OE_USER', true) ?: 'admin';
    }

    private function csrfToken(): string
    {
        CsrfUtils::setupCsrfKey($this->session);

        return CsrfUtils::collectCsrfToken($this->session);
    }

    private function invokeBuildResponse(CopilotDocumentUploadController $controller, Request $request): JsonResponse
    {
        $method = new ReflectionMethod(CopilotDocumentUploadController::class, 'buildResponse');
        /** @var JsonResponse $response */
        $response = $method->invoke($controller, $request);

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function restoreSessionValue(string $key, mixed $value): void
    {
        if ($value === null) {
            $this->session->remove($key);
        } else {
            $this->session->set($key, $value);
        }
    }
}
