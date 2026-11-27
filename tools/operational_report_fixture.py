"""Install exact current report tables in an owned partial-schema fixture.

This is test setup, not a production schema fallback. Full current-schema
equivalence and all constraints are independently tested by the schema suite.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]

def install(sql, *, has_service_schema=False):
    tables = ('payment_followup_intents', 'payment_followup_attempts')
    if not has_service_schema:
        tables = ('wallet_entries', 'service_operations') + tables
    source = (ROOT / 'database/current-schema.sql').read_text()
    for table in tables:
        statement = re.search(r'CREATE TABLE `'+table+r'` \(.*?\n\) ENGINE=InnoDB[^;]*;', source, re.S)
        assert statement, table
        sql(statement.group())

# Synthetic Bot API Message, not just an "ok" flag. Per-attempt ids differ;
# channel aliases have a stable, synthetic numeric chat id.
_ACK_HELPER = r"""function fixtureMessageAck($recipient,?int $messageId=null): object {
    $ctx=$GLOBALS['achbotTelegramDiagnosticContext']??[];
    $id=(string)$recipient;
    if (str_starts_with($id,'@')) {
        $username=strtolower(substr($id,1));
        $chat=(object)['id'=>-1000000000000-(int)crc32($username),'username'=>$username];
    } else $chat=(object)['id'=>(int)$id];
    $mid=$messageId??(100000+1000*(int)($ctx['followup_id']??0)+(int)($ctx['delivery_attempt']??1));
    return (object)['ok'=>true,'result'=>(object)['chat'=>$chat,'message_id'=>$mid]];
}
"""

def with_message_ack(source):
    """Inject only the shared test response builder into a PHP driver."""
    marker = 'function sendMessage('
    assert source.count(marker) == 1
    return source.replace(marker, _ACK_HELPER + marker, 1)
