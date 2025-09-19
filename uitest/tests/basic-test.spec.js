const { test, expect } = require('@playwright/test');

const TARGET_URL = 'http://localhost:8000/basic.php';

/**
 * Navigates to the PHP demo page, verifies the table renders cleanly, and fails on console, page, or server-side PHP errors.
 */
test('basic', async ({ page }) => {
  const { consoleErrors, pageErrors } = setupErrorTracking(page);

  const response = await page.goto(TARGET_URL, { waitUntil: 'networkidle' });
  expect(response && response.ok(), 'Expected a successful HTTP response').toBeTruthy();

  const html = await page.content();
  const serverErrors = findServerErrorMarkers(html);
  expect(serverErrors, formatServerErrors(serverErrors)).toEqual([]);

  const tableLocator = page.locator('table');
  const tableCount = await tableLocator.count();
  expect(tableCount, 'Expected at least one table element on the page').toBeGreaterThan(0);

  const headerCount = await page.locator('table th').count();
  expect(headerCount, 'Expected the table to render at least one header cell').toBeGreaterThan(0);

  expect(consoleErrors, formatConsoleErrors(consoleErrors)).toEqual([]);
  expect(pageErrors, formatPageErrors(pageErrors)).toEqual([]);
});

/**
 * Opens the edit panel, edits one field, saves, and confirms the change persisted without surfacing errors.
 */
test('edit record workflow', async ({ page }) => {
  const { consoleErrors, pageErrors } = setupErrorTracking(page);

  const response = await page.goto(TARGET_URL, { waitUntil: 'networkidle' });
  expect(response && response.ok(), 'Expected a successful HTTP response').toBeTruthy();

  const table = page.locator('table').first();
  await expect(table, 'Expected at least one rendered table').toBeVisible();

  await page.waitForSelector('button.fastcrud-edit-btn', { timeout: 10000 });

  const tableId = await table.getAttribute('id');
  expect(tableId, 'Expected table to have an id attribute').toBeTruthy();

  const editButton = page.locator(`#${tableId} button.fastcrud-edit-btn`).first();
  await editButton.click();

  const offcanvasSelector = `#${tableId}-edit-panel`;
  const offcanvasLocator = page.locator(offcanvasSelector);
  await expect(offcanvasLocator, 'Expected edit panel to slide in').toHaveClass(/show/, { timeout: 5000 });

  const defaultInput = page.locator(`${offcanvasSelector} [data-fastcrud-field]`).first();
  await expect(defaultInput, 'Expected editable fields inside the offcanvas form').toBeVisible();

  let targetInput = page.locator(`${offcanvasSelector} [data-fastcrud-field="title"]`).first();
  if ((await targetInput.count()) === 0) {
    targetInput = defaultInput;
  }

  const fieldName = await targetInput.getAttribute('data-fastcrud-field');
  expect(fieldName, 'Expected edit field to expose its column name').toBeTruthy();

  const originalValue = await targetInput.inputValue();
  const suffix = ' (Edited)';
  const newValue = originalValue.endsWith(suffix)
    ? originalValue.slice(0, -suffix.length)
    : `${originalValue}${suffix}`;
  await targetInput.fill(newValue);

  const saveButton = page.locator(`${offcanvasSelector} button[type="submit"]`);
  await saveButton.click();

  const successAlert = page.locator(`#${tableId}-edit-success`);
  await expect(successAlert, 'Expected success alert after saving changes').toBeVisible({ timeout: 5000 });

  await expect(offcanvasLocator, 'Expected edit panel to close after saving').not.toHaveClass(/show/, {
    timeout: 5000,
  });

  const headers = (await table.locator('thead th').allInnerTexts()).map((text) => text.trim());
  const columnLabel = toTitleCase(fieldName);
  const columnIndex = headers.findIndex((text) => text === columnLabel);
  expect(columnIndex, `Expected to locate column header for ${columnLabel}`).toBeGreaterThan(-1);

  const firstRowCell = table.locator('tbody tr').first().locator('td').nth(columnIndex);
  await expect(firstRowCell, 'Expected table to reflect edited value').toHaveText(newValue, {
    timeout: 5000,
  });

  const html = await page.content();
  const serverErrors = findServerErrorMarkers(html);
  expect(serverErrors, formatServerErrors(serverErrors)).toEqual([]);

  expect(consoleErrors, formatConsoleErrors(consoleErrors)).toEqual([]);
  expect(pageErrors, formatPageErrors(pageErrors)).toEqual([]);
});

/**
 * Verifies search and pagination metadata are exposed and drive AJAX requests.
 */
