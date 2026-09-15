# Static analysis & code quality

## PHPStan

PHPStan runs at **level 10 (`max`)**. Custom project rules live in `tests/PHPStan/Rules/` and enforce conventions (forbidden globals, forbidden direct instantiations, namespace rules).

Key principles:

- **Fix at the source, not the sink.** When PHPStan reports a type error, trace it back to where the wrong type was introduced. Do not suppress at the point where it manifests.
- **Narrow, don't cast.** When a value is `mixed` or a union, narrow with `is_string()`, `instanceof`, etc. Do not cast with `(string)`, `(int)` — casts silently coerce invalid data.
- **Avoid inline `@var` casts.** Each one should prompt: why does the type not match, and can the source be fixed?
- **Avoid baselines.** Never add new baseline entries — fix the underlying type error. When modifying a file, fix any existing baseline entries for that file.
- **Always run on the full codebase** and filter output for changed files. Never run on a subset — PHPStan's inference depends on full-codebase context.

### Array typing progression

Worst to best:

`array` → `array<K, V>` → `list<T>` → `non-empty-list<T>` → array shape → `@phpstan-type` alias → DTO

Convert shapes to DTOs when they exceed 3–4 keys or appear in multiple places.

## Commands

The same composer scripts back every PHP check, whether invoked in the container via `openemr-cmd` (no host toolchain needed) or directly on the host.

### In container (only requires Docker)

```bash
openemr-cmd code-quality                # alias: cq  -- full suite
openemr-cmd phpstan                     # alias: pst
openemr-cmd phpstan-generate            # alias: psg -- regenerate baseline
openemr-cmd phpstan-generate-reset      # alias: pgr -- wipe + regenerate from scratch
openemr-cmd psr12-report                # alias: pr  (composer phpcs)
openemr-cmd psr12-fix                   # alias: pf  (composer phpcbf)
openemr-cmd rector-dry-run              # alias: rd
openemr-cmd rector-process              # alias: rp  (apply changes)
openemr-cmd require-checker             # alias: crc
openemr-cmd composer-checks             # alias: cck (validate + normalize)
openemr-cmd codespell                   # alias: cps
openemr-cmd conventional-commits-check  # alias: ccc
openemr-cmd php-parserror               # alias: pp  (php -l)
openemr-cmd lint-javascript-report      # alias: ljr
openemr-cmd lint-themes-report          # alias: ltr
```

Target a worktree from outside it: `openemr-cmd worktree exec <branch> <cmd>`.

### On the host (requires PHP / Composer with `vendor/` / Node)

```bash
composer code-quality                # all PHP quality checks
composer phpstan                     # static analysis (level 10)
composer phpstan-baseline            # regenerate baseline
composer phpstan-baseline-reset      # wipe + regenerate from scratch
composer phpcs                       # style check
composer phpcbf                      # style auto-fix
composer rector-check                # modernization (dry-run)
composer rector-fix                  # modernization (apply)
composer require-checker             # undeclared dependencies
composer checks                      # validate + normalize composer.json
composer codespell                   # spell-check
composer conventional-commits:check  # commit message format
composer php-syntax-check            # php -l on all files

npm run lint:js           # ESLint check
npm run lint:js-fix       # ESLint auto-fix
npm run stylelint         # CSS/SCSS lint
```

## Build commands

```bash
npm run build             # production build (webpack + CSS sync)
npm run build:webpack     # theme compilation (caller provides --mode)
npm run build:webpack:prod
npm run build:webpack:dev
npm run build:sync        # sync static CSS to public/themes/
npm run dev               # dev theme build, CSS sync, then webpack watch
```

## Pre-commit hooks

Install with `openemr-cmd prek-install` (alias `pi`). This writes git hooks routing through the running openemr container, so `git commit` validates against the full `.pre-commit-config.yaml` suite (phpstan, rector, phpcs, codespell, actionlint, hadolint) **without** requiring PHP, Node, Python, codespell, actionlint, or hadolint on the host.

Manual passthrough: `openemr-cmd prek run [args...]` (use `--all-files` before pushing).

If you maintain a full host toolchain instead, use `prek install` (or `pre-commit install`); manual form is `prek run --all-files`.
