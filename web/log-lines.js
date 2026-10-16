// Only the exact ASKA/Unity message observed during a successful start is grouped.
export const shaderMessage = 'Trying to access a shader but no shaders were included in the build because Dedicated Server Optimizations is enabled.';
export function logGroups(text) {
  const groups = [];
  for (const line of text.split(/\r?\n/)) {
    const stamp = line.match(/^(\d{4}-\d\d-\d\dT\S+)\s+/)?.[1] || null;
    const shader = (stamp ? line.slice(stamp.length).trimStart() : line) === shaderMessage;
    const previous = groups.at(-1);
    if (shader && previous?.shader) {
      previous.lines.push(line); previous.last = stamp;
    } else groups.push({shader, lines: [line], first: stamp, last: stamp});
  }
  return groups;
}

// Labels describe explicit words in the log, never a guessed severity for unknown lines.
export function lineLevel(line) {
  if (/\b(?:[\w.]*Exception|ERROR|Error|FATAL|Fatal)(?:[:\s\]]|$)/.test(line)) return 'error';
  if (/\b(?:WARN|WARNING|Warning)(?:[:\s\]]|$)/.test(line)) return 'warning';
  return 'unknown';
}
export function filteredGroups(text, query = '', level = 'all') {
  const search = query.toLocaleLowerCase();
  return logGroups(text).flatMap(group => {
    const lines = group.lines.filter(line => line.toLocaleLowerCase().includes(search) && (level === 'all' || lineLevel(line) === level));
    return lines.length ? logGroups(lines.join('\n')) : [];
  });
}
