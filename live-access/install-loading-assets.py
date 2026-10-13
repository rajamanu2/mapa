"""Install the reviewed MAPA startup splash into an explicitly selected console.

Only the boot-loader element and mapa-loading asset tags/files are changed.
Runtime configuration, authentication code and native bundles are untouched.
"""
import argparse
import hashlib
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
PUBLIC = ROOT / 'fleetbase/console/public'
SOURCE_INDEX = ROOT / 'fleetbase/console/app/index.html'


def boot_bounds(html):
    starts = list(re.finditer(r'<div\b[^>]*\bid=["\']boot-loader["\'][^>]*>', html))
    if len(starts) != 1:
        raise ValueError('Expected exactly one native boot-loader; refusing a broad HTML edit.')
    start = starts[0].start()
    depth = 0
    for match in re.finditer(r'<div\b[^>]*>|</div\s*>', html[start:], re.I):
        depth += -1 if match.group().startswith('</') else 1
        if depth == 0:
            return start, start + match.end()
    raise ValueError('Native boot-loader markup is unbalanced.')


def prepare(html):
    source = SOURCE_INDEX.read_text(encoding='utf-8')
    begin, end = boot_bounds(source)
    markup = source[begin:end]
    begin, end = boot_bounds(html)
    html = html[:begin] + markup + html[end:]
    assets = {name: (PUBLIC / name).read_bytes() for name in ['mapa-loading.css', 'mapa-loading.js', 'images/asr-health-icon.svg']}
    version = hashlib.sha256(b'\0'.join(assets.values()) + markup.encode()).hexdigest()[:12]
    # Remove only this feature's tags so repeated installs remain identical.
    html = re.sub(r'\s*<link\b[^>]*href=["\'][^"\']*mapa-loading\.css(?:\?[^"\']*)?["\'][^>]*>\s*', '\n', html)
    html = re.sub(r'\s*<script\b[^>]*src=["\'][^"\']*mapa-loading\.js(?:\?[^"\']*)?["\'][^>]*>\s*</script>\s*', '\n', html)
    if html.count('</head>') != 1:
        raise ValueError('Expected one document head.')
    tags = (f'    <link rel="stylesheet" href="/mapa-loading.css?v={version}">\n'
            f'    <script defer src="/mapa-loading.js?v={version}"></script>\n')
    html = re.sub(r'\s*</head>', '\n' + tags + '  </head>', html, count=1)
    return html, assets, version


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', required=True, type=Path)
    args = parser.parse_args()
    console = args.console_root.resolve(strict=True)
    index = console / 'index.html'
    if not console.is_dir() or not index.is_file():
        raise ValueError('Select an exported console directory containing index.html.')
    html, assets, version = prepare(index.read_text(encoding='utf-8'))
    for name, content in assets.items():
        (console / name).write_bytes(content)
    index.write_text(html, encoding='utf-8', newline='\n')
    print(f'Installed ASR Health startup splash; MAPA software identity retained; cache version {version}.')


if __name__ == '__main__':
    main()
