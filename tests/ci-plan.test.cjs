const {test}=require('node:test');
const assert=require('node:assert/strict');
const {plan,changedFiles}=require('../.github/ci-plan.cjs');

test('UI changes select dashboard/API and browser checks without native or legacy matrix',()=>{
  const p=plan(['web/app.js','web/serverzentrale.css','symfony/src/Http/DashboardController.php']);
  assert.equal(p.symfony,true); assert.equal(p.ui,true); assert.equal(p.dashboard_only,true);
  assert.equal(p.native,false); assert.deepEqual(JSON.parse(p.symfony_os),['ubuntu-latest']);
});
test('process and backup changes retain Windows checks',()=>{
  for(const file of ['symfony/src/Runtime/RuntimeSupervisor.php','symfony/src/Backup/WorldBackups.php','symfony/src/Job/ExecuteServerJobHandler.php','scripts/windows-server.ps1']){
    const p=plan([file]);assert.equal(p.native,true,file);assert.equal(p.symfony,true,file);
    assert.equal(p.dashboard_only,false);assert.ok(JSON.parse(p.symfony_os).includes('windows-latest'));
  }
});
test('docs skip runtime checks, and mixed changes retain required checks',()=>{
  assert.equal(plan(['AGENTS.md','skills/gameserver-indy/SKILL.md']).symfony,false);
  assert.equal(plan(['docs/testing.md','symfony/src/Access/AccessService.php']).symfony,true);
  assert.equal(plan(['archive/python/old.py']).symfony,false);
  assert.equal(plan(['tests/test_manager.py']).symfony,false);
});
test('shared tooling, unknown files and scheduled/manual runs select the full matrix',()=>{
  for(const files of [['.github/workflows/checks.yml'],['package-lock.json'],['new-runtime.bin'],[]]){
    const p=plan(files);assert.equal(p.full,true);assert.equal(p.native,true);
  }
  for(const event of ['schedule','workflow_dispatch'])assert.equal(plan(['docs/testing.md'],event).full,true);
});
test('Git diff includes deleted/renamed paths and unavailable revisions select full checks',()=>{
  const sha='a'.repeat(40), event={pull_request:{base:{sha}}};
  assert.deepEqual(changedFiles(event,'pull_request',args=>{
    assert.deepEqual(args,['diff','--name-only','--no-renames','-z',sha,'HEAD']);
    return 'scripts/windows-server.ps1\0docs/removed.md\0';
  }),['scripts/windows-server.ps1','docs/removed.md']);
  assert.equal(plan(changedFiles(event,'pull_request',()=>{throw new Error('missing base');})).full,true);
});
