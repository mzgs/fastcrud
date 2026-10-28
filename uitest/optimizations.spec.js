const { test, expect } = require('@playwright/test');
const { spawn } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');

// Use an installed browser when the Playwright browser bundle is unavailable.
test.use({ launchOptions: { executablePath: process.env.FASTCRUD_BROWSER }, ignoreHTTPSErrors: true });
let server;
let baseUrl;

test.beforeAll(async () => {
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    baseUrl = `http://127.0.0.1:${port}/`;
    server = spawn('php', ['-S', `127.0.0.1:${port}`, path.resolve(__dirname, '../tests/fixtures/optimization-page.php')], { stdio: 'ignore' });
    await expect.poll(async () => {
        try { return (await fetch(baseUrl)).status; } catch { return 0; }
    }).toBe(200);
});

test.afterAll(() => { if (server) { server.kill(); } });

test('runtime is shared and cacheable; relation fields search and retain off-page selections', async ({ page, request }) => {
    const errors = [];
    const runtimes = [];
    const choices = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', req => {
        if (req.url().includes('action=runtime')) { runtimes.push(req.url()); }
        if ((req.postData() || '').includes('action=relation_options')) { choices.push(req.postData()); }
    });
    await page.goto(baseUrl);
    await expect(page.locator('table[data-table="items"]').first().locator('tbody')).toContainText('Person 1201');
    await expect(page.locator('table[data-table="items"]').nth(1).locator('tbody')).toContainText('Second');
    expect(runtimes).toHaveLength(1);
    expect(choices).toHaveLength(0);
    const runtime = await request.get(runtimes[0]);
    expect(runtime.headers()['cache-control']).toContain('immutable');
    const cached = await request.get(runtimes[0], { headers: { 'If-None-Match': runtime.headers().etag } });
    expect(cached.status()).toBe(304);
    await page.locator('table[data-table="items"]').first().locator('.fastcrud-nested-toggle').first().click();
    await expect(page.locator('table[data-table="items"]')).toHaveCount(3);
    await expect(page.locator('.fastcrud-nested-body table tbody')).toContainText('Second');
    expect(runtimes).toHaveLength(1);

    await page.locator('table[data-table="items"]').first().locator('.fastcrud-edit-btn').first().click();
    const owner = page.locator('select[data-fastcrud-field="owner"]').first();
    await expect(owner).toHaveValue('1201');
    await expect(owner.locator('option:checked')).toHaveText('Person 1201');
    const reviewers = page.locator('select[data-fastcrud-field="reviewers"]').first();
    await expect(reviewers).toHaveValues(['1', '1201']);
    await expect(reviewers.locator('option:checked')).toHaveText(['Person 1', 'Person 1201']);
    await owner.locator('xpath=following-sibling::*[contains(@class,"select2-container")]').click();
    await page.locator('.select2-container--open .select2-search__field').fill('Person 999');
    await expect(page.locator('.select2-results__option').filter({ hasText: /^Person 999$/ })).toBeVisible();
    expect(choices.some(data => data.includes('search=Person+999'))).toBe(true);
    expect(errors).toEqual([]);
});

test('create forms, inline editors, and query-builder filters use paged relation choices', async ({ page }) => {
    await page.goto(baseUrl + '?create=1');
    const owner = page.locator('select[data-fastcrud-field="owner"]').first();
    await expect(owner.locator('xpath=following-sibling::*[contains(@class,"select2-container")]')).toBeVisible();
    await owner.locator('xpath=following-sibling::*[contains(@class,"select2-container")]').click();
    await expect(page.locator('.select2-results__option').filter({ hasText: /^Person 1$/ })).toBeVisible();

    await page.goto(baseUrl);
    const table = page.locator('table[data-table="items"]').first();
    await expect(table.locator('tbody')).toContainText('Person 1201');
    await table.locator('tbody tr').first().locator('[data-fastcrud-column="owner"]').dblclick();
    const inline = page.locator('select.fastcrud-inline-input');
    await expect(inline).toHaveValue('1201');
    await expect(inline.locator('option:checked')).toHaveText('Person 1201');
    await page.goto(baseUrl);
    await expect(page.locator('.fastcrud-add-btn').first()).toBeVisible();
    await page.getByRole('button', { name: 'Filters', exact: false }).first().click();
    await page.getByRole('button', { name: 'Add Filter', exact: true }).click();
    const row = page.locator('.fastcrud-qb-filter-row').first();
    await row.locator('select').nth(0).selectOption('owner');
    await row.locator('select').nth(1).selectOption('equals');
    const relation = row.locator('select[data-fastcrud-relation="owner"]');
    await expect(relation.locator('xpath=following-sibling::*[contains(@class,"select2-container")]')).toBeVisible();
    await relation.locator('xpath=following-sibling::*[contains(@class,"select2-container")]').click();
    await page.locator('.select2-results__option').filter({ hasText: /^Person 1$/ }).click();
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(page.locator('table[data-table="items"]').first().locator('tbody')).toContainText('Second');
    await expect(page.locator('table[data-table="items"]').first().locator('tbody')).not.toContainText('First');
});

test('CSV endpoint downloads only selected rows', async ({ request }) => {
    const response = await request.post(baseUrl + '?buffered=1', { form: {
        fastcrud_ajax: '1', action: 'export_csv', table: 'items', 'primary_key_values[]': '2'
    } });
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');
    expect(response.headers()['content-disposition']).toContain('attachment');
    const csv = await response.text();
    expect(csv).toContain('Second');
    expect(csv).not.toContain('First');
});
