#!/usr/bin/env python3
"""Collect positive native output contracts against an ephemeral loopback fixture only."""
import datetime
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import json
import os
from pathlib import Path
import secrets
import signal
import socket
import subprocess
import tempfile
import threading
import time
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('web_tools',ROOT/'scripts/web-security-tools.py')
web=importlib.util.module_from_spec(spec);spec.loader.exec_module(web)
OUT=ROOT/'tests/fixtures/security-outputs'


class Fixture(BaseHTTPRequestHandler):
    """Serve controlled metadata and a single static HTML route with passive findings."""
    def log_message(self,*args):
        pass

    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length','0')))
        self.do_GET()

    def do_GET(self):
        base='http://127.0.0.1:'+str(self.server.server_port)
        advisory={'advisoryId':'PKSA-fixture-test-0001','packageName':'ninfa/fixture','affectedVersions':'<1.0.1','title':'Controlled fixture advisory','cve':None,'link':'https://example.invalid/advisory','reportedAt':'2026-10-23T00:00:00+00:00','sources':[{'name':'fixture','remoteId':'fixture-1'}],'severity':'high'}
        if self.path.startswith('/packages.json'):
            data={'packages':{},'available-packages':['ninfa/fixture'],'metadata-url':base+'/p2/%package%.json','security-advisories':{'metadata':False,'api-url':base+'/advisories'}}
        elif self.path.startswith('/advisories'):
            data={'advisories':{'ninfa/fixture':[advisory]}}
        elif self.path.startswith('/p2/'):
            data={'packages':{'ninfa/fixture':[{'name':'ninfa/fixture','version':'1.0.0','version_normalized':'1.0.0.0','abandoned':True}]}}
        else:
            body=b'<!doctype html><html><head><title>Ninfa fixture</title></head><body>NINFA_CONTRACT_MARKER</body></html>'
            self.send_response(200);self.send_header('Content-Type','text/html');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body);return
        body=json.dumps(data).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)


def capture(name,args,cwd,version,config,temp,port,env=None):
    """Record process output separately from sanitized JSON artifacts."""
    p=web.run(args,cwd=cwd,env=env,timeout=120)
    record(name,p.stdout,p.stderr,p.returncode,version,config,temp,port)
    return p


