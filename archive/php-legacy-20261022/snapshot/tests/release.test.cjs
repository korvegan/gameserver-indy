const {test} = require('node:test');
const assert = require('node:assert/strict');
const {activate} = require('../scripts/release.cjs');

function fixture(failNew = false, timeout = false) {
  const calls = []; let code = 'old';
  return {calls, ops: {
    snapshot: () => ({game: 6740, state: 'same'}), idle: () => calls.push('idle'),
    stop: target => calls.push('stop:' + target),
    install: target => {code = target; calls.push('install:' + target);},
    launch: () => {calls.push('launch:' + code); if (timeout) throw Error('caller timeout');},
    ready: target => {calls.push('ready:' + target); if (failNew && target === 'new') throw Error('not ready');}
  }};
}
test('release switch preserves the baseline and verifies the selected code', async () => {
  const {calls, ops} = fixture();
  assert.deepEqual(await activate(ops, 'old', 'new'), {activated: true, rolledBack: false, code: 'new'});
  assert.deepEqual(calls, ['idle', 'stop:old', 'install:new', 'launch:new', 'ready:new']);
});
test('startup failure switches back and verifies the previous PHP release', async () => {
  const {calls, ops} = fixture(true);
  assert.deepEqual(await activate(ops, 'old', 'new'), {activated: false, rolledBack: true, code: 'old'});
  assert.deepEqual(calls.slice(-4), ['stop:new', 'install:old', 'launch:old', 'ready:old']);
});
test('caller timeout accepts an actually ready release', async () => {
  const {ops} = fixture(false, true);
  assert.equal((await activate(ops, 'old', 'new')).activated, true);
});
