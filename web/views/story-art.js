// Landscape v1 is a durable visual vocabulary. Add a new version rather than
// changing its placement/seed when future games or illustration styles arrive.
const NS = 'http://www.w3.org/2000/svg';
export const WIDTH = 1080, HEIGHT = 710;
export function svg(tag, attrs = {}, text) {
  const node = document.createElementNS(NS, tag);
  for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, value);
  if (text !== undefined) node.textContent = text;
  return node;
}
const path = (d, fill, stroke = 'none', width = 1) => svg('path', {d, fill, stroke, 'stroke-width': width, 'stroke-linejoin': 'round'});
const circle = (cx, cy, r, fill, stroke = 'none') => svg('circle', {cx, cy, r, fill, stroke});
const line = (x1, y1, x2, y2, stroke, width = 2) => svg('line', {x1, y1, x2, y2, stroke, 'stroke-width': width});
const words = (x, y, text, size = 14, fill = '#34493d') => svg('text', {x, y, fill, 'font-size': size, 'font-family': 'Georgia, serif', 'text-anchor': 'middle'}, text);
const short = (text, length) => text.length > length ? text.slice(0, length - 1) + '…' : text;

function pine(x, y, scale = 1) {
  const group = svg('g', {transform: `translate(${x} ${y}) scale(${scale})`});
  group.append(path('M-3 0 L3 0 L2 -45 L-2 -45 Z', '#79623e'),
    path('M0 -78 L-22 -39 L-12 -42 L-29 -17 L-15 -21 L-34 -2 Q0 7 34 -2 L15 -21 L29 -17 L12 -42 L22 -39 Z', '#375d4c', '#294e40'),
    path('M0 -69 L-13 -42 L-6 -43 L-21 -19 L-10 -23 L-24 -6 L-3 0 Z', '#507557'));
  return group;
}
function hut(x, y, scale = 1) {
  const group = svg('g', {transform: `translate(${x} ${y}) scale(${scale})`});
  group.append(path('M-33 -12 L33 -12 L30 27 L-30 27 Z', '#b58b55', '#64583b', 2),
    path('M-49 -8 L-8 -58 L49 -8 L17 -14 L-10 -46 L-33 -12 Z', '#796746', '#514f37', 2),
    path('M-10 27 L-10 2 Q0 -13 10 2 L10 27 Z', '#3b4b3c'),
    line(-28, 5, -15, 5, '#e0ba75', 5), line(16, 5, 27, 5, '#e0ba75', 5),
    path('M-41 -10 L-9 -48 M-30 -9 L-9 -40 M-19 -8 L-8 -30 M-5 -46 L34 -9', 'none', '#ac925d', 2));
  return group;
}
function banner(x, y) {
  const group = svg('g', {transform: `translate(${x} ${y})`});
  group.append(line(0, 17, 0, -91, '#514f36', 3), circle(0, -94, 4, '#c08b44'),
    path('M2 -88 Q28 -102 52 -84 L52 -40 Q27 -57 2 -44 Z', '#803e3b', '#e4bd75', 2),
    path('M28 -84 L22 -65 L31 -69 L25 -51 L39 -73 L30 -69 Z', '#edd09a'));
  return group;
}
function people(count) {
  const group = svg('g', {'aria-hidden': 'true'});
  for (let i = 0; i < Math.min(count, 8); i++) {
    const x = 138 + i * 12 - Math.min(count, 8) * 6, y = 166 + (i % 2) * 3;
    group.append(path(`M${x - 4} ${y} Q${x - 7} ${y - 18} ${x} ${y - 18} Q${x + 7} ${y - 18} ${x + 4} ${y} Z`, i % 2 ? '#614c58' : '#58684b'),
      circle(x, y - 23, 4, '#c49b71'), line(x - 2, y, x - 3, y + 7, '#414b3b'), line(x + 2, y, x + 3, y + 7, '#414b3b'));
  }
  return group;
}

export function landscape(chapter) {
  const root = svg('svg', {viewBox: `0 0 ${WIDTH} ${HEIGHT}`, xmlns: NS, role: 'group', 'aria-label': `Geschichtsbild, Kapitel ${chapter + 1}`, tabindex: '0', class: 'tapestry-canvas'});
  root.append(svg('title', {}, `Sturmklingen · Kapitel ${chapter + 1}`), svg('desc', {}, 'Illustrierte Landschaft. Sechs feste Plätze für gemeinsame Erinnerungen. Szenen mit Tab auswählen und mit Enter öffnen.'));
  root.append(svg('rect', {x: 0, y: 0, width: WIDTH, height: HEIGHT, rx: 14, fill: '#d6ccaa'}),
    path('M12 12 H1068 V698 H12 Z', 'none', '#84724f', 2),
    path('M22 22 H1058 V688 H22 Z', 'none', '#a19068'),
    circle(540, 53, 23, '#384e41', '#a88a51'),
    path('M545 33 L531 55 L541 53 L534 71 L552 47 L542 50 Z', '#e6c27e'),
    words(325, 60, 'S T U R M K L I N G E N', 19), words(755, 60, `K A P I T E L  ${chapter + 1}`, 17));
  root.append(path('M25 231 Q140 178 252 217 T510 208 T770 219 T1055 194 V380 H25 Z', '#b3b99b'),
    path('M25 275 Q158 197 300 270 T570 255 T850 263 T1055 250 V398 H25 Z', '#9eab88'),
    path('M25 330 Q145 271 273 316 T565 305 T830 327 T1055 303 V397 H25 Z', '#8e9f7c'),
    path('M25 502 Q155 454 276 487 T545 503 T810 464 T1055 494 V673 H25 Z', '#b3b99b'),
    path('M25 568 Q182 506 320 555 T605 542 T860 564 T1055 538 V673 H25 Z', '#9eab88'),
    path('M25 641 Q179 564 301 610 T600 621 T843 617 T1055 595 V674 H25 Z', '#8e9f7c'),
    path('M25 351 Q170 310 280 342 T535 332 T800 349 T1055 331 M25 638 Q160 596 290 629 T560 621 T805 637 T1055 610', 'none', '#d3c49a', 8));
  for (let slot = 0; slot < 6; slot++) {
    const x = 30 + slot % 3 * 340, y = 103 + Math.floor(slot / 3) * 285;
    const empty = svg('g', {transform: `translate(${x} ${y})`, 'data-empty-slot': slot});
    empty.append(circle(160, 105, 22, 'none', '#a29470'), words(160, 112, '✦', 20, '#a29470'), words(160, 236, 'Hier wächst eure Geschichte.', 13, '#647052'));
    root.append(empty);
  }
  root.append(words(540, 687, 'GEMEINSAM ERLEBT · GEMEINSAM BEWAHRT', 10, '#576446'));
  return root;
}

