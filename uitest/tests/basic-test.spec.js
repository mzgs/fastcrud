const { test, expect } = require('@playwright/test');

const TARGET_URL = 'http://localhost:8000/basic.php';

/**
 * Navigates to the PHP demo page, verifies the table renders, and fails if console errors occur.
 */
test('basic', async ({ page }) => {
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

  await page.goto(TARGET_URL, { waitUntil: 'networkidle' });

  const tableLocator = page.locator('table');
  const tableCount = await tableLocator.count();
  expect(tableCount, 'Expected at least one table element on the page').toBeGreaterThan(0);

  const headerCount = await page.locator('table th').count();
  expect(headerCount, 'Expected the table to render at least one header cell').toBeGreaterThan(0);

  expect(consoleErrors, formatConsoleErrors(consoleErrors)).toEqual([]);
  expect(pageErrors, formatPageErrors(pageErrors)).toEqual([]);
});

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
