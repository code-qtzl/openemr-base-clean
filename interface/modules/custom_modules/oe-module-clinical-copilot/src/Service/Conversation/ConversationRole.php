<?php

/**
 * Speaker role for one turn of a persisted Clinical Co-Pilot conversation.
 *
 * Backed (not a pure unit enum) because the value round-trips through the
 * `clinical_copilot_conversation.turns_json` column and the Anthropic
 * Messages API's own `role` field, both of which are the string values
 * below, not enum names.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Service\Conversation;

enum ConversationRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
