#!/usr/bin/env python3
"""Deterministic explicit-allowlist source packages; never includes runtime or secrets."""
from pathlib import Path
import hashlib
import json
import zipfile

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'dist'
OUT.mkdir(exist_ok=True)
DENY = {'.git', '.runtime', 'node_modules', 'vendor', '__pycache__', '.env'}

def add(archive, file, name):
    if any(p in DENY for p in file.relative_to(ROOT).parts) or file.is_symlink():
        raise RuntimeError(f'Forbidden file: {file}')
    info = zipfile.ZipInfo(str(name), date_time=(2026, 1, 1, 0, 0, 0))
    info.compress_type = zipfile.ZIP_DEFLATED
    info.external_attr = 0o644 << 16
    archive.writestr(info, file.read_bytes())

def product(name, source):
    files = sorted((ROOT / source).rglob('*'))
    if not any(f.is_file() for f in files):
        raise RuntimeError(f'Missing product: {source}')
    with zipfile.ZipFile(OUT / (name + '.zip'), 'w') as archive:
        for file in files:
            if file.is_file():
                add(archive, file, Path(name) / file.relative_to(ROOT / source))

product('ludora-booking', 'plugins/ludora-booking')
# The companion ZIP is offered voluntarily through the TGM workflow.
bundled = ROOT / 'themes/ludora/plugins/ludora-booking.zip'
bundled.parent.mkdir(parents=True, exist_ok=True)
bundled.write_bytes((OUT / 'ludora-booking.zip').read_bytes())
product('ludora', 'themes/ludora')
product('ludora-child', 'themes/ludora-child')

# Independent clean-site verification compares all installed files, including the bundled companion.
installed = {}
for directory in ('themes/ludora', 'themes/ludora-child', 'plugins/ludora-booking'):
    for file in sorted((ROOT / directory).rglob('*')):
        if file.is_file():
            installed['wp-content/' + str(file.relative_to(ROOT))] = hashlib.sha256(file.read_bytes()).hexdigest()
(ROOT / 'tests/artifacts/product-file-manifest.json').write_text(json.dumps(installed, indent=2) + '\n')

with zipfile.ZipFile(OUT / 'ludora-full-package.zip', 'w') as archive:
    for name in ('ludora.zip', 'ludora-child.zip', 'ludora-booking.zip'):
        add(archive, OUT / name, Path('ludora-package/installable') / name)
    for directory in ('docs', 'demo', 'docker', 'licenses', 'marketplace', 'themes', 'plugins', 'scripts', 'tests/domain', 'tests/integration', 'tests/browser', 'tests/woocommerce'):
        for file in sorted((ROOT / directory).rglob('*')):
            if file.is_file() and not any(part in DENY for part in file.relative_to(ROOT).parts) and file.suffix not in ('.log', '.zip'):
                add(archive, file, Path('ludora-package/source') / file.relative_to(ROOT))
    for file in sorted((ROOT / 'tests/artifacts').glob('*')):
        if file.is_file() and file.suffix in ('.txt', '.json') and file.name not in ('package-build.json', 'package-audit.json', 'final-reproducibility.json'):
            add(archive, file, Path('ludora-package/evidence') / file.name)
    for file in sorted((ROOT / 'docs/guides').glob('*')):
        if file.is_file(): add(archive, file, Path('ludora-package/documentation') / file.name)
    info = zipfile.ZipInfo('ludora-package/START-HERE.txt', date_time=(2026, 1, 1, 0, 0, 0))
    archive.writestr(info, 'Install the three ZIPs under installable/ through WordPress. Start with documentation/index.html. Developer source lives under source/. Read source/PROGRESS.md and source/docs/test-report.md for actual verification and commercial gates. No account, payment or marketplace approval is included.\n')
    for name in ('README.md', 'LICENSE', 'NOTICE.md', 'PROJECT_SPEC.md', 'PROGRESS.md', 'PLAN.md', 'AGENTS.md', 'compose.yaml', 'compose.clean.yaml', 'docker-images.lock.json', '.env.example', 'package.json', 'package-lock.json', 'composer.json', 'composer.lock', 'phpcs.xml', 'playwright.config.js'):
        file = ROOT / name
        if file.is_file(): add(archive, file, Path('ludora-package/source') / name)

manifest = {file.name: {'bytes': file.stat().st_size, 'sha256': hashlib.sha256(file.read_bytes()).hexdigest()} for file in sorted(OUT.glob('*.zip'))}
(OUT / 'checksums.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(json.dumps(manifest, indent=2))
