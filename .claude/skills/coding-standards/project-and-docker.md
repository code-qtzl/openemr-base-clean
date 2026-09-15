# Project structure, Docker & git

## Project structure

```
/src/              - Modern PSR-4 code (OpenEMR\ namespace)
/library/          - Legacy procedural PHP code
/interface/        - Web UI controllers and templates
/templates/        - Smarty/Twig templates
/tests/            - Test suite (unit, e2e, api, services)
/sql/              - Database schema and migrations
/public/           - Static assets
/docker/           - Docker configurations
/modules/          - Custom and third-party modules
```

## Technology stack

- **PHP:** 8.2+ required
- **Backend:** Laminas MVC, Symfony components
- **Templates:** Twig 3.x (modern), Smarty 4.5 (legacy)
- **Frontend:** jQuery 3.7, Bootstrap 4.6, Backbone, Angular 1.8 (legacy). **No React or Vue.**
- **Build:** Webpack 5, SASS
- **Database:** MySQL via Doctrine DBAL 4.x (ADODB surface API for legacy code)
- **Testing:** PHPUnit 11, Jest 29
- **Static Analysis:** PHPStan level 10, Rector, custom rules in `tests/PHPStan/Rules/`

## Local development

See `CONTRIBUTING.md` for full setup. Quick start:

```bash
cd docker/development-easy
docker compose up --detach --wait
```

- **App URL:** http://localhost:8300/ or https://localhost:9300/
- **Login:** `admin` / `pass`
- **phpMyAdmin:** http://localhost:8310/

Stack variants under `docker/`: `development-easy` (full — adds Selenium, CouchDB, OpenLDAP, Mailpit), `development-easy-light` (MariaDB + OpenEMR + phpMyAdmin only), `development-easy-redis`, `development-insane`, `production`, `flex`, `release`, `binary`.

## Custom modules

Custom modules live at `interface/modules/custom_modules/<name>/` with an `openemr.bootstrap.php` entry point. The cleanest modern template is `oe-module-dashboard-context`.

Registration is via the `modules` DB table, enabled through **Admin → Manage Modules**. A module's `public/*.php` endpoints only work while the module row is active — `ModulesApplication::checkModuleScriptPathForEnabledModule()` enforces this.

UI injection uses Symfony EventDispatcher events, e.g. `Main\Tabs\RenderEvent` (`main.body.render.nav` / `.post`) for a global widget, or `Patient\Summary\Card\SectionEvent` to add a card to the patient dashboard. New REST routes register via `RestApiCreateEvent`; new OAuth2 scopes via `RestApiScopeEvent`.

## Working in a git worktree

OpenEMR supports concurrent development across branches via git worktrees managed by `openemr-cmd worktree`. Skip this section if the working directory does not match `*/openemr-wt-<slug>/` — that path is the signal you are inside a managed worktree. `openemr-cmd worktree list` confirms.

**Never use raw `git worktree add`, `git worktree remove`, or `git worktree move` against this repo.** The `openemr-cmd worktree` script owns state that bare git does not: a JSON state file tracking each worktree, a per-worktree compose override with its assigned port offset, and a generated `.env`. Bypassing it leaves orphaned state, port collisions, and broken compose stacks the script can no longer recover. Use `openemr-cmd worktree` subcommands: `add`, `remove`, `up`, `down`, `start`, `stop`, `exec`, `set-env`, `list`, `regen`, `prune`.

Even for tasks that seem not to need a stack (docs-only PRs, review checkouts), still use `openemr-cmd worktree add <branch> --start` (`-b` if new). The `git commit` hook routes via openemr-cmd into the worktree's container; without a state entry pointing at a running stack, commits fail with `Could not automatically determine target OpenEMR container`. Recovery from a raw add: `git worktree remove <path>` then `openemr-cmd worktree add <branch> --start`.

With `-b`, the new branch is based on canonical `openemr/openemr` master fetched from GitHub at command time — *not* the primary repo's HEAD. Override with `--base <ref>`, accepting either a URL (optionally `#<ref>`) for a fresh fetch, or any git commit-ish resolved locally. Because the primary's HEAD is never read or modified on the default path, concurrent worktree creation by multiple agents is safe.

**Never use `git fetch ... --update-head-ok` in the primary repo**, regardless of remote. It overwrites the current branch's ref without updating the working tree, leaving the index showing staged deletions of everything new on master — a stray `git commit` after that wipes recent work. Use `git pull` or plain `git fetch` instead.

If `openemr-cmd worktree list` shows `missing` or `invalid` entries with a footer about stale state, run `openemr-cmd worktree prune` — never hand-edit `.worktrees.json`. If the footer instead reports missing compose files, use `openemr-cmd worktree regen <branch>`.

Run commands against a worktree's containers with `openemr-cmd worktree exec <branch> <cmd>`, not `cd docker/development-easy && docker compose exec openemr ...` — each worktree has a distinct compose project name and port offset, so the bare form hits the wrong stack.

For short pauses prefer `worktree stop` / `worktree start` (pause/resume, data preserved) over `worktree down` / `worktree up` (recreates containers).

## Commit messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <description>
```

**Types:** feat, fix, docs, style, refactor, perf, test, build, ci, chore, revert

**Examples:**
- `feat(api): add PATCH support for patient resource`
- `fix(calendar): correct date parsing for recurring events`
- `chore(deps): bump monolog/monolog to 3.10.0`

Commit messages are validated against this format in CI.

### AI assistance trailer

If an AI assistant helped write a commit, add an `Assisted-by` trailer:

```bash
git commit --trailer "Assisted-by: Claude Code" -m "fix(calendar): correct date parsing"
```

Use the tool's name as the value (`Claude Code`, `GitHub Copilot`, `ChatGPT`).

## Common gotchas

- Multiple template engines: check the extension (`.twig`, `.html`, `.php`).
- Event system uses Symfony EventDispatcher.
- **Bind-mount permissions / HOST_UID:** openemr-cmd auto-exports `HOST_UID`/`HOST_GID` on every `up`, and the in-container apache user adopts your host uid via the entrypoint. Bind-mounted files apache writes are then host-owned, so host-side edits (including `git commit`) work regardless of your host uid. Bypassing openemr-cmd skips the export and leaves apache at uid=1000 — the usual cause of `EACCES` on bind-mount edits.

## Key documentation

- `CONTRIBUTING.md` — contributing guidelines
- `API_README.md` — REST API docs
- `FHIR_README.md` — FHIR implementation
- `DOCKER_README.md` — Docker images and deployment
- `tests/Tests/README.md` — testing guide