def record(name,stdout,stderr,code,version,config,temp,port):
    """Sanitize before persisting; normalize only fixture paths and ephemeral target identity."""
    lines=[json.loads(line) for line in stdout.splitlines() if line.strip()] if name=='nuclei' else json.loads(stdout)
    transformed=json.dumps(lines).replace(str(temp),'/fixture').replace(str(temp).lstrip('/').replace('/','.'),'fixture').replace(':'+str(port),':18080')
    data=json.loads(transformed)
    if name=='zap':
        for site in data.get('site',[]):
            if '@port' in site:site['@port']='18080'
    if name=='nuclei':
        for event in data:
            if 'port' in event:event['port']='18080'
    safe=web.run(['php',ROOT/'scripts/sanitize-security-json.php'],input=json.dumps(data))
    if safe.returncode:raise RuntimeError('sanitization failed')
    artifact=''.join(json.dumps(x,separators=(',',':'))+'\n' for x in json.loads(safe.stdout)) if name=='nuclei' else safe.stdout
    OUT.mkdir(parents=True,exist_ok=True)
    filename=name+('.jsonl' if name=='nuclei' else '.json')
    (OUT/filename).write_text(artifact)
    safe_stderr=web.run(['php',ROOT/'scripts/sanitize-security-json.php'],input=json.dumps(stderr.replace(str(temp),'/fixture')))
    metadata={'origin':'real','tool':name,'version':version,'captured_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'native_exit_code':code,'artifact':filename,'sha256':hashlib.sha256(artifact.encode()).hexdigest(),'configuration':config,'configuration_sha256':hashlib.sha256(json.dumps(config,sort_keys=True).encode()).hexdigest(),'stderr':json.loads(safe_stderr.stdout),'transformations':['EvidenceSanitizer before persistence','temporary root -> /fixture','target loopback port -> 18080','payload fields redacted'],'limits':'controlled fixture; positive output contract, not production coverage'}
    (OUT/(name+'.capture.json')).write_text(json.dumps(metadata,indent=2)+'\n')
    print(name,'captured, exit',code,flush=True)


def api(base,key,path,params=None):
    """Call only the loopback daemon with an ephemeral API key kept out of artifacts."""
    query=urllib.parse.urlencode(dict(params or {},apikey=key))
    with urllib.request.urlopen(base+path+'?'+query,timeout=20) as response:return json.load(response)


def main():
    """Verify preserved payloads, capture all sources and shut down the isolated daemon."""
    lock=json.loads((ROOT/'security/web-tools.lock.json').read_text())
    for name in ['semgrep','psalm','nuclei','zap']:
        item=lock['tools'][name];web.verify(ROOT/'.tools'/name,item['files'],item.get('external_files'),item.get('relocatable_files'),item.get('record_files'))
    import shutil
    for name in ['composer','php','java']:
        path=shutil.which(name)
        if not path or web.digest(path)!=lock['host_baseline'][name]['sha256']:
            raise ValueError('host baseline changed before capture: '+name)
    with tempfile.TemporaryDirectory(prefix='ninfa-contract-') as folder:
        temp=Path(folder);temp.chmod(0o700)
        server=ThreadingHTTPServer(('127.0.0.1',0),Fixture)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        port=server.server_port;url='http://127.0.0.1:'+str(port)
        env=dict(os.environ,COMPOSER_HOME=str(temp/'composer-home'),COMPOSER_CACHE_DIR=str(temp/'composer-cache'),COMPOSER_NO_INTERACTION='1',SEMGREP_SEND_METRICS='off',SEMGREP_ENABLE_VERSION_CHECK='0',XDEBUG_MODE='off')
        project=temp/'project';project.mkdir()
        package={'name':'ninfa/fixture-app','require':{'ninfa/fixture':'1.0.0'},'repositories':{'fixture':{'type':'composer','url':url},'packagist.org':False},'config':{'secure-http':False,'allow-plugins':False}}
        (project/'composer.json').write_text(json.dumps(package))
        (project/'composer.lock').write_text(json.dumps({'packages':[{'name':'ninfa/fixture','version':'1.0.0','abandoned':True}],'packages-dev':[],'aliases':[],'minimum-stability':'stable','stability-flags':{},'prefer-stable':False,'prefer-lowest':False,'platform':{},'platform-dev':{},'plugin-api-version':'2.9.0'}))
        p=capture('composer',['composer','audit','--locked','--format=json','--no-plugins','--no-scripts'],project,'2.9.5',package,temp,port,env)
        if p.returncode!=3:raise RuntimeError('Composer expected positive advisory+abandoned exit3')
        (project/'input.php').write_text('<?php\necho $_GET["input"];\n')
        rule={'rules':[{'id':'ninfa-contract-echo','languages':['php'],'message':'Controlled output fixture','severity':'WARNING','pattern':'echo $X;'}]}
        (temp/'rule.json').write_text(json.dumps(rule))
        capture('semgrep',[ROOT/'.tools/semgrep/bin/semgrep','--config',temp/'rule.json','--json','--metrics=off','--disable-version-check','--no-git-ignore','input.php'],project,'1.177.0',rule,temp,port,env)
        xml='<psalm errorLevel="1" resolveFromConfigFile="true"><projectFiles><file name="input.php" /></projectFiles></psalm>'
        (project/'psalm.xml').write_text(xml)
        static=temp/'static';static.mkdir();(static/'input.php').write_text((project/'input.php').read_text());(static/'psalm.xml').write_text(xml)
        p=capture('psalm',[ROOT/'.tools/psalm/bin/psalm','--config=psalm.xml','--taint-analysis','--output-format=json','--no-cache','--no-progress'],static,'6.20.1',{'xml':xml,'mode':'taint-analysis'},temp,port,env)
        if p.returncode!=2:raise RuntimeError('Psalm expected positive exit2')
        template='''id: ninfa-contract
info:
  name: Ninfa controlled contract marker
  author: ninfa
  severity: info
http:
  - method: GET
    path:
      - "{{BaseURL}}/"
    matchers:
      - type: word
        name: contract-marker
        words:
          - NINFA_CONTRACT_MARKER
'''
        (temp/'contract.yaml').write_text(template);(temp/'nuclei-config.yaml').write_text('{}\n')
        capture('nuclei',[ROOT/'.tools/nuclei/bin/nuclei','-config',temp/'nuclei-config.yaml','-u',url,'-t',temp/'contract.yaml','-jsonl','-silent','-no-color','-duc','-ni','-no-stdin','-omit-raw','-omit-template','-rl','1','-c','1'],temp,'3.11.1',{'template':template,'flags':'-duc -ni -no-stdin -omit-raw -omit-template -rl 1 -c 1'},temp,port,env)
        query={'queries':[{'package':{'name':'monolog/monolog','ecosystem':'Packagist'},'version':'1.10.0'}]}
        req=urllib.request.Request('https://api.osv.dev/v1/querybatch',data=json.dumps(query).encode(),headers={'Content-Type':'application/json'})
        with urllib.request.urlopen(req,timeout=30) as response:batch=json.load(response)
        vulnerabilities=[]
        for match in batch['results'][0].get('vulns',[]):
            with urllib.request.urlopen('https://api.osv.dev/v1/vulns/'+match['id'],timeout=30) as response:vulnerabilities.append(json.load(response))
        record('osv',json.dumps({'batch':batch,'vulnerabilities':vulnerabilities}),'',None,'HTTP v1',query,temp,port)
        # Passive ZAP only: one URL, no spiders, no active scan, fresh home and private API.
        sock=socket.socket();sock.bind(('127.0.0.1',0));zap_port=sock.getsockname()[1];sock.close()
        key=secrets.token_hex(24);base='http://127.0.0.1:'+str(zap_port)
        logfile=open(temp/'zap.log','w+')
        args=[ROOT/'.tools/zap/bin/zap','-daemon','-host','127.0.0.1','-port',str(zap_port),'-dir',temp/'zap-home','-config','api.key='+key,'-config','api.addrs.addr.name=127.0.0.1','-config','api.addrs.addr.regex=false','-config','autoupdate.checkOnStart=false','-config','autoupdate.installAddonUpdates=false','-config','autoupdate.downloadNewRelease=false','-config','callhome.enabled=false']
        process=subprocess.Popen([str(x) for x in args],stdout=logfile,stderr=logfile,start_new_session=True)
        try:
            deadline=time.monotonic()+100
            while True:
                try:api(base,key,'/JSON/core/view/version/');break
                except Exception:
                    if process.poll() is not None or time.monotonic()>deadline:raise RuntimeError('ZAP daemon did not become ready; log in private temp')
                    time.sleep(1)
            api(base,key,'/JSON/core/action/accessUrl/',{'url':url,'followRedirects':'false'})
            time.sleep(2)
            deadline=time.monotonic()+45
            while int(api(base,key,'/JSON/pscan/view/recordsToScan/')['recordsToScan']):
                if time.monotonic()>deadline:raise RuntimeError('ZAP passive timeout')
                time.sleep(0.5)
            report=api(base,key,'/JSON/reports/action/generate/',{'title':'Controlled contract capture','template':'traditional-json','reportDir':str(temp),'reportFileName':'zap-contract','sites':url})
            reportpath=Path(report['generate'])
            raw=reportpath.read_text()
            record('zap',raw,'',None,'2.17.0',{'mode':'one URL passive','report':'traditional-json','api_version':api(base,key,'/JSON/core/view/version/'),'remaining_passive':0},temp,port)
        finally:
            try:api(base,key,'/JSON/core/action/shutdown/')
            except Exception:pass
            try:process.wait(timeout=15)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid,signal.SIGTERM)
                try:process.wait(timeout=5)
                except subprocess.TimeoutExpired:os.killpg(process.pid,signal.SIGKILL);process.wait()
            logfile.close();server.shutdown();server.server_close()


if __name__=='__main__':main()
