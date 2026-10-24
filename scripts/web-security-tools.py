#!/usr/bin/env python3
"""Native pinned installers and independent preflight. Never scan a consumer."""
import argparse
import csv
import io
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import signal
import shutil
import subprocess
import tempfile
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parent.parent


def digest(path):
    """Hash bytes without loading large distributions in memory."""
    with open(path, 'rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def run(args, **kwargs):
    """Bound probes, capture diagnostics and avoid shell evaluation."""
    timeout = kwargs.pop('timeout',45)
    input_text = kwargs.pop('input',None)
    process = subprocess.Popen([str(a) for a in args],text=True,stdin=subprocess.PIPE,
                               stdout=subprocess.PIPE,stderr=subprocess.PIPE,start_new_session=True,**kwargs)
    try:
        stdout,stderr = process.communicate(input_text,timeout=timeout)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid,signal.SIGTERM)
        try:
            process.communicate(timeout=3)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid,signal.SIGKILL)
            process.communicate()
        raise
    return subprocess.CompletedProcess(args,process.returncode,stdout,stderr)


def canonical_record(path):
    """Normalize pip bookkeeping; every executable/package byte is verified separately."""
    rows=[]
    for row in csv.reader(Path(path).read_text().splitlines()):
        if row[0].endswith('/REQUESTED'):
            continue
        if row[0].startswith('../') and '/bin/' in row[0]:
            row=[row[0],'generated-launcher','0']
        rows.append(row)
    return hashlib.sha256(json.dumps(sorted(rows),separators=(',',':')).encode()).hexdigest()


def verify(directory, files, external=None, relocatable=None, records=None):
    """Verify the locked payload, rejecting absent or escaping file paths."""
    base = Path(directory).resolve()
    for relative, expected in files.items():
        path = base / relative
        if not path.is_file() or not path.resolve().is_relative_to(base):
            raise ValueError('missing/escaping locked file: ' + relative)
        actual_hash = hashlib.sha256(path.read_bytes().replace(str(base).encode(), b'{INSTALL_ROOT}')).hexdigest() if relative in (relocatable or {}) else digest(path)
        expected = (relocatable or {}).get(relative, expected)
        if relative in (records or {}):
            actual_hash = canonical_record(path)
            expected = records[relative]
        if actual_hash != expected:
            raise ValueError('sha256 mismatch: ' + relative)
    for relative, expected in (external or {}).items():
        if digest(base / relative) != expected:
            raise ValueError('external runtime digest mismatch: ' + relative)
    # New executable code/add-ons/templates must not silently enter an installation.
    actual = {str(p.relative_to(base)) for p in base.rglob('*') if p.is_file()
              and '.git' not in p.relative_to(base).parts and '__pycache__' not in p.parts
              and p.suffix != '.pyc' and p.name != 'installation.json' and '.links' not in p.parts}
    if actual != set(files) | set(external or {}):
        raise ValueError('unlocked payload files: ' + ', '.join(sorted(actual ^ set(files))[:5]))


def download(url, destination, expected):
    """Verify a completed download before extraction or executing any payload."""
    if not re.fullmatch(r'[0-9a-f]{64}', expected or ''):
        raise ValueError('trusted SHA-256 required')
    with urllib.request.urlopen(url, timeout=30) as source, open(destination, 'xb') as target:
        shutil.copyfileobj(source, target)
    if digest(destination) != expected:
        raise ValueError('download SHA-256 mismatch')


def unzip(archive, destination):
    """Extract only ordinary files/directories; reject traversal and symlinks."""
    with zipfile.ZipFile(archive) as package:
        for entry in package.infolist():
            path = Path(entry.filename)
            if path.is_absolute() or '..' in path.parts or (entry.external_attr >> 16) & 0o170000 == 0o120000:
                raise ValueError('unsafe archive member')
        package.extractall(destination)
        for entry in package.infolist():
            path = destination / entry.filename
            if path.is_file():
                path.chmod(0o755 if entry.external_attr >> 16 & 0o111 else 0o644)


