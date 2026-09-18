<?php

/**
 * DB-backed tests for SqlConversationStore -- PUNCH_LIST.md Tier 4.1's
 * persistence and TTL-eviction behavior in isolation from the controller's
 * request flow (CopilotChatControllerTest covers the end-to-end wiring).
 *
 * AI-Generated Code Notice: This file contains code generated with
 * assistance from Claude Code (Anthropic). The code has been reviewed
 * and tested by the contributor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/require_module.php';
require_once __DIR__ . '/../../../Fixtures/ClinicalCopilot/ClinicalCopilotFixtureManager.php';

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\Conversation;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\ConversationRole;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\ConversationTurn;
use OpenEMR\Modules\ClinicalCopilot\Service\Conversation\SqlConversationStore;
use OpenEMR\Tests\Fixtures\ClinicalCopilot\ClinicalCopilotFixtureManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class SqlConversationStoreTest extends TestCase
{
    private ClinicalCopilotFixtureManager $fixtures;
    private SqlConversationStore $store;

    /** @var list<int> */
    private array $installedPids = [];

    /** @var list<string> */
    private array $usedSessionUuids = [];

    protected function setUp(): void
    {
        $this->fixtures = new ClinicalCopilotFixtureManager();
        $this->store = new SqlConversationStore();
    }

    protected function tearDown(): void
    {
        foreach ($this->usedSessionUuids as $sessionUuid) {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `clinical_copilot_conversation` WHERE `session_uuid` = ?',
                [$sessionUuid],
            );
        }
        $this->fixtures->removeFixtures($this->installedPids);
    }

    /**
     * Failure mode guarded against: a first-ever question in a conversation
     * must not error just because no row exists yet.
     */
    #[Test]
    public function loadForUnknownSessionAndPatientReturnsEmptyConversation(): void
    {
        $conversation = $this->store->load($this->newSessionUuid(), 999999999);

        self::assertTrue($conversation->isEmpty());
    }

    #[Test]
    public function saveThenLoadRoundTripsTurnsInOrder(): void
    {
        $sessionUuid = $this->newSessionUuid();
        $pid = $this->installPrimaryPatient();

        $conversation = (new Conversation())->append(
            new ConversationTurn(ConversationRole::User, 'What is the A1c trend?'),
            new ConversationTurn(ConversationRole::Assistant, 'It rose from 6.8 to 7.1.'),
        );
        $this->store->save($sessionUuid, $pid, $conversation);

        $loaded = $this->store->load($sessionUuid, $pid);

        self::assertSame($conversation->toMessages(), $loaded->toMessages());
    }

    /**
     * Failure mode guarded against: a duplicate row per session/patient
     * instead of one growing row -- would make load() pick an arbitrary
     * (likely the oldest, least useful) conversation for a pair that saves
     * more than once, which every real multi-turn conversation does.
     */
    #[Test]
    public function savingTwiceUpdatesTheSameRowInsteadOfInserting(): void
    {
        $sessionUuid = $this->newSessionUuid();
        $pid = $this->installPrimaryPatient();

        $this->store->save($sessionUuid, $pid, (new Conversation())->append(
            new ConversationTurn(ConversationRole::User, 'first question'),
            new ConversationTurn(ConversationRole::Assistant, 'first answer'),
        ));
        $this->store->save($sessionUuid, $pid, (new Conversation())->append(
            new ConversationTurn(ConversationRole::User, 'first question'),
            new ConversationTurn(ConversationRole::Assistant, 'first answer'),
        )->append(
            new ConversationTurn(ConversationRole::User, 'second question'),
            new ConversationTurn(ConversationRole::Assistant, 'second answer'),
        ));

        $rowCount = QueryUtils::querySingleRow(
            'SELECT COUNT(*) AS c FROM `clinical_copilot_conversation` WHERE `session_uuid` = ? AND `pid` = ?',
            [$sessionUuid, $pid],
        );
        self::assertIsArray($rowCount);
        self::assertIsNumeric($rowCount['c']);
        self::assertSame(1, (int) $rowCount['c']);

        $loaded = $this->store->load($sessionUuid, $pid);
        self::assertCount(4, $loaded->toMessages());
    }

    /**
     * Failure mode guarded against: two patients seen in the same browser
     * session (same session_database_uuid, e.g. Dr. Ruiz closing one chart
     * and opening the next) must never share a conversation -- the
     * (session_uuid, pid) composite key is what PUNCH_LIST.md Tier 4.1's
     * "new patient must not see old conversation" acceptance criterion
     * ultimately rests on.
     */
    #[Test]
    public function differentPatientsInTheSameSessionHaveIndependentConversations(): void
    {
        $sessionUuid = $this->newSessionUuid();
        $primaryPid = $this->installPrimaryPatient();
        $secondaryPid = $this->fixtures->installSecondaryPatient();
        $this->installedPids[] = $secondaryPid;

        $this->store->save($sessionUuid, $primaryPid, (new Conversation())->append(
            new ConversationTurn(ConversationRole::User, 'primary patient question'),
            new ConversationTurn(ConversationRole::Assistant, 'primary patient answer'),
        ));

        $secondaryConversation = $this->store->load($sessionUuid, $secondaryPid);

        self::assertTrue($secondaryConversation->isEmpty());
    }

    /**
     * Failure mode guarded against: a mid-visit session timeout resurrecting
     * a stale conversation for whichever patient is opened next in that same
     * session -- PUNCH_LIST.md Tier 4.1's explicit TTL requirement. Backdates
     * `last_updated` past the app's own session `timeout` global (the same
     * value OpenEMR\Common\Session\SessionTracker measures its own session
     * expiry against) rather than sleeping in the test.
     */
    #[Test]
    public function conversationOlderThanSessionTimeoutIsNotReturnedAndIsEvicted(): void
    {
        $sessionUuid = $this->newSessionUuid();
        $pid = $this->installPrimaryPatient();

        $this->store->save($sessionUuid, $pid, (new Conversation())->append(
            new ConversationTurn(ConversationRole::User, 'stale question'),
            new ConversationTurn(ConversationRole::Assistant, 'stale answer'),
        ));

        $timeoutSeconds = OEGlobalsBag::getInstance()->getInt('timeout');
        QueryUtils::sqlStatementThrowException(
            'UPDATE `clinical_copilot_conversation`
                SET `last_updated` = NOW() - INTERVAL ? SECOND
              WHERE `session_uuid` = ? AND `pid` = ?',
            [$timeoutSeconds + 60, $sessionUuid, $pid],
        );

        $loaded = $this->store->load($sessionUuid, $pid);
        self::assertTrue($loaded->isEmpty());

        $row = QueryUtils::querySingleRow(
            'SELECT `id` FROM `clinical_copilot_conversation` WHERE `session_uuid` = ? AND `pid` = ?',
            [$sessionUuid, $pid],
        );
        self::assertFalse($row, 'an expired conversation row should be evicted on read');
    }

    private function newSessionUuid(): string
    {
        $uuid = Uuid::uuid4()->toString();
        $this->usedSessionUuids[] = $uuid;

        return $uuid;
    }

    private function installPrimaryPatient(): int
    {
        $pid = $this->fixtures->installPrimaryPatient();
        $this->installedPids[] = $pid;

        return $pid;
    }
}
