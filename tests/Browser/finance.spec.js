import { test, expect } from '@playwright/test';

async function login(page) {
  await page.goto('/login');
  await page.locator('#email').fill('demo@gridmidia.local');
  await page.locator('#password').fill('Grid@2026!');
  await page.getByRole('button', { name: 'Acessar workspace' }).click();
  await expect(page.getByRole('button', { name: 'Sair da conta' })).toBeVisible();
}

test('authentication rejects wrong password and logout protects private routes', async ({ page }) => {
  await page.goto('/despesas');
  await expect(page).toHaveURL(/\/login$/);
  await page.locator('#email').fill('demo@gridmidia.local');
  await page.locator('#password').fill('wrong');
  await page.getByRole('button', { name: 'Acessar workspace' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await login(page);
  await page.getByRole('button', { name: 'Sair da conta' }).click();
  await page.goto('/unidades');
  await expect(page).toHaveURL(/\/login$/);
});

test('BRL creation uses live split validation and closes every cent', async ({ page }) => {
  await login(page);
  await page.goto('/despesas/nova');
  await page.locator('#description').fill('Browser exact cents');
  await page.locator('#supplier').fill('Browser supplier');
  await page.locator('#amount').fill('100,01');
  const first = page.locator('[data-unit]').first();
  await first.selectOption({ index: 1 });
  await page.locator('[data-percentage]').first().fill('101');
  await expect(page.locator('[data-percentage]').first()).toHaveValue('100');
  await page.locator('[data-percentage]').first().fill('50');
  await page.locator('[data-add-allocation]').click();
  await page.locator('[data-unit]').nth(1).selectOption({ index: 2 });
  await page.locator('[data-percentage]').nth(1).fill('49');
  await expect(page.locator('[data-allocation-total]')).toContainText('99');
  await page.locator('[data-percentage]').nth(1).fill('50');
  await expect(page.locator('[data-allocation-total]')).toContainText('100');
  await page.getByRole('button', { name: 'Registrar despesa' }).click();
  await expect(page).toHaveURL(/\/despesas\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Browser exact cents' })).toBeVisible();
  await expect(page.locator('main')).toContainText('50,01');
  await expect(page.locator('main')).toContainText('50,00');
});

test('mobile navigation opens, closes on Escape and fits viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  const menu = page.getByRole('button', { name: 'Abrir menu' });
  await menu.click();
  await expect(menu).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('laptop navigation fits without scroll and units have one creation entry', async ({ page }) => {
  await login(page);
  for (const viewport of [{ width: 1366, height: 768 }, { width: 1024, height: 640 }]) {
    await page.setViewportSize(viewport);
    await expect.poll(() => page.locator('#sidebar').evaluate(e => e.scrollWidth <= e.clientWidth && e.scrollHeight <= e.clientHeight)).toBe(true);
    await expect(page.getByRole('button', { name: 'Sair da conta' })).toBeInViewport();
  }
  await page.goto('/unidades');
  await expect(page.locator('a[href="#new-unit"]')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Cadastrar unidade' })).toBeVisible();
});

test('units can be created and edited through their dialog', async ({ page }) => {
  await login(page);
  await page.goto('/unidades');
  await page.locator('#name').fill('Browser unit');
  await page.locator('#company').fill('Browser company');
  await page.locator('#slug').fill('browser-unit');
  await page.getByRole('button', { name: 'Cadastrar unidade' }).click();
  const card = page.locator('.unit-card').filter({ has: page.getByRole('heading', { name: 'Browser unit', exact: true }) });
  await card.getByRole('button', { name: 'Editar', exact: true }).click();
  const dialog = page.locator('dialog[open]');
  await dialog.locator('input[name=name]').fill('Browser unit edited');
  await dialog.getByRole('button', { name: 'Salvar altera\u00e7\u00f5es' }).click();
  await expect(page.getByRole('heading', { name: 'Browser unit edited' })).toBeVisible();
});

test('report period and CSV export preserve the recorded cents', async ({ page }) => {
  await login(page);
  await page.goto('/despesas/nova');
  await page.locator('#description').fill('Browser report');
  await page.locator('#supplier').fill('Browser supplier');
  await page.locator('#date').fill('2025-02-01');
  await page.locator('#amount').fill('16,01');
  await page.locator('[data-unit]').first().selectOption({ index: 1 });
  await page.getByRole('button', { name: 'Registrar despesa' }).click();
  await expect(page).toHaveURL(/\/despesas\/\d+$/);
  await page.goto('/relatorios?from=2025-02-01&to=2025-02-01');
  await expect(page.locator('.report-total-value')).toContainText('16,01');
  const downloading = page.waitForEvent('download');
  await page.locator('a[href*="/relatorios/exportar"]').click();
  const download = await downloading;
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  expect(Buffer.concat(chunks).toString('utf8')).toContain('16,01');
});

test('USD is saved before conversion without contributing to consolidated BRL', async ({ page }) => {
  await login(page);
  await page.goto('/despesas/nova');
  await page.locator('#description').fill('Browser pending USD');
  await page.locator('#supplier').fill('International supplier');
  await page.locator('#date').fill('2025-02-01');
  await page.locator('#currency').selectOption('USD');
  await page.locator('#amount').fill('15,01');
  await page.locator('[data-unit]').first().selectOption({ index: 1 });
  await page.getByRole('button', { name: 'Registrar despesa' }).click();
  await expect(page).toHaveURL(/\/despesas\/\d+$/);
  await expect(page.locator('main')).toContainText('15,01');
  await expect(page.locator('.status-pending')).toBeVisible();
  await page.goto('/relatorios?from=2025-02-01&to=2025-02-01');
  await expect(page.locator('main')).toContainText('0,00');
});
