---
name: do-work
description: "Execute a unit of work end-to-end: plan, implement, validate with typecheck and tests, then commit. Use when user wants to do work, build a feature, fix a bug, or implement a phase from a plan."
---

# Do Work

Execute a complete unit of work: plan it, build it, validate it, commit it.

## Workflow

### 1. Understand the task

Read any referenced plan or PRD. Explore the codebase to understand the relevant files, patterns, and conventions. If the task is ambiguous, ask the user to clarify scope before proceeding.

### 2. Plan the implementation (optional)

If the task has not already been planned, create a plan for it.

### 3. Implement

**For backend code**: use red/green/refactor, one test at a time in a tracer-bullet style.

1. Write a single failing test for the smallest vertical slice of behavior
2. Run the test — confirm it fails (red)
3. Write the minimum code to make it pass (green)
4. Repeat from step 1 for the next slice of behavior
5. Refactor if needed while keeping tests green

Each test should target one thin vertical slice through the system. Do not write all tests upfront — write one, make it pass, then move to the next.

**For frontend code**: implement directly without TDD.

### 4. Validate

Run the feedback loops and fix any issues. Repeat until both pass cleanly.

This is a PHP/OpenEMR codebase — validation runs inside the openemr container:

```
openemr-cmd phpstan        # static analysis, level 10 (alias: pst)
openemr-cmd unit-test      # alias: ut
```

Scale up as the change warrants: `openemr-cmd code-quality` (alias `cq`) for the full
quality suite, `openemr-cmd services-test` / `api-test` / `e2e-test` for broader
coverage, or `openemr-cmd clean-sweep-tests` (alias `cst`) for everything.

For pure-PHP logic with no database dependency, `openemr-cmd phpunit-isolated`
(alias `pit`) is much faster. Host equivalents exist as `composer phpstan`,
`composer phpunit-isolated`, etc., if a local PHP toolchain is available.

See the `coding-standards` skill for the full command reference.

### 5. Commit

Once static analysis and tests pass, commit the work. Follow Conventional Commits
and add the `Assisted-by` trailer — see the `coding-standards` skill.