def install(name, base, lock):
    """Install into a sibling staging directory and publish only validated bytes."""
    spec = lock['tools'][name]
    base.mkdir(mode=0o700, parents=True, exist_ok=True)
    with open(base / '.web-tools.lock', 'a') as guard:
        fcntl.flock(guard, fcntl.LOCK_EX)
        target = base / name
        if target.exists():
            verify(target, spec['files'], spec.get('external_files'), spec.get('relocatable_files'), spec.get('record_files'))
            return {'tool': name, 'state': 'unchanged'}
        with tempfile.TemporaryDirectory(prefix='.' + name + '-', dir=base) as temp:
            stage = Path(temp) / ('semgrep' if name == 'semgrep' else 'payload')
            stage.mkdir(mode=0o700)
            if name == 'nuclei-templates':
                # Git object IDs bind the whole source tree; never resolve a moving tag at scan time.
                p = run(['git','init',stage])
                if p.returncode:
                    raise RuntimeError('git init failed')
                p = run(['git','-C',stage,'fetch','--depth=1',spec['url'],spec['revision']],timeout=180)
                if p.returncode:
                    raise RuntimeError('git fetch failed: ' + p.stderr[-1000:])
                p = run(['git', '-C', stage, 'checkout', '--detach', 'FETCH_HEAD'])
                if p.returncode:
                    raise RuntimeError('template checkout failed')
                if run(['git', '-C', stage, 'rev-parse', 'HEAD']).stdout.strip() != spec['revision']:
                    raise ValueError('template revision mismatch')
            elif name in ('zap', 'nuclei', 'psalm'):
                release = spec['release']
                archive = Path(temp) / 'download'
                download(release['url'], archive, release['sha256'])
                if name == 'psalm':
                    shutil.copyfile(archive, stage / 'psalm.phar')
                else:
                    unpacked = Path(temp) / 'unpacked'
                    unzip(archive, unpacked)
                    content = unpacked / ('ZAP_' + spec['version']) if name == 'zap' else unpacked
                    shutil.copytree(content, stage, dirs_exist_ok=True)
                (stage / 'bin').mkdir(exist_ok=True)
                if name == 'nuclei':
                    shutil.move(stage / 'nuclei', stage / 'bin/nuclei')
                    (stage / 'bin/nuclei').chmod(0o755)
                else:
                    wrapper = stage / 'bin' / name
                    wrapper.write_text('#!/bin/sh\nexec ' + ('"$(dirname "$0")/../zap.sh"' if name == 'zap' else 'php "$(dirname "$0")/../psalm.phar"') + ' "$@"\n')
                    wrapper.chmod(0o755)
                    # Wrapper is Ninfa code; distribution files retain the archive pins.
                    actual = digest(wrapper)
                    if actual != spec['files']['bin/' + name]:
                        raise ValueError('wrapper differs from locked installation; regenerate lock after review')
            elif name == 'playwright':
                package_lock = ROOT / 'security/browser/package-lock.json'
                if digest(package_lock) != spec['release']['package_lock_sha256']:
                    raise ValueError('browser package lock mismatch')
                for filename in ['package.json','package-lock.json']:
                    shutil.copyfile(ROOT/'security/browser'/filename,stage/filename)
                p = run(['npm','ci','--ignore-scripts','--no-audit','--no-fund'],cwd=stage,timeout=180)
                if p.returncode:
                    raise RuntimeError('npm ci failed: ' + p.stderr[-1000:])
                # npm's SRI-pinned packages are checked against the recorded payload before running the installer.
                for relative, expected in spec['files'].items():
                    if relative.startswith('node_modules/') and digest(stage/relative) != expected:
                        raise ValueError('Playwright package payload mismatch: '+relative)
                env = dict(os.environ,PLAYWRIGHT_BROWSERS_PATH=str(stage/'browsers'))
                p = run(['node',stage/'node_modules/playwright/cli.js','install','chromium'],env=env,timeout=240)
                if p.returncode:
                    raise RuntimeError('Chromium install failed: '+p.stderr[-1000:])
                (stage/'bin').mkdir()
                wrapper=stage/'bin/playwright'
                wrapper.write_text('#!/bin/sh\nexport PLAYWRIGHT_BROWSERS_PATH="$(dirname "$0")/../browsers"\nexec node "$(dirname "$0")/../node_modules/playwright/cli.js" "$@"\n')
                wrapper.chmod(0o755)
            elif name == 'semgrep':
                wheels = json.loads((ROOT/'security/semgrep-wheels.lock.json').read_text())
                requirements = Path(temp)/'requirements.txt'
                requirements.write_text('\n'.join(p['name']+'=='+p['version']+' '+' '.join('--hash=sha256:'+w['sha256'] for w in p['wheels']) for p in wheels['packages'])+'\n')
                p = run(['python3','-m','venv',stage],timeout=60)
                if p.returncode:
                    raise RuntimeError('venv failed: '+p.stderr[-500:])
                p = run([stage/'bin/python','-m','pip','install','--disable-pip-version-check','--only-binary=:all:','--require-hashes','-r',requirements],timeout=240)
                if p.returncode:
                    raise RuntimeError('pinned wheel installation failed: '+p.stderr[-1000:])
                for requested in stage.rglob('REQUESTED'):
                    if str(requested.relative_to(stage)) not in spec['files']:
                        requested.unlink()
            else:
                raise ValueError('unknown installer')
            verify(stage, spec['files'], spec.get('external_files'), spec.get('relocatable_files'), spec.get('record_files'))
            if name == 'semgrep':
                # Generated launchers must point at the final location after atomic publication.
                for relative in spec.get('relocatable_files', {}):
                    file = stage/relative
                    file.write_bytes(file.read_bytes().replace(str(stage).encode(),str(target).encode()))
            (stage / 'installation.json').write_text(json.dumps({'release':spec.get('release'), 'files':spec['files']}))
            os.replace(stage, target)
    return {'tool':name, 'state':'installed'}