export function illustration(event, gameLabel) {
  const {scene} = event, x = 30 + scene.slot % 3 * 340, y = 103 + Math.floor(scene.slot / 3) * 285;
  const node = svg('a', {id: scene.id, href: '#moment-' + event.id, tabindex: '0', transform: `translate(${x} ${y})`,
    class: 'tapestry-scene', 'data-event-id': event.id, 'data-revision': event.revision,
    'aria-label': `${event.title} · ${gameLabel} · ${event.member_ids.length} gemeinsame Auszeichnungen. Erinnerung öffnen.`});
  node.append(svg('title', {}, `${event.title} · ${event.member_ids.length} gemeinsame Auszeichnungen`),
    svg('rect', {x: 0, y: 0, width: 320, height: 266, rx: 12, fill: '#fff', 'fill-opacity': 0.001, class: 'scene-focus-frame', stroke: 'transparent', 'stroke-width': 3}));
  const art = svg('g', {class: 'scene-art', 'aria-hidden': 'true'});
  const region = scene.blueprint;
  if (region === 'landscape-v1-mountains') {
    art.append(path('M5 139 L79 17 L114 68 L159 0 L221 92 L259 46 L315 139 Z', '#778b83', '#586e68', 2),
      path('M48 67 L79 17 L100 50 L80 42 L67 61 L61 52 Z M123 52 L159 0 L191 46 L169 34 L156 47 L145 34 Z M238 80 L259 46 L280 79 L261 67 Z', '#e0dfca'),
      path('M5 128 Q80 102 156 126 T315 117 L315 149 H5 Z', '#b8c7b0'),
      path('M10 143 Q90 121 170 144 T315 132', 'none', '#ced4b9', 8),
      path('M187 143 V82 H215 V143 Z M181 83 V73 H189 V79 H196 V73 H204 V79 H211 V73 H220 V83 Z', '#6f7a63', '#52624f', 2),
      path('M197 143 V119 Q203 107 209 119 V143', '#384f43'));
    art.append(pine(55, 167, .62), pine(282, 160, .75));
  } else if (region === 'landscape-v1-coast') {
    art.append(path('M5 94 Q78 62 165 100 T315 89 V178 H5 Z', '#759d9b'),
      path('M7 116 Q38 104 69 116 T132 116 T195 116 T258 116 T315 116 M7 143 Q38 131 69 143 T132 143 T195 143 T258 143 T315 143', 'none', '#b5c7ae', 3),
      path('M187 130 L280 130 L263 149 H207 Z', '#806c46', '#4d5e4d', 2), line(233, 133, 233, 42, '#4f5d49', 3),
      path('M230 48 L197 116 H230 Z M238 56 Q268 89 273 116 H238 Z', '#e8d9ab', '#9b8f66', 2),
      path('M6 176 Q94 132 165 178 T315 177 V202 H6 Z', '#c8bd91'), pine(46, 160, .75));
  } else if (region === 'landscape-v1-woodland') {
    art.append(pine(36, 154, 1.2), pine(95, 131, 1.45), pine(267, 147, 1.6), pine(308, 166, 1.05),
      hut(210, 139, .9), hut(137, 116, .56));
  } else {
    art.append(pine(49, 152, 1.2), pine(281, 145, 1.1), hut(220, 139, .82),
      path('M55 173 L81 127 L109 173 Z', '#c2a976', '#6d7351', 2), path('M72 172 L82 148 L93 172 Z', '#5e6950'));
  }
  const variant = scene.position % 3;
  art.append(banner(148 + variant * 8, 134), people(event.member_ids.length),
    path('M15 191 Q160 170 305 191 L300 248 Q160 233 20 248 Z', '#e0d5af', '#aa9569'),
    circle(38, 212, 15, '#475f49', '#b4965c'), words(38, 218, '✦', 19, '#ead19c'),
    words(177, 206, short(event.title, 31), 16),
    words(177, 226, short(`${gameLabel}${event.campaign_label ? ' · ' + event.campaign_label : ''}`, 41), 11, '#596747'),
    words(160, 264, `${event.member_ids.length} Gefährten · ${new Date(event.occurred_at || event.recorded_at).toLocaleDateString('de-DE')}`, 11, '#455a43'));
  node.append(art);
  return node;
}
