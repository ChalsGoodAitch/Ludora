#!/usr/bin/env python3
"""Build a complete Spanish PO from POT and reviewed source dictionaries.

No translation is invented and no English fallback is used. Missing/empty entries
fail before replacing the catalog. Context/plural additions fail explicitly until
a reviewed dictionary schema for them is supplied.
"""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / 'plugins/ludora-booking/languages'
DOMAIN = 'ludora-booking'


def field(block, name):
    """Read a gettext quoted field, including multiline continuations."""
    match = re.search(r'^' + re.escape(name) + r' (".*")(?:\n(?="))?', block, re.M)
    if not match:
        return None
    value = json.loads(match.group(1))
    rest = block[match.end():]
    for line in rest.splitlines():
        if not line.startswith('"'):
            break
        value += json.loads(line)
    return value


def main():
    translations = {}
    for name in ('es_ES.json', 'config-es_ES.json'):
        source = json.loads((BASE / name).read_text())
        if not isinstance(source, dict):
            raise SystemExit(f'{name}: expected a translation object')
        for key, value in source.items():
            if not isinstance(value, str) or not value.strip():
                raise SystemExit(f'{name}: empty/invalid translation for {key!r}')
            if key in translations and translations[key] != value:
                raise SystemExit(f'Conflicting reviewed translations for {key!r}')
            translations[key] = value
    pot = (BASE / f'{DOMAIN}.pot').read_text()
    blocks = pot.strip().split('\n\n')
    missing = []
    output = []
    count = 0
    for block in blocks:
        if re.search(r'^msgctxt |^msgid_plural ', block, re.M):
            raise SystemExit('Context/plural source requires an explicit reviewed dictionary schema.')
        msgid = field(block, 'msgid')
        if msgid:
            count += 1
            if msgid not in translations:
                missing.append(msgid)
                continue
            translation = json.dumps(translations[msgid], ensure_ascii=False)
            block = re.sub(r'^msgstr ""$', lambda _: 'msgstr ' + translation, block, flags=re.M)
        else:
            block = re.sub(r'^"Language-Team:.*$', lambda _: '"Language-Team: Spanish\\n"\n"Language: es_ES\\n"\n"Plural-Forms: nplurals=2; plural=(n != 1);\\n"', block, flags=re.M)
            block = re.sub(r'^"PO-Revision-Date:.*$', lambda _: '"PO-Revision-Date: 2026-11-15 00:00+0000\\n"', block, flags=re.M)
            block = re.sub(r'^"Last-Translator:.*$', lambda _: '"Last-Translator: Ludora contributors\\n"', block, flags=re.M)
        output.append(block)
    if missing:
        raise SystemExit('Missing Spanish translations:\n' + '\n'.join(missing))
    target = BASE / f'{DOMAIN}-es_ES.po'
    target.write_text('\n\n'.join(output) + '\n')
    print(f'{DOMAIN}: {count}/{count} source strings translated; {len(translations)} reviewed dictionary entries.')


if __name__ == '__main__':
    main()
