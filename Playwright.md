# Playwright — Clearance Package Testing Guide

## Overview

Clearance è un Composer package. Per eseguire test browser devi installarlo in un **progetto host** Laravel con:
- `php artisan serve` attivo
- Playwright configurato nel host

---

## 1. Setup nel progetto host

### 1.1 Installa Playwright

```bash
# nella root del progetto host (non di clearance/)
npm install --save-dev @playwright/test
npx playwright install chromium
```

### 1.2 Configura `playwright.config.ts`

```ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/browser',
  use: {
    baseURL: 'http://127.0.0.1:8000',
    headless: true,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    command: 'php artisan serve',
    url: 'http://127.0.0.1:8000',
    reuseExistingServer: true,
  },
});
```

### 1.3 Struttura test

```
tests/
  browser/
    helpers/
      auth.ts
    clearance/
      permission-form.spec.ts
      user-permissions.spec.ts
```

---

## 2. Prerequisiti nel progetto host

### 2.1 Seeder utente admin

```php
// database/seeders/ClearanceTestSeeder.php
$user = User::factory()->create([
    'email'    => 'admin@test.com',
    'password' => bcrypt('password'),
]);
$user->givePermissionTo('clearance-access');
```

### 2.2 Helper login

```ts
// tests/browser/helpers/auth.ts
import { Page } from '@playwright/test';

export async function loginAsAdmin(page: Page) {
  await page.goto('/login');
  await page.fill('input[name="email"]', 'admin@test.com');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**');
}
```

---

## 3. Test PermissionForm — Custom Ability Pills

```ts
// tests/browser/clearance/permission-form.spec.ts
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../helpers/auth';

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/clearance/permissions');
});

test('aggiunge e rimuove custom ability senza errori JS', async ({ page }) => {
  const jsErrors: string[] = [];
  page.on('pageerror', err => jsErrors.push(err.message));

  await page.getByRole('button', { name: /new permission/i }).click();
  await page.waitForSelector('[wire\\:id]');
  await page.fill('input[wire\\:model="prefix"]', 'test');

  // Aggiungi custom ability
  await page.fill('input[list="clearance-ability-suggestions"]', 'export');
  await page.keyboard.press('Enter');
  await expect(page.locator('text=export')).toBeVisible();

  // Rimuovi — questo era il bug
  await page.locator('[data-flux-badge]')
    .filter({ hasText: 'export' })
    .locator('button')
    .click();

  await expect(page.locator('text=export')).not.toBeVisible();
  expect(jsErrors).toHaveLength(0);
});

test('rimuove primo di più custom abilities senza morph error', async ({ page }) => {
  const jsErrors: string[] = [];
  page.on('pageerror', err => jsErrors.push(err.message));

  await page.getByRole('button', { name: /new permission/i }).click();
  await page.fill('input[wire\\:model="prefix"]', 'multi');

  for (const ability of ['export', 'import', 'archive']) {
    await page.fill('input[list="clearance-ability-suggestions"]', ability);
    await page.keyboard.press('Enter');
    await expect(page.locator(`text=${ability}`)).toBeVisible();
  }

  // Rimuovi primo (index 0 → causa morph bug se non fixato)
  await page.locator('[data-flux-badge]')
    .filter({ hasText: 'export' })
    .locator('button')
    .first()
    .click();

  await expect(page.locator('text=export')).not.toBeVisible();
  await expect(page.locator('text=import')).toBeVisible();
  await expect(page.locator('text=archive')).toBeVisible();
  expect(jsErrors).toHaveLength(0);
});
```

---

## 4. Test UserPermissionManager

```ts
// tests/browser/clearance/user-permissions.spec.ts
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../helpers/auth';

test('assegna master role a utente', async ({ page }) => {
  const jsErrors: string[] = [];
  page.on('pageerror', err => jsErrors.push(err.message));

  await loginAsAdmin(page);
  await page.goto('/clearance/user/1');

  await page.selectOption('select[wire\\:model="selectedRoleId"]', { index: 1 });
  await page.getByRole('button', { name: /assign role/i }).click();

  await expect(page.locator('[data-flux-card]').first()).toBeVisible({ timeout: 5000 });
  expect(jsErrors).toHaveLength(0);
});

test('remove role modal apre e chiude', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/clearance/user/1');

  await page.getByRole('button', { name: /remove role/i }).first().click();
  await expect(page.locator('[data-flux-modal]')).toBeVisible();

  await page.getByRole('button', { name: /cancel/i }).click();
  await expect(page.locator('[data-flux-modal]')).not.toBeVisible();
});
```

