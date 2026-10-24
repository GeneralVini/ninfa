#!/usr/bin/env python3
"""SEC-05.01: bounded NINFA discovery followed by offline, selected ZAP rules.

Internal adapter transport, not a public audit CLI. Raw bodies/HAR/daemon logs
exist only in a private temporary directory and are removed after termination.
"""
import base64
from collections import deque
import datetime
import hashlib
from html.parser import HTMLParser
import http.client
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request

import importlib.util

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('ninfa_web_tools', ROOT / 'scripts/web-security-tools.py')
tools = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tools)


class Cancelled(Exception):
    """An explicit signal stops scheduling while retaining partial measures."""


def canonical(url):
    """Reject ambiguous URLs, credentials, queries and encoded/traversing paths."""
    if not isinstance(url, str) or not url.isascii() or re.search(r'[\s\\%]', url):
        raise ValueError('invalid-url')
    p = urllib.parse.urlsplit(url)
    if p.scheme not in ('http', 'https') or not p.hostname or p.username or p.password or p.query:
        raise ValueError('invalid-url')
    if not re.fullmatch(r'[A-Za-z0-9.:-]+', p.hostname):
        raise ValueError('invalid-host')
    path = p.path or '/'
    if any(x in ('.', '..') for x in path.split('/')) or '//' in path or ';' in path:
        raise ValueError('ambiguous-path')
    host = p.hostname.lower()
    host = '[' + host + ']' if ':' in host else host
    port = p.port or (443 if p.scheme == 'https' else 80)
    return urllib.parse.urlunsplit((p.scheme, host + ':' + str(port), path, '', ''))


def policy(data):
    """Require exact GET read-only authorization and enforce contract ceilings."""
    expected = {'origin', 'address', 'base_path', 'safe_get_urls', 'seeds', 'max_requests',
                'max_seconds', 'interval_seconds', 'max_body_bytes', 'max_redirects'}
    if not isinstance(data, dict) or set(data) != expected:
        raise ValueError('invalid-policy-fields')
    ipaddress.ip_address(data['address'])  # No runtime DNS or ambient HTTP proxy.
    origin = canonical(data['origin'])
    if urllib.parse.urlsplit(origin).path != '/' or not isinstance(data['base_path'], str):
        raise ValueError('invalid-origin')
    base = data['base_path']
    if not base.startswith('/') or not base.endswith('/') or canonical(origin.rstrip('/') + base) != origin.rstrip('/') + base:
        raise ValueError('invalid-base-path')
    ceilings = {'max_requests': (1, 100), 'max_seconds': (1, 300),
                'max_body_bytes': (1, 1048576), 'max_redirects': (0, 3)}
    for key, (low, high) in ceilings.items():
        if type(data[key]) is not int or not low <= data[key] <= high:
            raise ValueError('invalid-limit')
    if type(data['interval_seconds']) not in (int, float) or not 1 <= data['interval_seconds'] <= 10:
        raise ValueError('invalid-rate')
    allowed = []
    for key in ('safe_get_urls', 'seeds'):
        if not isinstance(data[key], list) or not 1 <= len(data[key]) <= 100:
            raise ValueError('invalid-url-list')
        values = [canonical(url) for url in data[key]]
        if len(values) != len(set(values)):
            raise ValueError('duplicate-url')
        for url in values:
            p = urllib.parse.urlsplit(url)
            if (p.scheme, p.netloc) != (urllib.parse.urlsplit(origin).scheme, urllib.parse.urlsplit(origin).netloc) or not p.path.startswith(base):
                raise ValueError('outside-scope')
        if key == 'safe_get_urls':
            allowed = values
        elif not set(values) <= set(allowed):
            raise ValueError('seed-not-authorized')
    return dict(data, origin=origin, safe_get_urls=allowed, seeds=[canonical(x) for x in data['seeds']])


class Links(HTMLParser):
    """Discover anchors only; do not submit forms or execute JavaScript/resources."""
    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            self.urls.extend(value for key, value in attrs if key == 'href' and value is not None)


