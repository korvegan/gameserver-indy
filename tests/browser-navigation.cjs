// Exercise the same overview/detail navigation and mobile drawer as a user.
exports.openNavigation = async page => {
  // CSS selects the current mode before the resize listener transfers the navigation.
  if (await page.locator('#navigation-toggle').isVisible() && !await page.locator('#mobile-navigation').isVisible()) await page.locator('#navigation-toggle').click();
  await page.locator('.sidebar').waitFor({state:'visible'});
};
exports.selectServer = async (page, id) => {
  if (!await page.locator('#fleet').isVisible()) await exports.navigation(page)('Serverübersicht').click();
  await page.locator('#world-list [data-select-server="' + id + '"]').click();
};
exports.navigation = page => name => ({async click() {
  if (['Serverübersicht', 'Serverzentrale'].includes(name)) {
    await exports.openNavigation(page);
    return page.locator('.sidebar nav [data-view="fleet"]').click();
  }
  const tabs = {Übersicht: 'Übersicht', Einstellungen: 'Einstellungen', Serverlog: 'Protokoll & Aufträge', Weltsicherungen: 'Sicherungen', Updates: 'Updates', Discord: 'Mitteilungen', WhatsApp: 'Mitteilungen'};
  if (!tabs[name]) {
    await exports.openNavigation(page);
    return page.locator('.sidebar').getByRole('button', {name, exact: true}).click();
  }
  if (!await page.locator('#server-workspace').isVisible()) await exports.selectServer(page, await page.locator('#server-select').inputValue());
  await page.getByRole('tab', {name: tabs[name], exact: true}).click();
  if (name === 'Discord' || name === 'WhatsApp') await page.locator('#message-channels').getByRole('button', {name, exact: true}).click();
}});
