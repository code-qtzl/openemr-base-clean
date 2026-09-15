# PHP conventions & type system

## Formatting and structure

- **Indentation:** 4 spaces
- **Line endings:** LF (Unix)
- **Namespaces:** PSR-4 with `OpenEMR\` prefix for `/src/`
- New code goes in `/src/`, legacy helpers in `/library/`

## PSR standards

| Standard | Purpose |
|----------|---------|
| [PSR-1](https://www.php-fig.org/psr/psr-1/) | Basic coding standard (class naming, file structure) |
| [PSR-4](https://www.php-fig.org/psr/psr-4/) | Autoloading |
| [PSR-3](https://www.php-fig.org/psr/psr-3/) | Logger interface (`Psr\Log\LoggerInterface`) |
| [PSR-11](https://www.php-fig.org/psr/psr-11/) | Container interface (`Psr\Container\ContainerInterface`) |
| [PER-CS 3.0](https://www.php-fig.org/per/coding-style/) | Coding style (supersedes PSR-12; adds enums, match, union types) |

Adopt where applicable: PSR-7 (HTTP messages), PSR-15 (middleware), PSR-17 (HTTP factories), PSR-18 (HTTP client), PSR-20 (clock).

## Strict typing

Every new PHP file starts with `declare(strict_types=1)`. Without strict types, PHP silently coerces `"123abc"` to `123` when passed to an `int` parameter, hiding bugs that surface later as data corruption.

Every property, parameter, and return type should have a native type declaration. Reserve PHPDoc types for what native types cannot express (generics, array shapes, type narrowing).

## Type system

- **Nullable types:** Use `?Type`. Use `Type|null` only in unions with three or more members.
- **Avoid `mixed`:** Enumerate types explicitly. Reserve `mixed` for genuinely polymorphic code and narrow it immediately via type checks.
- **Enums over constants:** Use enums for any value drawn from a closed set. Prefer unit enums (no backing type) for purely runtime state. Use backed enums only when the value is persisted to a database, serialized to JSON, or exchanged with an external system.
- **Return types:** `void` for side-effect-only methods, `never` for methods that always throw or exit, `self` for factories on `final` classes, `static` for factories on non-final classes.

## Immutability

- Use `readonly` classes or properties for value objects, DTOs, and configuration. Mutable state should be the exception.
- `final` on value objects to prevent mutable subclasses.
- `DateTimeImmutable` over `DateTime` — always.
- Wither methods (return a new instance) over setters on value objects.

## Domain primitives

Wrap primitive values in typed classes when the primitive could be confused with another primitive of the same PHP type. This prevents argument-transposition bugs invisible to PHP's type system:

```php
final readonly class PatientId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new \DomainException('Patient ID must be positive');
        }
    }
}
```

Use for: IDs that could be confused (`PatientId` vs `EncounterId`), strings with semantic meaning (`Email`, `Npi`), numbers with constraints or units (`Money`).

## Parse, don't validate

At system boundaries (controllers, CLI handlers, message consumers), parse raw input into typed objects immediately. After parsing, the rest of the code works with types that guarantee their own validity — no re-validation downstream.

## Exhaustive matching

Use `match` on enums without a `default` branch. PHPStan verifies every case is handled. Adding a `default` silently absorbs new cases and suppresses the exhaustiveness check.

## Null safety

- **Early returns:** Flatten null checks with early returns rather than nesting.
- **Null coalescing:** `??` for defaults, `??=` for lazy initialization.
- **Null-safe operator:** `?->` for optional chaining, but no more than two levels deep.
- **Never suppress nullable warnings.** If PHPStan says a value might be null, handle the null case explicitly. Do not add `@var` casts or `@phpstan-ignore` comments to silence it.

## Error handling and logging

**PSR-3 logging context.** Never concatenate or interpolate variables into log messages:

```php
// Bad
$this->logger->error("Failed for {$phone}: " . $e->getMessage());

// Good
$this->logger->error('Failed to send message', [
    'phone' => $phone,
    'exception' => $e,
]);
```

**Catch `\Throwable`, not `\Exception`.** `\Exception` misses `\TypeError`, `\ParseError`, and other `\Error` subclasses.

**Let exceptions propagate.** Only catch when the caller can meaningfully recover. Do not catch-log-continue — it hides failures from callers.

**Never expose `$e->getMessage()` in user-facing output.** Exception messages may contain internal details (SQL, file paths). Log the exception and return a generic message.

**Exception chaining:** When wrapping an exception, use a generic message describing the failed operation. The original is accessible via `->getPrevious()` — do not embed its message in the wrapper.

## Dependency injection

Inject all dependencies through the constructor. Never use `new` for service-layer objects inside business logic, never call static service locators, and never reach into global state (`$GLOBALS`, `$_SESSION`, `$_GET`) for dependencies.

- **Interface-based dependencies** for cross-boundary code. Use concrete types for internal collaborators where a single-implementation interface adds no value.
- **PSR-11 containers:** Wire in configuration, not in business logic. Business logic classes should never know the container exists.
- **Clock injection (PSR-20):** Inject `ClockInterface` instead of calling `new \DateTimeImmutable()` or `time()` directly, so time-dependent code is deterministically testable.
- **No direct superglobal access** in application code. Use PSR-7 request objects, framework session abstractions, and container-provided configuration. In legacy code where unavoidable, confine superglobal reads to the outermost entry point and parse into typed objects immediately.

## File headers

When modifying PHP files, ensure a proper docblock. **Preserve existing authors and copyrights when editing.**

```php
/**
 * Brief description
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Your Name <your@email.com>
 * @copyright Copyright (c) YEAR Your Name or Organization
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
```
