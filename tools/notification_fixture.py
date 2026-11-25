"""Load the actual current notification router in isolated domain PHP drivers.

Transport spies stay test-owned. No recipient, authority or routing behavior is
replaced here; the current function is extracted without booting config.php.
"""
from pathlib import Path
import re,subprocess

ROOT=Path(__file__).resolve().parents[1]

def router_definition() -> str:
    return subprocess.check_output([
        'php','-r',
        "require $argv[1]; echo achbotExtractFunction($argv[2], 'achbotNotifyAdmins');",
        str(ROOT/'tools/source_scan.php'),str(ROOT/'config.php')
    ],cwd=ROOT,text=True)

def with_router(source: str) -> str:
    if not source.startswith('<?php'):
        raise ValueError('notification fixture must be a PHP driver')
    definition=router_definition()
    source=source.replace('<?php',"<?php\n"+definition+"\n",1)
    # Direct domain drivers intentionally do not execute config.php. Import
    # the same current authority dependency using the driver's actual root.
    if '/src/Admin/Access.php' not in source:
        source,count=re.subn(r'(\$root\s*=\s*\$argv\[1\]\s*;)',
            r"\1\nrequire_once $root.'/src/Admin/Access.php';",source,count=1)
        if count!=1:raise ValueError('missing domain driver root binding')
    return source

def stage_router(stage: Path) -> None:
    (stage/'notifications-under-test.php').write_text(
        '<?php\nrequire_once __DIR__."/src/Admin/Access.php";\n'+router_definition())

def sanitized_exception_line(line: str) -> bool:
    """Recognize only bounded router metadata, never arbitrary error text."""
    return re.fullmatch(
        r'(?:\[[^\]\r\n]{1,40}\] )?achbot_admin_notice_exception '
        r'event=(?:general|manual_review) method=(?:sendMessage|sendPhoto) '
        r'target=(?:owner|staff|channel) class=[A-Za-z_\\][A-Za-z0-9_\\]{0,119}'
        r'(?: update_id=[1-9][0-9]{0,18})?',line) is not None
