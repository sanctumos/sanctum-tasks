# PHP test benchmark (production surface)

Target for **sanctum-tasks** `public/` PHP (the layer currently shipped to production):

| Layer | Target | How we measure it |
| ----- | ------ | ----------------- |
| **Unit** | **≥ 90%** line coverage on `public/includes`, `public/api`, `public/admin` | PHPUnit `--coverage-text` (or HTML) with **PCOV** enabled. Scope is only PHP under those directories (see `phpunit.xml.dist` `<source>`). |
| **Integration** | **≥ 90%** line coverage on the same directories | PHPUnit **Integration** suite (HTTP + Guzzle). **Note:** code executed inside the **`php -S` worker** is a separate process; PCOV on the test runner mainly reflects includes run in the runner (e.g. bootstrap). Treat **integration tests as behavioral/regression coverage**; drive **line %** toward 90% with **Unit** tests plus DB/API-path tests that call `public/includes` directly in-process. |
| **End-to-end (major workflows)** | **≥ 90%** of **documented critical workflows** exercised in a browser | Not the same as line %: maintain a **checklist of workflows** (login, task CRUD, admin settings, API smoke, etc.) and require **≥ 90%** of those scenarios to have an automated **Playwright** path (e.g. `tools/design-smoke/`). Track pass/fail per scenario in CI or release checklist. |

## Commands

```bash
composer install
composer run test:php:unit
composer run test:php:integration
composer run test:php:e2e
```

Coverage (PCOV):

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --testsuite unit --coverage-text \
  --coverage-filter=public/includes --coverage-filter=public/api --coverage-filter=public/admin
```

Line coverage for **unit + integration** test paths (avoids duplicating tests when the default config runs multiple suites):

```bash
composer run test:php:coverage
# equivalent:
php -d pcov.enabled=1 vendor/bin/phpunit tests/php/Unit tests/php/Integration --coverage-text \
  --coverage-filter=public/includes --coverage-filter=public/api --coverage-filter=public/admin
```

## E2E / Playwright

Browser verification for UI changes is required before calling visual work “done” (see workspace design-verification rule). For **workflow coverage %**, treat each major flow as a binary: covered or not. Expand `tools/design-smoke/` (or a dedicated Playwright project) until **≥ 90%** of the documented workflow list is automated.

## CI

GitHub Actions runs PHP syntax lint, Composer install, and PHPUnit (unit + integration). Keep **composer.lock** committed so CI and production-like installs stay aligned.
