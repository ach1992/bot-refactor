"""Source extraction for isolated PHP fixtures, without bot bootstrap."""
import re

def function_bodies(data:bytes,names:list[str]):
    out=[]
    for name in names:
        found=list(re.finditer(rb'(?m)^function\s+'+name.encode()+rb'\s*\([^\n]*\)\s*\{',data))
        if len(found)!=1:raise RuntimeError('Cannot locate original/facade '+name)
        end=re.search(rb'(?m)^}\s*(?:\n|\Z)',data[found[0].end():])
        if not end:raise RuntimeError('Missing function end '+name)
        out.append(data[found[0].start():found[0].end()+end.end()])
    return b'<?php\n'+b'\n'.join(out)
