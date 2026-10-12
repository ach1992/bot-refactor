#!/usr/bin/env python3
"""Run the unchanged native-cURL fixtures under the V3 source envelope."""
from __future__ import annotations
import argparse,json,threading
from pathlib import Path
from http.server import ThreadingHTTPServer
import test_native_telegram as native
import v3_contract as c

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--run-loopback',action='store_true',required=True);ap.add_argument('--report',type=Path,required=True);args=ap.parse_args()
    cases=[];count=0
    with c.previous() as (old,lock):
        c.verify(c.ROOT,old,lock)
        with ThreadingHTTPServer(('127.0.0.1',0),native.Fixture) as server:
            server.daemon_threads=True;server.requests=[];server.variant='ok'
            thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
            base='http://127.0.0.1:'+str(server.server_port)
            try:
                for name in ['bot','sendFileToTelegram']:
                    for variant in native.VARIANTS:
                        pair=[]
                        for root,mode in [(old,'old'),(c.ROOT,'new')]:
                            server.requests=[];server.variant=variant
                            record=native.execute(root,mode,name,variant,base)
                            if len(server.requests)!=1:raise RuntimeError('Unexpected native request count')
                            pair.append({'return':record['return'],'requests':list(server.requests)});count+=1
                        if pair[0]!=pair[1]:raise RuntimeError('Native cURL parity failed: '+name+':'+variant)
                        cases.append(name+':'+variant)
            finally:server.shutdown();thread.join(timeout=5)
    report={'comparison_pairs':len(cases),'requests':count,'cases':cases,'php':record['php'],'curl':record['curl'],
            'previous_commit':c.V2,'native_curl':True,'listener':'owned ephemeral 127.0.0.1','real_telegram_used':False,
            'tls_or_production_dns_tested':False,'production_acceptance':False}
    report.update(c.identity());args.report.write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report,sort_keys=True))
if __name__=='__main__':main()
