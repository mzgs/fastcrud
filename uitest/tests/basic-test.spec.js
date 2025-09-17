const { test, expect } = require('@playwright/test');

const TARGET_URL = 'http://localhost:8000/basic.php';

/**
 * Navigates to the PHP demo page, verifies the table renders cleanly, and fails on console, page, or server-side PHP errors.
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
