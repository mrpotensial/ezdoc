# ezdoc-spec (generated)

**DO NOT EDIT MANUALLY.** Regenerate via `php cli/spec-dump.php` dari
project root.

Source of truth: `migrations/blueprints/*.php` (PHP Blueprint DSL).

## Contents

- `schema/tables.{json,yaml}` — DB schema descriptor (portable, cross-language)
- `ddl/*.sql` — Generated DDL per platform (mysql/mariadb/sqlite/postgres/sqlserver)
- `meta/version.json` — Version metadata + checksum
- `meta/checksum.txt` — SHA-256 hash of all artifacts (CI gate)

## Cross-language consumption

- **Go**: `yq eval schema/tables.yaml` → codegen struct
- **Rust**: `serde_yaml::from_str()` → struct + Diesel/SeaORM binding
- **TypeScript**: `js-yaml` → interface / Prisma / Drizzle schema

## CI gate

Add to CI pipeline:

```yaml
- run: php cli/spec-dump.php --check   # fail if spec out-of-date
```
