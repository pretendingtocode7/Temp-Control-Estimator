#!/usr/bin/env python3
"""Prepare the offline v0.4.10 service-worker-fix release from a clean pinned commit.

Supply --commit as the complete current HEAD SHA and --output-root as a fresh
directory inside this workspace but outside the repository. Only ignored npm
build/test output changes in the clean checkout. This script never downloads,
installs, deploys, uses SSH or calls a live business service.
"""

import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import zipfile
import zlib
from datetime import datetime, timezone

ORIGINAL_SHA = '0c1c04ef023b6aa094b2237e1f62452bed8f08d37ed4308e2d9aa0732937f56b'
EXPECTED_LOCK_SHA = '95086183b87a15a142ea7423540c286ced2ac2711b2d7c9d1e0e1d6184ae642d'
OLD_VERSION = '0.4.8'
NEW_VERSION = '0.4.10'
EXPECTED_TEST_COUNT = 124
SLUG = 'temp-control-estimate-builder'
ASSETS = ('estimate-builder.js', 'estimate-builder.css', 'service-worker.js', 'estimate-builder.js.map')
OVERRIDE_PATH = 'app/reconstruction/runtime/public/class-enqueue.php'
OVERRIDE_DESTINATION = 'public/class-enqueue.php'
PACKAGING_TOOL_PATH = 'app/reconstruction/tools/prepare-release.py'


def sha(data):
    return hashlib.sha256(data).hexdigest()


def file_info(path):
    data = path.read_bytes()
    return {'size_bytes': len(data), 'sha256': sha(data)}


def write_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + '\n', encoding='utf-8')


def safe_path(value):
    path = PurePosixPath(value)
    if path.is_absolute() or '..' in path.parts or '\\' in value:
        raise RuntimeError('Unsafe path: ' + value)
    return path


