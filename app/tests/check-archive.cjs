const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const archive = path.join(root, 'archive/php-legacy-20261022');
const metadata = JSON.parse(fs.readFileSync(path.join(archive, 'source.json'), 'utf8'));
assert.equal(metadata.commit, '5b4e29ba0e0e0ce667a2e3bd9a605b9c283057cb');
const rows = execFileSync('git', ['ls-tree', '-rz', metadata.commit], { cwd: root }).toString().split('\0').filter(Boolean);
assert.equal(rows.length, metadata.files);
const expected = new Set();
for (const row of rows) {
  const [entry, name] = row.split('\t');
  const [mode, kind, oid] = entry.split(' ');
  assert.equal(kind, 'blob');
  assert.ok(['100644', '100755'].includes(mode));
  const file = path.join(archive, 'snapshot', name);
  const info = fs.lstatSync(file);
  assert.ok(info.isFile() && !info.isSymbolicLink(), name);
  const data = fs.readFileSync(file);
  const actual = crypto.createHash('sha1').update(`blob ${data.length}\0`).update(data).digest('hex');
  assert.equal(actual, oid, name);
  assert.equal(Boolean(info.mode & 0o111), mode === '100755', name);
  expected.add(name);
}
function walk(directory, relative = '') {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const name = relative ? `${relative}/${entry.name}` : entry.name;
    if (entry.isDirectory()) walk(path.join(directory, entry.name), name);
    else assert.ok(expected.delete(name), `Unexpected archived file: ${name}`);
  }
}
walk(path.join(archive, 'snapshot'));
assert.equal(expected.size, 0);
console.log(`PASS: all ${metadata.files} historical Git blobs and executable modes preserved; no additional files`);
