const {test} = require('node:test');
const assert = require('node:assert/strict');
test('known shader flood keeps neighboring start messages and exact raw lines', async () => {
  const {shaderMessage, logGroups} = await import('../web/log-lines.js');
  const warning = stamp => `${stamp} ${shaderMessage}`;
  const lines = ['Connected to Steam successfully', ...Array.from({length:250}, (_, i) => warning(`2026-10-15T14:59:38.${String(i).padStart(3,'0')}Z`)),
    'Built-in Resource Error: still visible', warning('2026-10-15T15:00:00Z'), 'The session is now open!'];
  const groups = logGroups(lines.join('\n'));
  assert.equal(groups.length, 5); assert.equal(groups[1].lines.length, 250);
  assert.equal(groups[1].first, '2026-10-15T14:59:38.000Z');
  assert.equal(groups[1].last, '2026-10-15T14:59:38.249Z');
  assert.deepEqual(groups.flatMap(group => group.lines), lines);
  assert.equal(groups[2].shader, false); assert.equal(groups[3].lines.length, 1);
});
test('search and explicit level filters retain unknown lines and original group boundaries', async () => {
  const {shaderMessage, filteredGroups, lineLevel} = await import('../web/log-lines.js');
  const text = `${shaderMessage}\nBuilt-in Resource Error: visible\n${shaderMessage}\nUnknown game message\nWarning: own fixture`;
  assert.equal(filteredGroups(text, 'shader').length, 2);
  assert.equal(filteredGroups(text, '', 'error')[0].lines[0], 'Built-in Resource Error: visible');
  assert.equal(filteredGroups(text, 'unknown', 'unknown')[0].lines[0], 'Unknown game message');
  assert.equal(lineLevel('Connected to Steam successfully'), 'unknown');
  assert.equal(filteredGroups(text, '', 'warning')[0].lines[0], 'Warning: own fixture');
});
