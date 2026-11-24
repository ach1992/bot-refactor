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