def fetch(url, p, deadline):
    """Make one connection to an explicit IP, retaining Host/SNI and TLS checks."""
    target = urllib.parse.urlsplit(url)
    timeout = max(.01, min(10, deadline - time.monotonic()))
    cls = http.client.HTTPSConnection if target.scheme == 'https' else http.client.HTTPConnection
    connection = cls(target.hostname, target.port, timeout=timeout)
    connection._create_connection = lambda address, timeout, source_address=None: socket.create_connection(
        (p['address'], target.port), timeout, source_address)
    try:
        connection.request('GET', target.path, headers={'User-Agent': 'NINFA-Discovery/1',
                           'Accept': 'text/html,*/*;q=0.5', 'Accept-Encoding': 'identity', 'Connection': 'close'})
        response = connection.getresponse()
        content = bytearray()
        while len(content) <= p['max_body_bytes']:
            if time.monotonic() >= deadline:
                raise TimeoutError('deadline')
            chunk = response.read1(min(65536, p['max_body_bytes'] + 1 - len(content)))
            if not chunk:
                break
            content.extend(chunk)
        if len(content) > p['max_body_bytes']:
            raise ValueError('body-limit')
        if response.getheader('Content-Encoding', 'identity').lower() != 'identity':
            raise ValueError('unsupported-content-encoding')
        return response.status, response.reason, response.getheaders(), bytes(content)
    finally:
        connection.close()


def discover(p, result, deadline):
    """Count before send, recheck every redirect and retain bounded frontier proof."""
    frontier = deque((url, 0) for url in p['seeds'])
    seen = set()
    entries = []
    last_sent = 0
    while frontier:
        url, hops = frontier.popleft()
        if url in seen:
            continue
        seen.add(url)
        if url not in p['safe_get_urls']:
            result['blocked'].append({'url': url, 'reason': 'not-explicit-read-only-get'})
            continue
        if result['requests'] >= p['max_requests']:
            result['reasons'].append('request-budget')
            break
        time.sleep(max(0, last_sent + p['interval_seconds'] - time.monotonic()))
        if time.monotonic() >= deadline:
            raise TimeoutError('deadline')
        result['requests'] += 1
        last_sent = time.monotonic()
        result['sent'].append({'url': url, 'at_monotonic': last_sent})
        status, reason, headers, body = fetch(url, p, deadline)
        result['observed'].append({'url': url, 'method': 'GET', 'status': status,
                                   'bytes': len(body), 'response_sha256': hashlib.sha256(body).hexdigest()})
        if status >= 400:
            result['reasons'].append('http-error-response')
        entries.append({'startedDateTime': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'time': 0,
            'request': {'method': 'GET', 'url': url, 'httpVersion': 'HTTP/1.1', 'cookies': [],
                        'headers': [{'name': 'Host', 'value': urllib.parse.urlsplit(url).netloc}],
                        'queryString': [], 'headersSize': -1, 'bodySize': 0},
            'response': {'status': status, 'statusText': reason, 'httpVersion': 'HTTP/1.1', 'cookies': [],
                'headers': [{'name': k, 'value': v} for k, v in headers],
                'content': {'size': len(body), 'mimeType': dict((k.lower(), v) for k, v in headers).get('content-type', ''),
                            'text': base64.b64encode(body).decode(), 'encoding': 'base64'},
                'redirectURL': '', 'headersSize': -1, 'bodySize': len(body)},
            'cache': {}, 'timings': {'send': 0, 'wait': 0, 'receive': 0}})
        lowered = {k.lower(): v for k, v in headers}
        links = []
        if 300 <= status < 400 and 'location' in lowered:
            if hops >= p['max_redirects']:
                result['reasons'].append('redirect-limit')
            else:
                links.append((lowered['location'], hops + 1))
        elif 'text/html' in lowered.get('content-type', '').lower():
            parser = Links()
            parser.feed(body.decode('utf-8', errors='replace'))
            links.extend((link, 0) for link in parser.urls)
        for link, depth in links:
            try:
                discovered = canonical(urllib.parse.urljoin(url, link))
            except ValueError:
                result['blocked'].append({'reason': 'ambiguous-or-unsupported-link'})
                continue
            if discovered not in seen:
                frontier.append((discovered, depth))
        # The input and body ceilings also bound memory; do not queue arbitrary site graphs.
        if len(frontier) > 1000:
            result['reasons'].append('frontier-limit')
            break
    result['unobserved_authorized'] = sorted(set(p['safe_get_urls']) - {x['url'] for x in result['observed']})
    result['discovery_finished'] = not frontier and not result['reasons'] and not result['unobserved_authorized']
    return entries


