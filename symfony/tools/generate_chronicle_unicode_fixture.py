"""Regenerate the pinned identity evidence with the original Python Unicode version."""
import hashlib
import json
from pathlib import Path
import struct
import unicodedata

if unicodedata.unidata_version != '16.0.0':
    raise RuntimeError('Use the original Unicode 16 Python runtime; do not silently change durable identities')
singletons, sequences = hashlib.sha256(), hashlib.sha256()
combining = []


def add(target, value):
    data = unicodedata.normalize('NFKC', value).casefold().encode('utf-8')
    target.update(struct.pack('>I', len(data)))
    target.update(data)


for point in range(0x110000):
    if 0xD800 <= point <= 0xDFFF:
        continue
    character = chr(point)
    add(singletons, character)
    if unicodedata.combining(character):
        combining.append(point)
        add(sequences, 'A\u0301' + character + '\u0327')
output = Path(__file__).resolve().parents[1] / 'tests/fixtures/chronicle-unicode-16.json'
output.parent.mkdir(exist_ok=True)
output.write_text(json.dumps({'unicode_version': unicodedata.unidata_version, 'singletons_sha256': singletons.hexdigest(),
    'combining_sha256': sequences.hexdigest(), 'combining_codepoints': combining}, indent=2) + '\n', encoding='utf-8')
print('Pinned Unicode 16 identity evidence regenerated.')