def run(command, cwd, log=None):
    environment = dict(os.environ, CI='1', FORCE_COLOR='0', npm_config_offline='true', npm_config_update_notifier='false')
    if log is not None:
        environment['npm_config_cache'] = str(log.parent / 'npm-cache')
    result = subprocess.run(command, cwd=str(cwd), env=environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    if log:
        log.write_text(result.stdout, encoding='utf-8')
    if result.returncode:
        raise RuntimeError('Command failed: {} (see {})\n{}'.format(' '.join(command), log or 'output', result.stdout[-5000:]))
    return result.stdout


def replace_once(path, pattern, replacement):
    old = path.read_text(encoding='utf-8')
    new, count = re.subn(pattern, replacement, old, flags=re.MULTILINE)
    if count != 1:
        raise RuntimeError('Expected exactly one version match in ' + str(path))
    path.write_text(new, encoding='utf-8')


def main():
    default_repo = Path(__file__).resolve().parents[3]
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=default_repo)
    parser.add_argument('--commit', required=True, help='Exact complete current Git HEAD SHA')
    parser.add_argument('--output-root', type=Path, required=True, help='Fresh output directory under workspace, outside repository')
    args = parser.parse_args()
    repo = args.repo.resolve()
    workspace = repo.parent
    output = args.output_root.resolve()
    if workspace not in output.parents or output == repo or repo in output.parents:
        raise RuntimeError('Output must be inside workspace and outside repository')
    if output.exists():
        raise RuntimeError('Output directory already exists; choose a fresh path')
    if not re.fullmatch(r'[0-9a-fA-F]{40}', args.commit):
        raise RuntimeError('--commit must be the complete 40-character Git HEAD SHA')
    commit = run(['git', 'rev-parse', args.commit + '^{commit}'], repo).strip()
    if commit != args.commit.lower():
        raise RuntimeError('Source commit mismatch')
    if run(['git', 'rev-parse', '--show-toplevel'], repo).strip() != str(repo):
        raise RuntimeError('--repo must identify the repository root')
    if run(['git', 'rev-parse', 'HEAD'], repo).strip() != commit:
        raise RuntimeError('Checkout must be at the exact pinned commit')
    if run(['git', 'status', '--porcelain', '--untracked-files=no'], repo).strip():
        raise RuntimeError('Tracked checkout must be clean before building')
    build = output / 'build'
    if build.exists():
        raise RuntimeError('Build directory already exists; use a fresh --output-root for reproducibility')
    source = build / 'source'
    source.mkdir(parents=True)
    logs = output / 'logs'
    logs.mkdir(parents=True, exist_ok=True)

    # Export only tracked bytes at the pinned commit, not the working tree.
    archive = subprocess.check_output(['git', 'archive', '--format=tar', commit], cwd=str(repo))
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        members = tar.getmembers()
        for member in members:
            safe_path(member.name)
            if not (member.isfile() or member.isdir()):
                raise RuntimeError('Unexpected archive member type: ' + member.name)
        tar.extractall(str(source))
    if file_info(Path(__file__)) != file_info(source / PACKAGING_TOOL_PATH):
        raise RuntimeError('Running packaging tool differs from the pinned committed tool')

    manifest_path = source / 'docs/rebuild/reference-manifest.json'
    reference = json.loads(manifest_path.read_text())
    if reference['archive']['sha256'] != ORIGINAL_SHA or len(reference['files']) != 45:
        raise RuntimeError('Original installer manifest identity/count mismatch')
    for expected in [reference['archive']] + reference['files']:
        relative = safe_path(expected['path'])
        if file_info(source / relative) != {key: expected[key] for key in ('size_bytes', 'sha256')}:
            raise RuntimeError('Original reference changed: ' + expected['path'])
    original_archive = source / reference['archive']['path']
    with zipfile.ZipFile(str(original_archive)) as original_zip:
        if original_zip.testzip() is not None:
            raise RuntimeError('Original installer CRC failed')
        original_members = [entry for entry in original_zip.infolist() if not entry.is_dir()]
        original_names = set()
        for entry in original_members:
            safe_path(entry.filename)
            if stat.S_ISLNK(entry.external_attr >> 16) or entry.flag_bits & 1:
                raise RuntimeError('Original installer contains unexpected special entry')
            prefix = SLUG + '/'
            if not entry.filename.startswith(prefix):
                raise RuntimeError('Unexpected original plugin root')
            relative = entry.filename[len(prefix):]
            original_names.add(relative)
            if original_zip.read(entry) != (source / relative).read_bytes():
                raise RuntimeError('Original ZIP/runtime mismatch: ' + relative)
        if original_names != set(entry['path'] for entry in reference['files']):
            raise RuntimeError('Original ZIP/runtime allowlist mismatch')

    project = source / 'app/reconstruction'
    build_project = repo / 'app/reconstruction'
    installed_dependencies = build_project / 'node_modules'
    if not installed_dependencies.is_dir():
        raise RuntimeError('Offline dependency tree is unavailable')
    lock_path = project / 'package-lock.json'
    if file_info(lock_path)['sha256'] != EXPECTED_LOCK_SHA:
        raise RuntimeError('Dependency lock changed from the reviewed pinned dependency tree')
    lock = json.loads(lock_path.read_text())
    verified_dependencies = []
    absent_optional_dependencies = []
    for relative, locked in sorted(lock['packages'].items()):
        if not relative or not relative.startswith('node_modules/'):
            continue
        package_path = build_project / relative / 'package.json'
        if not package_path.exists():
            if locked.get('optional'):
                absent_optional_dependencies.append(relative)
                continue
            raise RuntimeError('Missing locked dependency: ' + relative)
        installed = json.loads(package_path.read_text())
        if installed.get('version') != locked.get('version'):
            raise RuntimeError('Installed dependency version differs from lock: ' + relative)
        verified_dependencies.append({'path': relative, 'version': installed.get('version')})
    write_json(logs / 'dependency-check.json', {'verified': verified_dependencies, 'absent_optional_platform_packages': absent_optional_dependencies})
    if file_info(build_project / 'package-lock.json') != file_info(lock_path):
        raise RuntimeError('Build checkout lockfile differs from pinned export')
    node_version = run(['node', '--version'], build_project).strip()
    npm_version = run(['npm', '--version'], build_project).strip()
    node_architecture = run(['node', '-p', 'process.platform + "/" + process.arch'], build_project).strip()
    run(['npm', 'run', 'verify:reference'], build_project, logs / 'reference-verification.log')
    test_output = run(['npm', 'test'], build_project, logs / 'frontend-tests.log')
    plain_test_output = re.sub(r'\x1b\[[0-9;]*m', '', test_output)
    if not re.search(r'Tests\s+{} passed'.format(EXPECTED_TEST_COUNT), plain_test_output):
        raise RuntimeError('Expected {} passing frontend tests; inspect log before packaging'.format(EXPECTED_TEST_COUNT))
    run(['npm', 'run', 'build'], build_project, logs / 'frontend-build.log')
    if run(['git', 'status', '--porcelain', '--untracked-files=no'], repo).strip():
        raise RuntimeError('Build/test changed tracked checkout files')

    # The registration fix intentionally changes the old parity-build hashes.
    # Record exact hashes from this pinned commit's build instead of old evidence.
    build_assets = {}
    for filename in ASSETS:
        info = file_info(build_project / 'dist' / filename)
        build_assets[filename] = info

    stage = output / 'stage' / SLUG
    stage.mkdir(parents=True, exist_ok=False)
    for entry in reference['files']:
        relative = safe_path(entry['path'])
        destination = stage / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(str(source / relative), str(destination))
    for filename in ASSETS:
        shutil.copyfile(str(build_project / 'dist' / filename), str(stage / 'app/dist' / filename))
    override_source = source / OVERRIDE_PATH
    if not override_source.is_file() or override_source.is_symlink():
        raise RuntimeError('Pinned commit lacks the service-worker delivery override')
    shutil.copyfile(str(override_source), str(stage / OVERRIDE_DESTINATION))
    override_provenance = {
        'source_path': OVERRIDE_PATH,
        'staged_path': OVERRIDE_DESTINATION,
        'source_commit': commit,
        'original_reference_sha256': file_info(source / OVERRIDE_DESTINATION)['sha256'],
        'override_sha256': file_info(override_source)['sha256'],
    }
    if file_info(stage / OVERRIDE_DESTINATION)['sha256'] != override_provenance['override_sha256']:
        raise RuntimeError('Staged override differs from the pinned source')

    main_file = stage / (SLUG + '.php')
    replace_once(main_file, r'(^ \* Version:\s+)0\.4\.8(?=\s*$)', r'\g<1>' + NEW_VERSION)
    replace_once(main_file, r"(define\( 'TC_ESTIMATE_VERSION', ')0\.4\.8('\s*\);)", r'\g<1>' + NEW_VERSION + r'\2')
    replace_once(stage / 'readme.txt', r'(^Stable tag: )0\.4\.8$', r'\g<1>' + NEW_VERSION)
    worker = stage / 'app/dist/service-worker.js'
    worker_versions = re.findall(r'const VERSION = "([^"]+)";', worker.read_text())
    if len(worker_versions) != 1 or worker_versions[0] not in (OLD_VERSION, NEW_VERSION):
        raise RuntimeError('Unexpected built worker version')
    built_worker_version = worker_versions[0]
    if built_worker_version != NEW_VERSION:
        replace_once(worker, r'(const VERSION = ")0\.4\.8(";)', r'\g<1>' + NEW_VERSION + r'\2')
    if main_file.read_bytes().replace(NEW_VERSION.encode(), OLD_VERSION.encode()) != (source / (SLUG + '.php')).read_bytes():
        raise RuntimeError('PHP changed beyond intended version metadata')
    if (stage / 'readme.txt').read_bytes().replace(('Stable tag: ' + NEW_VERSION).encode(), ('Stable tag: ' + OLD_VERSION).encode()) != (source / 'readme.txt').read_bytes():
        raise RuntimeError('Readme changed beyond stable tag')
    if worker.read_bytes().replace(('const VERSION = "' + NEW_VERSION + '";').encode(), ('const VERSION = "' + built_worker_version + '";').encode()) != (build_project / 'dist/service-worker.js').read_bytes():
        raise RuntimeError('Worker changed beyond cache version')
    for filename in ('estimate-builder.js', 'estimate-builder.css', 'estimate-builder.js.map'):
        if file_info(stage / 'app/dist' / filename) != build_assets[filename]:
            raise RuntimeError('Tested build asset was altered while staging: ' + filename)
    js = (stage / 'app/dist/estimate-builder.js').read_text()
    source_map = json.loads((stage / 'app/dist/estimate-builder.js.map').read_text())
    if 'sourceMappingURL=estimate-builder.js.map' not in js or source_map.get('file') != 'estimate-builder.js':
        raise RuntimeError('Source map and exact tested JavaScript do not match')
    run(['node', '--check', str(stage / 'app/dist/estimate-builder.js')], build_project, logs / 'candidate-js-syntax.log')
    run(['node', '--check', str(worker)], build_project, logs / 'candidate-worker-syntax.log')
    php_executable = shutil.which('php')
    php_files = sorted(stage.rglob('*.php'))
    php_syntax = {'status': 'not-run: native PHP unavailable; verify separately', 'files': len(php_files)}
    if php_executable:
        php_output = []
        for php_file in php_files:
            php_output.append(run([php_executable, '-l', str(php_file)], build_project))
        (logs / 'candidate-php-syntax.log').write_text(''.join(php_output), encoding='utf-8')
        php_syntax['status'] = 'passed'
        php_syntax['runtime'] = run([php_executable, '--version'], build_project).splitlines()[0]

    staged_asset_info = {filename: file_info(stage / 'app/dist' / filename) for filename in ASSETS}
    source_timestamp = run(['git', 'show', '-s', '--format=%ct', commit], repo).strip()
    provenance = {
        'schema_version': 1,
        'status': 'prepared-not-installed',
        'release_version': NEW_VERSION,
        'release_version_provisional': True,
        'source_commit': commit,
        'source_commit_url': 'https://github.com/pretendingtocode7/Temp-Control-Estimator/commit/' + commit,
        'original_installer_sha256': ORIGINAL_SHA,
        'original_runtime_allowlist_files': 45,
        'node_version': node_version,
        'npm_version': npm_version,
        'node_platform_architecture': node_architecture,
        'dependency_lock_sha256': file_info(lock_path)['sha256'],
        'dependency_versions_verified': len(verified_dependencies),
        'build_mode': 'Exact clean pinned checkout; previously clean-installed locked dependencies; only ignored build/test outputs written',
        'build_assets_before_version_patch': build_assets,
        'staged_build_assets': staged_asset_info,
        'runtime_override': override_provenance,
        'packaging_tool_sha256': file_info(source / PACKAGING_TOOL_PATH)['sha256'],
        'offline_validation': {'original_manifest_files': 45, 'frontend_tests_passed': EXPECTED_TEST_COUNT, 'javascript_syntax': 'passed', 'php_syntax': php_syntax, 'commands': ['npm run verify:reference', 'npm test', 'npm run build'], 'build_assets': 'Exact output of pinned commit; registration deliberately differs from older parity build', 'browser_or_live_checks': 'Not run by this packaging tool'},
        'intentional_version_changes': ['Plugin header Version: 0.4.8 -> ' + NEW_VERSION, 'TC_ESTIMATE_VERSION: 0.4.8 -> ' + NEW_VERSION, 'readme Stable tag: 0.4.8 -> ' + NEW_VERSION, 'Worker VERSION/cache suffix: ' + built_worker_version + ' -> ' + NEW_VERSION],
        'source_map_policy': 'Exact built candidate JavaScript and its matching source map are included without stripping.',
        'unverified_conditions': ['Installed live version and source divergence', 'Live vendor/ dependencies; none included in original allowlist', 'Settings/data/plugin backup and rollback availability', 'WordPress/theme/provider/email/cron staging behavior'],
        'installation_or_live_backup_performed': False,
    }
    write_json(stage / 'build-provenance.json', provenance)

    allowed = set(entry['path'] for entry in reference['files']) | {'app/dist/estimate-builder.js.map', 'build-provenance.json'}
    actual = {str(path.relative_to(stage)) for path in stage.rglob('*') if path.is_file()}
    if actual != allowed or len(actual) != 47:
        raise RuntimeError('Staged runtime allowlist/count mismatch')
    forbidden_components = {'node_modules', 'tests', 'screenshots', '.git', '.env', 'wp-config.php'}
    patterns = {
        'private_key': rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----',
        'github_token': rb'\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})',
        'aws_access_key': rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b',
        'google_api_key': rb'\bAIza[A-Za-z0-9_-]{35}\b',
        'zoho_refresh_token': rb'\b1000\.[a-f0-9]{32}\.[a-f0-9]{32}\b',
    }
    findings = []
    for relative in sorted(actual):
        safe_path(relative)
        path = stage / relative
        if path.is_symlink() or forbidden_components.intersection(Path(relative).parts) or relative.endswith('.zip'):
            raise RuntimeError('Forbidden staged file: ' + relative)
        path.chmod(0o644)
        data = path.read_bytes()
        for category, pattern in patterns.items():
            if re.search(pattern, data):
                findings.append({'path': relative, 'category': category})
    write_json(logs / 'credential-pattern-scan.json', {'findings_redacted': findings, 'files_scanned': len(actual)})
    if findings:
        raise RuntimeError('Credential-pattern findings require review; no values printed')

    archive_path = output / ('temp-control-estimate-builder-v' + NEW_VERSION + '-prepared.zip')
    timestamp = datetime.fromtimestamp(int(source_timestamp), timezone.utc).timetuple()[:6]
    with zipfile.ZipFile(str(archive_path), 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        for relative in sorted(actual):
            info = zipfile.ZipInfo(SLUG + '/' + relative, date_time=timestamp)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(info, (stage / relative).read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    with zipfile.ZipFile(str(archive_path)) as package:
        if package.testzip() is not None or len(package.infolist()) != 47:
            raise RuntimeError('Candidate ZIP CRC/count failure')
        for entry in package.infolist():
            safe_path(entry.filename)
            if entry.flag_bits & 1 or stat.S_ISLNK(entry.external_attr >> 16):
                raise RuntimeError('Unsafe candidate ZIP entry')
            relative = entry.filename[len(SLUG) + 1:]
            if not entry.filename.startswith(SLUG + '/') or relative not in allowed or package.read(entry) != (stage / relative).read_bytes():
                raise RuntimeError('Candidate ZIP/root/content mismatch')

    external = {
        'schema_version': 1,
        'status': 'prepared-not-installed',
        'release_version_provisional': NEW_VERSION,
        'source_commit': commit,
        'original_installer_sha256': ORIGINAL_SHA,
        'zip': dict({'path': archive_path.name}, **file_info(archive_path)),
        'staged_file_count': len(actual),
        'php_file_count': sum(relative.endswith('.php') for relative in actual),
        'files': [dict({'path': SLUG + '/' + relative}, **file_info(stage / relative)) for relative in sorted(actual)],
        'runtime_override': override_provenance,
        'checks': {'original_manifest': 'passed', 'frontend_tests_passed': EXPECTED_TEST_COUNT, 'pinned_commit_build_hashes_recorded': 'passed', 'staged_assets_match_build': 'passed', 'runtime_override_matches_pinned_commit': 'passed', 'only_intended_version_deltas': 'passed', 'node_js_syntax': 'passed', 'node_worker_syntax': 'passed', 'php_syntax': php_syntax, 'zip_crc_path_type_allowlist_content': 'passed', 'credential_patterns': 'no findings'},
        'reproducibility': {'fixed_zip_timestamp': list(timestamp), 'python': sys.version.split()[0], 'zlib': zlib.ZLIB_VERSION, 'packaging_tool_sha256': file_info(Path(__file__))['sha256']},
        'conditions': provenance['unverified_conditions'],
    }
    write_json(output / 'release-candidate-manifest.json', external)
    print(json.dumps({'status': external['status'], 'release_version_provisional': NEW_VERSION, 'stage': str(stage), 'zip': str(archive_path), 'zip_sha256': external['zip']['sha256'], 'zip_size_bytes': external['zip']['size_bytes'], 'files': len(actual), 'php_files': external['php_file_count'], 'tests_passed': EXPECTED_TEST_COUNT, 'external_manifest': str(output / 'release-candidate-manifest.json')}, indent=2))


if __name__ == '__main__':
    main()
