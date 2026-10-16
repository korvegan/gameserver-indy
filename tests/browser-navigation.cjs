// Navigate the production UI, including server tabs and message channel selection.
exports.navigation = page => name => ({async click() {
  if (name === 'Übersicht' || name === 'Serverzentrale') return page.locator('.sidebar nav [data-view="overview"]').click();
  const tabs = {Einstellungen: 'Einstellungen', Serverlog: 'Protokoll & Aufträge', Weltsicherungen: 'Sicherungen', Updates: 'Updates', Discord: 'Mitteilungen', WhatsApp: 'Mitteilungen'};
  if (!tabs[name]) return page.getByRole('button', {name, exact: true}).click();
  if (!await page.locator('#server-workspace').isVisible()) await page.locator('.sidebar nav [data-view="overview"]').click();
  await page.getByRole('tab', {name: tabs[name], exact: true}).click();
  if (name === 'Discord' || name === 'WhatsApp') await page.locator('#message-channels').getByRole('button', {name, exact: true}).click();
}});