---

## 5. Eseguire i test

```bash
# tutti i test browser
npx playwright test

# solo clearance
npx playwright test tests/browser/clearance/

# con UI Playwright (debug visivo)
npx playwright test --ui

# headed (vedi il browser)
npx playwright test --headed

# un test specifico in debug
npx playwright test permission-form --debug
```

---

## 6. MCP Playwright Tools (Claude Code)

Quando il server è attivo (`php artisan serve`), Claude Code può usare questi tool MCP **direttamente senza scrivere file `.spec.ts`**:

| Tool MCP | Uso |
|----------|-----|
| `browser_navigate` | Naviga a `/clearance/permissions` |
| `browser_snapshot` | Dump DOM accessibile (verifica stato) |
| `browser_take_screenshot` | Screenshot per debug visivo |
| `browser_click` | Clicca elemento per selector |
| `browser_fill` | Compila input |
| `browser_type` | Digita testo |
| `browser_press_key` | Enter / Escape / Tab |
| `browser_console_messages` | Leggi errori JS dalla console |
| `browser_wait_for` | Aspetta condizione/elemento |
| `browser_evaluate` | Esegui JS arbitrario nel browser |
| `browser_network_requests` | Monitora richieste Livewire XHR |
| `browser_handle_dialog` | Gestisci `wire:confirm` dialogs |

### Workflow tipico con MCP (interattivo)

```
1. php artisan serve           # nel terminale del progetto host
2. Dì a Claude: "avvia il server e testa il form permessi"
3. Claude usa:
   → browser_navigate('/clearance/permissions')
   → browser_click('button[name="new"]')
   → browser_fill('input[wire:model="prefix"]', 'orders')
   → browser_press_key('Enter')
   → browser_console_messages()   # verifica zero errori JS
   → browser_snapshot()           # verifica DOM risultante
```

---

## 7. Skills disponibili

```
/superpowers-laravel:laravel-playwright    # pattern Pest + Playwright per Laravel
/superpowers-laravel:e2e-playwright        # E2E specifico per Livewire
```

Attivano:
- Auth helpers preconfigurati
- Livewire component interaction patterns
- Flux UI selector strategy
- Screenshot automatici su fallimento

---

## 8. Selector Strategy per Flux UI

Flux renderizza web components con attributi custom. Selectors affidabili:

```ts
// Badge
page.locator('[data-flux-badge]')

// Badge close button (rendered come <button> dentro il badge)
page.locator('[data-flux-badge]').filter({ hasText: 'export' }).locator('button')

// Modal
page.locator('[data-flux-modal]')

// Card
page.locator('[data-flux-card]')

// Aspetta Livewire montato
await page.waitForSelector('[wire\\:id]')

// Input Livewire
page.locator('input[wire\\:model="prefix"]')

// Select Livewire
page.locator('select[wire\\:model="selectedRoleId"]')
```

---

## 9. Debug Livewire Morph Errors

Pattern per intercettare il bug `Cannot read properties of null (reading 'before')`:

```ts
test('nessun morph error', async ({ page }) => {
  const morphErrors: string[] = [];

  page.on('pageerror', err => {
    if (err.message.includes("reading 'before'") ||
        err.message.includes('morph') ||
        err.message.includes('Block.appendChild')) {
      morphErrors.push(err.message);
    }
  });

  // ... esegui operazione che prima causava il bug ...

  expect(morphErrors).toHaveLength(0);
});
```

---

## 10. Note

- **Non modificare** `clearance/` per i test browser — Playwright va nel progetto host
- I test PHP (`vendor/bin/pest`) restano nel package per la logica server-side
- Playwright testa: JS, Alpine.js, Flux UI, Livewire morph, interazioni reali
- Per CI/CD aggiungi `npx playwright install --with-deps chromium` prima di `npx playwright test`
