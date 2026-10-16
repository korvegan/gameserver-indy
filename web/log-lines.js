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
