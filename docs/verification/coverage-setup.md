# Coverage setup

PWT enforces **≥ 80% line coverage** via `php artisan test --coverage --min=80`. The gate runs in CI on every push / PR — see `.github/workflows/tests.yml`.

## CI (always on)

The workflow installs **PCOV** via `shivammathur/setup-php@v2` (`coverage: pcov`) on Ubuntu, spins up a MySQL 8.0 service for the `pwt_testing` integration suites, and runs the full suite with coverage. The build fails if coverage drops below 80%.

Coverage reports are uploaded as artifacts on every run:
- `coverage-html-php8.2` — browsable HTML report (`coverage/html/index.html`).
- `coverage-clover-php8.2` — `coverage/clover.xml` for downstream tooling (Codecov, SonarQube, etc.).

Excluded from coverage (`phpunit.xml` `<source><exclude>`):
- `app/OpenApi/**` — doc-only schema classes, no executable code.
- `app/Mail/**` — thin Laravel Mailables; covered indirectly by listener tests.
- `app/Http/Kernel.php`, `app/Console/Kernel.php` — framework wiring.

## Local

The committed local PHP install (XAMPP on Windows in this workspace) does **not** ship with a coverage driver. To enable coverage locally:

### Option A — PCOV (recommended; fastest)

Windows + XAMPP (PHP 8.2 TS x64):
1. Download the matching DLL from <https://windows.php.net/downloads/pecl/releases/pcov/> — pick the `8.2 TS x64` build.
2. Extract `php_pcov.dll` into `C:\xampp\php\ext\`.
3. Append to `C:\xampp\php\php.ini`:
   ```ini
   [PCOV]
   extension=pcov
   pcov.enabled=1
   pcov.directory=app
   pcov.exclude="~tests|database|config|OpenApi~"
   ```
4. Verify: `php -m | findstr pcov` should print `pcov`.
5. Run: `php artisan test --coverage --min=80`.

Linux / macOS:
```sh
pecl install pcov
echo "extension=pcov" | sudo tee -a $(php --ini | grep "Loaded Configuration" | awk '{print $4}')
```

### Option B — Xdebug 3

Heavier than PCOV (~3× slower under coverage) but supports branch + path coverage. Only worth it if you need those reports.

```ini
[xdebug]
zend_extension=xdebug
xdebug.mode=coverage
```

Then run with `XDEBUG_MODE=coverage php artisan test --coverage --min=80`.

> **Never** ship Xdebug with `xdebug.mode=develop,debug` in production — startup cost is significant.

## Running coverage locally

```powershell
# Full coverage with 80% gate
php artisan test --coverage --min=80

# Coverage without gate (for exploration)
php artisan test --coverage

# Coverage scoped to one module
php artisan test --coverage --filter='Orders'
```

Reports land under `./coverage/` (already gitignored).

## What the gate does

PHPUnit aggregates line coverage across **everything in `<source><include>`** minus excludes. The 80% floor is project-wide — it does NOT mean every file is at 80%. To inspect per-file figures, open `coverage/html/index.html` after a run.

Per `CLAUDE.md` § 10, services and policies should sit at **100%** coverage. The gate doesn't enforce that today; consider tightening to `--min=85` once stable, or split per-directory thresholds via a custom tool.