test('filtering and pagination controls', async ({ page }) => {
  const { consoleErrors, pageErrors } = setupErrorTracking(page);

  const response = await page.goto(TARGET_URL, { waitUntil: 'networkidle' });
  expect(response && response.ok(), 'Expected a successful HTTP response').toBeTruthy();

  await page.waitForFunction(() => {
    if (!window.FastCrudTables) {
      return false;
    }
    var ids = Object.keys(window.FastCrudTables);
    if (!ids.length) {
      return false;
    }
    for (var index = 0; index < ids.length; index++) {
      var table = window.FastCrudTables[ids[index]];
      if (!table || typeof table.getMeta !== 'function') {
        continue;
      }
      var meta = table.getMeta();
      if (meta && meta.search && Array.isArray(meta.search.columns)) {
        return true;
      }
    }
    return false;
  }, null, { timeout: 20000 });

  const table = page.locator('table').first();
  const tableId = await table.getAttribute('id');
  expect(tableId, 'Expected rendered table to expose its id').toBeTruthy();

  await page.waitForFunction((id) => {
    if (!id || !window.FastCrudTables || !window.FastCrudTables[id]) {
      return false;
    }
    var meta = window.FastCrudTables[id].getMeta();
    return !!(meta && meta.search && Array.isArray(meta.search.columns));
  }, tableId, { timeout: 20000 });

  const meta = await page.evaluate((id) => {
    return window.FastCrudTables && window.FastCrudTables[id]
      ? window.FastCrudTables[id].getMeta()
      : null;
  }, tableId);

  expect(meta, 'Expected FastCrudTables registry to expose metadata').toBeTruthy();
  expect(Array.isArray(meta.limit_options), 'Expected limit options array').toBeTruthy();
  expect(meta.limit_options).toContain('all');
  expect(meta.search && Array.isArray(meta.search.columns), 'Expected search columns metadata').toBeTruthy();
  expect(meta.search.columns.length, 'Expected at least one search column').toBeGreaterThan(0);

  const searchTerm = 'PlaywrightFilter';
  const [searchResponse] = await Promise.all([
    page.waitForResponse((resp) => {
      if (!resp.url().includes('fastcrud_ajax=1') || resp.request().method() !== 'GET') {
        return false;
      }
      const url = new URL(resp.url());
      return url.searchParams.get('search_term') === searchTerm;
    }),
    page.evaluate(({ id, term }) => {
      if (window.FastCrudTables && window.FastCrudTables[id]) {
        window.FastCrudTables[id].search(term);
      }
    }, { id: tableId, term: searchTerm }),
  ]);

  expect(searchResponse.status(), 'Expected search-triggered request to succeed').toBe(200);

  await Promise.all([
    page.waitForResponse((resp) => {
      if (!resp.url().includes('fastcrud_ajax=1') || resp.request().method() !== 'GET') {
        return false;
      }
      const url = new URL(resp.url());
      return url.searchParams.get('per_page') === '0';
    }),
    page.evaluate((id) => {
      if (window.FastCrudTables && window.FastCrudTables[id]) {
        window.FastCrudTables[id].setPerPage('all');
      }
    }, tableId),
  ]);

  const html = await page.content();
  const serverErrors = findServerErrorMarkers(html);
  expect(serverErrors, formatServerErrors(serverErrors)).toEqual([]);

  expect(consoleErrors, formatConsoleErrors(consoleErrors)).toEqual([]);
  expect(pageErrors, formatPageErrors(pageErrors)).toEqual([]);
});

function findServerErrorMarkers(html) {
  const markers = [
    { label: 'Fatal error', regex: /Fatal error/i },
    { label: 'Warning', regex: /Warning:/i },
    { label: 'Parse error', regex: /Parse error/i },
    { label: 'Notice', regex: /Notice:/i },
  ];

  return markers
    .map(({ label, regex }) => {
      const match = html.match(regex);
      if (!match) return null;
      return `${label} detected near: ${snippet(html, match.index)}`;
    })
    .filter(Boolean);
}

function snippet(html, index, radius = 60) {
  const start = Math.max(0, index - radius);
  const end = Math.min(html.length, index + radius);
  return html.slice(start, end).replace(/\s+/g, ' ').trim();
}

function formatServerErrors(errors) {
  if (!errors.length) return 'Expected no PHP errors in markup';
  return `Server-side error markers found (count=${errors.length}):\n${errors
    .map((message, index) => `  [${index + 1}] ${message}`)
    .join('\n')}`;
}

function formatConsoleErrors(errors) {
  if (!errors.length) return 'Expected no console errors';
  return `Console errors detected (count=${errors.length}):\n${errors
    .map((error, index) => `  [${index + 1}] ${error.text}\n      at ${error.url}:${error.line ?? '?'}:${error.column ?? '?'}`)
    .join('\n')}`;
}

function formatPageErrors(errors) {
  if (!errors.length) return 'Expected no page errors';
  return `Unhandled page errors detected (count=${errors.length}):\n${errors
    .map((error, index) => `  [${index + 1}] ${error.name}: ${error.message}`)
    .join('\n')}`;
}

function setupErrorTracking(page) {
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      const location = msg.location();
      consoleErrors.push({
        text: msg.text(),
        url: location?.url || 'n/a',
        line: location?.lineNumber,
        column: location?.columnNumber,
      });
    }
  });

  const pageErrors = [];
  page.on('pageerror', (error) => {
    pageErrors.push({
      name: error.name,
      message: error.message,
      stack: error.stack,
    });
  });

  return { consoleErrors, pageErrors };
}

function toTitleCase(value) {
  return (value || '')
    .split('_')
    .map((segment) => {
      if (!segment) return segment;
      return segment.charAt(0).toUpperCase() + segment.slice(1);
    })
    .join(' ')
    .trim();
}
