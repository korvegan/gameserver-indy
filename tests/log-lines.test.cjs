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