def resolve(name, project, base, php):
    """Delegate precedence to ToolResolver, using an alternate Ninfa root for clean trials."""
    # ToolResolver's private directory is <ninfa>/.tools; explicit trial roots must preserve it.
    ninfa = ROOT if base == ROOT / '.tools' else base.parent
    if base.name != '.tools':
        raise ValueError('--tools-dir must end with .tools to match ToolResolver')
    proc = run([php, ROOT/'scripts/resolve-security-tool.php', name, project, ninfa])
    if proc.returncode:
        raise RuntimeError(proc.stderr.strip())
    return Path(proc.stdout.strip())


def doctor(project, base, lock, network=False):
    """Collect every source independently; integrity gates all executable probes."""
    results = {}
    php = shutil.which('php')
    for name in ['php','java','node','python3','npm','composer','semgrep','psalm','zap','nuclei','playwright','nuclei-templates','chromium','osv']:
        item = {'state':'unavailable','effect':'required capability unavailable','path':None,'integrity':'unknown'}
        results[name] = item
        try:
            if name == 'osv':
                item.update(api=lock['requirements']['osv_api'], checked_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
                if not network:
                    item.update(state='unknown',cause='network probe disabled; use --network',effect='OSV availability unverified')
                    continue
                req = urllib.request.Request(item['api'], data=b'{"queries":[{"package":{"name":"monolog/monolog","ecosystem":"Packagist"},"version":"1.10.0"}]}', headers={'Content-Type':'application/json'})
                with urllib.request.urlopen(req, timeout=15) as response:
                    payload = json.load(response)
                if not isinstance(payload.get('results'), list):
                    raise ValueError('OSV response contract incompatible')
                item.update(state='completed',integrity='https-service',effect=None)
                continue
            if name == 'nuclei-templates':
                path = base/name
                verify(path,lock['tools'][name]['files'])
                item.update(path=str(path),integrity='local-baseline-verified',version=lock['tools'][name]['version'],revision=lock['tools'][name]['revision'],state='completed',effect=None)
                continue
            dependency = {'semgrep':'python3','npm':'node','psalm':'php','composer':'php','zap':'java','playwright':'node','chromium':'playwright'}.get(name)
            if dependency and results[dependency]['state'] != 'completed':
                raise ValueError('required runtime unavailable: '+dependency)
            if name == 'chromium':
                verify(base/'playwright',lock['tools']['playwright']['files'])
                browser = base/'playwright/browsers/chromium-1248/chrome-linux64/chrome'
                path = browser
                args = ['--version']
                expected = lock['requirements']['browser_version']
            elif name in lock['host_baseline']:
                path = resolve(name, project, base, php) if name == 'composer' else Path(shutil.which(name) or '/missing')
                expected_host = lock['host_baseline'][name]
                if digest(path) != expected_host['sha256']:
                    raise ValueError('host executable differs from recorded baseline; review before probe')
                args = expected_host['args']
                expected = None
            else:
                path = resolve(name, project, base, php)
                spec = lock['tools'][name]
                if path.absolute() != base/name/spec['command'][0]:
                    item['path'] = str(path)
                    raise ValueError('consumer/PATH override selected; no trusted digest registered, probe refused')
                verify(base/name,spec['files'],spec.get('external_files'),spec.get('relocatable_files'), spec.get('record_files'))
                args = spec['command'][1:]
                expected = spec['version']
            item.update(path=str(path),integrity='local-baseline-verified')
            if not os.access(path,os.X_OK):
                raise ValueError('binary not executable')
            env = dict(os.environ,SEMGREP_SEND_METRICS='off',SEMGREP_ENABLE_VERSION_CHECK='0',PLAYWRIGHT_BROWSERS_PATH=str(base/'playwright/browsers'))
            if name == 'zap':
                # Never contend with a user's desktop/daemon profile during a read-only version check.
                with tempfile.TemporaryDirectory(prefix='ninfa-zap-doctor-') as home:
                    p = run([path,*args,'-dir',Path(home)/'profile',
                             '-config','autoupdate.checkOnStart=false'],env=env)
            else:
                p = run([path,*args],env=env)
            output = (p.stdout+'\n'+p.stderr).strip()
            if p.returncode:
                raise ValueError('version probe failed: code '+str(p.returncode))
            if expected and expected not in output:
                raise ValueError('version mismatch: expected '+expected)
            if name == 'java':
                match = re.search(r'version "(\d+)',output)
                if not match or int(match[1]) < lock['requirements']['java_min']:
                    raise ValueError('Java >=17 required')
            if name == 'node' and int(output.lstrip('v').split('.')[0]) < 20:
                raise ValueError('Node >=20 required')
            if name == 'php':
                probe = run([path,'-r','echo json_encode([PHP_VERSION, get_loaded_extensions()]);'])
                version, extensions = json.loads(probe.stdout)
                if tuple(map(int,version.split('.')[:2])) < (8,2):
                    raise ValueError('Psalm requires PHP >=8.2')
                missing = set(lock['requirements']['psalm_extensions']) - {x.lower() for x in extensions}
                if missing:
                    raise ValueError('Psalm PHP extensions missing: '+','.join(sorted(missing)))
            if name == 'playwright':
                smoke = 'const {chromium}=require(process.argv[1]); (async()=>{const b=await chromium.launch({headless:true});try{const p=await b.newPage();await p.setContent("<title>Ninfa smoke</title>");if(await p.title()!=="Ninfa smoke")throw Error("browser DOM mismatch");}finally{await b.close();}})().catch(()=>process.exit(1));'
                p = run(['node','-e',smoke,base/'playwright/node_modules/playwright'],env=env)
                if p.returncode:
                    raise ValueError('Playwright Chromium launch/DOM smoke failed')
                item['browser_smoke'] = 'passed; local DOM only'
            item.update(state='completed',effect=None,version_output=output[:500])
        except Exception as error:
            item.update(cause=str(error))
    return {'checked_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'audit_executed':False,'supply_chain_status':lock['verification'],'sources':results,'full_web_ready':False}


def main():
    """Expose explicit install/doctor operations; never update during a scan."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['install','doctor'])
    parser.add_argument('names',nargs='*')
    parser.add_argument('--tools-dir',type=Path,default=ROOT/'.tools')
    parser.add_argument('--project',type=Path,default=Path('/tmp/ninfa-preflight-consumer'))
    parser.add_argument('--lock',type=Path,default=ROOT/'security/web-tools.lock.json')
    parser.add_argument('--network',action='store_true')
    args = parser.parse_args()
    lock = json.loads(args.lock.read_text())
    # Resolve aliases/ancestors before checking isolation; lexical paths can hide a consumer destination.
    base = args.tools_dir.resolve()
    project = args.project.resolve()
    if base == project or base.is_relative_to(project):
        parser.error('tools directory must be outside the consumer')
    if args.action == 'doctor':
        result = doctor(project,base,lock,args.network)
        print(json.dumps(result,indent=2))
        return 0 if all(i['state']=='completed' for i in result['sources'].values()) else 1
    if platform.system() != 'Linux' or platform.machine() != 'x86_64':
        parser.error('locked platform is linux-x86_64')
    for name in args.names or ['zap','nuclei','psalm','nuclei-templates','playwright','semgrep']:
        try:
            print(json.dumps(install(name,base,lock)),flush=True)
        except Exception as error:
            print(json.dumps({'tool':name,'state':'error','cause':str(error)}))
            return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
