// Check every shipped module, including future feature views.
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

function files(directory) {
  return fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
    const filename = path.join(directory, entry.name);
    return entry.isDirectory() ? files(filename) : filename.endsWith('.js') ? [filename] : [];
  });
}

const modules = files(path.resolve(__dirname, '../web'));
for (const filename of modules) {
  const result = spawnSync(process.execPath, ['--check', filename], {encoding: 'utf8'});
  if (result.status !== 0) {
    console.error(result.stderr || result.error || `Syntaxprüfung fehlgeschlagen: ${filename}`);
    process.exit(1);
  }
}
console.log(`JavaScript syntax passed: ${modules.length} modules.`);
