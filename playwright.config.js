const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: './tests/browser', timeout: 45000, workers: 1,
  use: { baseURL: process.env.LUDORA_TEST_URL || 'http://localhost:8095', browserName: 'chromium', headless: true },
  reporter: [['list'], ['json', { outputFile: 'tests/artifacts/playwright-results.json' }]],
  outputDir: 'tests/artifacts/browser'
});
