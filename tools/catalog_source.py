"""Expose current catalog template prose to existing source-level UI guards.

Only presentation calls are expanded, using the current native PHP catalog.
This is a static inspection view; it is never executed as application source.
"""
from __future__ import annotations
import json,re,subprocess
from pathlib import Path
def templates(root:Path)->dict[str,str]:
    raw=subprocess.check_output(['php','-r',
      'require "messages/loader.php";$r=[];foreach(glob("messages/fa/*.php") as $p){$t=basename($p,".php");foreach(achbotMessages($t) as $k=>$v)$r[$t.".".$k]=is_array($v)?$v["text"]:$v;}echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);'],
      cwd=root)
    return json.loads(raw)
def expanded(source:str,catalog:dict[str,str])->str:
    pattern=re.compile(r"(?<![A-Za-z0-9_])\\?achbotMessage(?:Html)?\(\s*'([^']+)'")
    changes=[]
    for match in pattern.finditer(source):
        key=match[1]
        if key not in catalog:raise RuntimeError('Static privacy guard found unknown message key: '+key)
        depth=1;quote=None;escaped=False;i=match.end()
        while i<len(source) and depth:
            char=source[i]
            if quote:
                if escaped:escaped=False
                elif char=='\\':escaped=True
                elif char==quote:quote=None
            elif char in "'\"":quote=char
            elif char=='(':depth+=1
            elif char==')':depth-=1
            i+=1
        if depth:raise RuntimeError('Static privacy guard found incomplete catalog expression')
        # A source-level string with exact template prose keeps the old guard
        # sensitive to catalog edits without executing any caller variables.
        literal="'"+catalog[key].replace('\\','\\\\').replace("'","\\'")+"'"
        changes.append((match.start(),i,literal))
    for start,end,literal in reversed(changes):source=source[:start]+literal+source[end:]
    return source
