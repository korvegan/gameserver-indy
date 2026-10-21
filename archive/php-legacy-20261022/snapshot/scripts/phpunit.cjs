// Small CLI wrapper: regular family cases by default, quiet diagnostics until needed.
const {spawnSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..'), input=process.argv.slice(2);
const extended=input.includes('--extended'), verbose=input.includes('--verbose');
const args=input.filter(x=>!['--extended','--verbose'].includes(x));
const result=spawnSync(process.env.SLICE_PHP || 'php',[
  'symfony/vendor/bin/phpunit','-c','symfony/phpunit.xml',...(extended?[]:['--group','regular']),...args,
],{cwd:root,encoding:'utf8',maxBuffer:8*1024*1024,env:{...process.env,SHELL_VERBOSITY:verbose?'3':'-1'}});
process.stdout.write(result.stdout || '');
if(result.stderr){
  fs.mkdirSync(path.join(root,'artifacts'),{recursive:true});
  fs.writeFileSync(path.join(root,'artifacts/phpunit-stderr.log'),result.stderr);
  if(verbose || result.status!==0)process.stderr.write(result.stderr);
}
if(result.error)console.error(result.error.message);
process.exitCode=result.status ?? 1;
