export const $ = selector => document.querySelector(selector);

export function notice(message, error = false) {
  const box = $('#notice');
  box.replaceChildren();
  if (message) {
    const text = document.createElement('span'); text.textContent = message;
    const close = document.createElement('button'); close.type = 'button'; close.className = 'notice-close';
    close.textContent = '×'; close.setAttribute('aria-label', 'Meldung schließen');
    close.addEventListener('click', () => { box.hidden = true; });
    box.append(text, close);
  }
  box.classList.toggle('error', error);
  box.hidden = !message;
}

export function element(tag, text, className) {
  const node = document.createElement(tag);
  if (text !== undefined) node.textContent = text;
  if (className) node.className = className;
  return node;
}