def stop_group(process):
    """Terminate the complete scanner process group even if its launcher exited."""
    for sig in (signal.SIGTERM, signal.SIGKILL):
        try:
            os.killpg(process.pid, sig)
        except ProcessLookupError:
            break
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            pass
        if sig == signal.SIGTERM:
            time.sleep(.1)
    process.wait(timeout=3)


def preflight(directory):
    """Verify native payload and runtime before contacting any consumer."""
    lock = json.loads((ROOT / 'security/web-tools.lock.json').read_text())
    installed = ROOT / '.tools/zap'
    tools.verify(installed, lock['tools']['zap']['files'])
    java = Path(shutil.which('java') or '/missing')
    if tools.digest(java) != lock['host_baseline']['java']['sha256']:
        raise ValueError('unverified-java')
    # No consumer override can silently bypass the reviewed native tool lock.
    selected = tools.resolve('zap', directory, ROOT / '.tools', shutil.which('php'))
    if selected != installed / 'bin/zap':
        raise ValueError('unverified-zap-override')
    return lock, installed, java


def passive(entries, ids, directory, result, deadline):
    """Import recorded exchanges without retransmission; verify rules and drain."""
    lock, installed, java = preflight(directory)
    sock = socket.socket()
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
    sock.close()
    key = secrets.token_hex(24)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def api(component, kind, action, **params):
        """Talk only to the private local API and reject API error envelopes."""
        endpoint = f'http://127.0.0.1:{port}/JSON/{component}/{kind}/{action}/'
        request = urllib.request.Request(endpoint, data=urllib.parse.urlencode(params).encode(),
                                         headers={'X-ZAP-API-Key': key})
        with opener.open(request, timeout=max(.01, min(5, deadline - time.monotonic()))) as response:
            data = json.load(response)
        if 'code' in data:
            raise ValueError('zap-api-error')
        return data

    args = [str(java), '-Xmx512m', '-Duser.home=' + str(directory), '-jar', str(installed / 'zap-2.17.0.jar'),
            '-daemon', '-host', '127.0.0.1', '-port', str(port),
            '-dir', str(directory / 'home')]
    settings = {'api.key': key, 'api.addrs.addr.name': '127.0.0.1', 'api.addrs.addr.regex': 'false',
                'autoupdate.checkOnStart': 'false', 'autoupdate.installAddonUpdates': 'false',
                'autoupdate.downloadNewRelease': 'false', 'callhome.enabled': 'false'}
    for name, value in settings.items():
        args.extend(['-config', name + '=' + value])
    with open(directory / 'daemon.log', 'w') as logfile:
        process = subprocess.Popen(args, stdout=logfile, stderr=logfile, start_new_session=True,
                                   env={'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'HOME': str(directory), 'LANG': 'C.UTF-8'})
        result['scanner_pid'] = process.pid
        result['scanner_stopped'] = False
        try:
            while True:
                try:
                    version = api('core', 'view', 'version')['version']
                    break
                except (OSError, ValueError):
                    if process.poll() is not None or time.monotonic() >= deadline:
                        raise RuntimeError('zap-startup-failed')
                    time.sleep(.25)
            if version != lock['tools']['zap']['version']:
                raise ValueError('unexpected-zap-version')
            api('core', 'action', 'setMode', mode='safe')
            api('pscan', 'action', 'disableAllScanners')
            api('pscan', 'action', 'enableScanners', ids=','.join(ids))
            for rule in ids:
                api('pscan', 'action', 'setScannerAlertThreshold', id=rule, alertThreshold='MEDIUM')
            scanners = api('pscan', 'view', 'scanners')['scanners']
            enabled = sorted(str(s['id']) for s in scanners if str(s['enabled']).lower() == 'true')
            if enabled != sorted(ids):
                raise ValueError('selection-not-effective')
            result['effective_rules'] = enabled
            har = directory / 'observations.har'
            har.write_text(json.dumps({'log': {'version': '1.2', 'creator': {'name': 'NINFA', 'version': '1'}, 'entries': entries}}))
            api('exim', 'action', 'importHar', filePath=str(har), sendRequests='false', maxMessages=str(len(entries)))
            # Import completion, exact history count, and stable empty queue are all required.
            stable = 0
            while stable < 3:
                if time.monotonic() >= deadline:
                    raise TimeoutError('passive-deadline')
                count = int(api('core', 'view', 'numberOfMessages')['numberOfMessages'])
                pending = int(api('pscan', 'view', 'recordsToScan')['recordsToScan'])
                stable = stable + 1 if count == len(entries) and pending == 0 else 0
                result['imported_messages'] = count
                result['passive_pending'] = pending
                time.sleep(.5)
            result['passive_finished'] = True
            result['alerts'] = api('core', 'view', 'alerts', start='0', count='10000')
            if len(result['alerts']['alerts']) >= 10000:
                result['reasons'].append('alert-limit')
        finally:
            # The cleanup gets its own short grace; a pending wall deadline must
            # never interrupt group termination and falsely attest stopped=true.
            signal.setitimer(signal.ITIMER_REAL, 0)
            mask = signal.pthread_sigmask(signal.SIG_BLOCK, {signal.SIGTERM, signal.SIGINT})
            try:
                stop_group(process)
                result['scanner_stopped'] = True
            finally:
                signal.pthread_sigmask(signal.SIG_SETMASK, mask)


def execute(data, ids):
    """Bound the entire job and always retain status distinct from findings."""
    p = policy(data)
    allowed = {'10020', '10035', '10038', '10021', '10010', '10011'}
    if not ids or len(ids) != len(set(ids)) or not set(ids) <= allowed:
        raise ValueError('invalid-passive-selection')
    result = {'operation': 'error', 'coverage': 'partial', 'requests': 0, 'sent': [], 'observed': [],
              'blocked': [], 'reasons': [], 'discovery_finished': False, 'passive_finished': False,
              'scanner_stopped': True, 'alerts': {'alerts': []}, 'session_verified': False}

    def interrupt(signum, frame):
        """Unwind through scanner group cleanup on cancel or wall clock limit."""
        if signum == signal.SIGALRM:
            raise TimeoutError('wall-clock-limit')
        raise Cancelled('cancelled')

    previous = {s: signal.signal(s, interrupt) for s in (signal.SIGALRM, signal.SIGTERM, signal.SIGINT)}
    signal.setitimer(signal.ITIMER_REAL, p['max_seconds'])
    deadline = time.monotonic() + p['max_seconds']
    try:
        with tempfile.TemporaryDirectory(prefix='ninfa-zap-passive-') as temp:
            preflight(Path(temp))
            entries = discover(p, result, deadline)
            if entries:
                passive(entries, ids, Path(temp), result, deadline)
            else:
                result['reasons'].append('no-observed-response')
            result['operation'] = 'completed'
    except Cancelled:
        result['operation'] = 'cancelled'
        result['reasons'].append('cancelled')
    except TimeoutError:
        result['operation'] = 'timeout'
        result['reasons'].append('wall-clock-or-io-timeout')
    except Exception as error:
        result['operation'] = 'error'
        # Never serialize arbitrary exception text (may include response/secret data).
        result['reasons'].append('adapter-error-' + type(error).__name__)
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        for signum, handler in previous.items():
            signal.signal(signum, handler)
    result['limits'] = {'requests': p['max_requests'], 'interval_seconds': p['interval_seconds'],
                        'wall_seconds': p['max_seconds'], 'redirects': p['max_redirects']}
    result['limitations'] = ['authorized-static-get-discovery-only', 'no-js-forms-resources-or-native-spider',
                             'no-authenticated-session-attestation', 'observed-responses-not-complete-application',
                             'offline-import-sendRequests-false; no OS network sandbox']
    return result


if __name__ == '__main__':
    os.umask(0o077)
    try:
        document = json.loads(Path(sys.argv[1]).read_text()) if len(sys.argv) > 1 else json.load(sys.stdin)
        if len(sys.argv) > 2 and sys.argv[2] == '--validate':
            print(json.dumps(policy(document['policy'])))
            sys.exit(0)
        output = execute(document['policy'], document['rules'])
        print(json.dumps(output))
    except Exception:
        print(json.dumps({'transport_error': 'invalid-input-or-preflight'}))
        sys.exit(1)
