// No network calls or dependencies: classify the complete Git diff, including deleted paths.
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');

function plan(files, event = 'pull_request') {
  const result = {symfony:false, native:false, python:false, ui:false, full:false, dashboard_only:true};
  const reasons = new Set();
  const all = reason => { Object.assign(result,{symfony:true,native:true,python:true,ui:true,full:true,dashboard_only:false}); reasons.add(reason); };
  if (['schedule','workflow_dispatch'].includes(event) || !files.length) all('Complete scheduled/manual run or unavailable diff');
  for (const file of files) {
    if (/^(docs\/|skills\/)|\.md$/.test(file)) continue;
    if (/^(web\/|symfony\/templates\/|tests\/browser-[^/]+\.cjs$)/.test(file)
      || /^symfony\/(src\/Http\/DashboardController.php|tests\/DashboardTest.php)$/.test(file)) {
      result.symfony=true; result.ui=true; reasons.add('Dashboard and assets'); continue;
    }
    result.dashboard_only=false;
    if (/^symfony\/(src|tests|config)\//.test(file)) {
      result.symfony=true; result.ui=true; reasons.add('Symfony application');
      if (/^symfony\/src\/(Runtime|Backup|Storage|Command|Game|Instance|Job)\//.test(file)
        || /^symfony\/tests\/(Native|State|Release|WorldBackups|ManagedProcess)/.test(file)) {
        result.native=true; reasons.add('Native processes, storage or releases');
      }
    } else if (/^(gameserver_manager\/|tests\/test_[^/]+\.py$|profiles\/)/.test(file)) {
      result.python=true; reasons.add('Python compatibility or shared game profiles');
      if (/windows|backups|profiles\//.test(file)) { result.native=true; result.symfony=true; }
    } else if (/^scripts\/.*\.ps1$/.test(file)) {
      result.native=true; result.python=true; result.symfony=true; reasons.add('Windows launcher or bridge');
    } else { all('Shared infrastructure or unclassified path: '+file); }
  }
  result.symfony_os = JSON.stringify(result.native ? ['ubuntu-latest','windows-latest'] : ['ubuntu-latest']);
  result.reasons = [...reasons];
  return result;
}

function changedFiles(event, eventName, git = args => execFileSync('git',args,{encoding:'utf8'})) {
  if (['schedule','workflow_dispatch'].includes(eventName)) return [];
  const base = eventName === 'pull_request' ? event.pull_request?.base?.sha : event.before;
  if (!/^[a-f0-9]{40}$/.test(base || '') || /^0+$/.test(base)) return [];
  try {
    // Comparing the tested merge tree to the PR base also covers changes in the target branch.
    return git(['diff','--name-only','--no-renames','-z',base,'HEAD']).split('\0').filter(Boolean);
  } catch { return []; } // An unavailable base selects all checks, never an empty successful plan.
}

if (require.main === module) {
  const eventName=process.env.GITHUB_EVENT_NAME || 'workflow_dispatch';
  const event=process.env.GITHUB_EVENT_PATH ? JSON.parse(fs.readFileSync(process.env.GITHUB_EVENT_PATH,'utf8')) : {};
  const files=changedFiles(event,eventName), result=plan(files,eventName);
  const lines=Object.entries(result).filter(([key])=>key!=='reasons').map(([key,value])=>`${key}=${value}`).join('\n')+'\n';
  if (process.env.GITHUB_OUTPUT) fs.appendFileSync(process.env.GITHUB_OUTPUT,lines);
  if (process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY,
    '## Selected checks\n\n```json\n'+JSON.stringify(result,null,2)+'\n```\n\n'+files.length+' changed paths. Full matrix is also available nightly and manually.\n');
  console.log(JSON.stringify(result,null,2));
}
module.exports={plan,changedFiles};
