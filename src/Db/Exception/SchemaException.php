<?php

declare(strict_types=1);

namespace Ezdoc\Db\Exception;

/**
 * Thrown untuk error terkait schema (Blueprint / Grammar / SchemaManager).
 *
 * Contoh:
 *   - Blueprint declaration invalid (unknown column type, dup index name)
 *   - Grammar tidak support fitur yang di-request (contoh: enum di SQLite native)
 *   - Migration file corrupt / malformed
 */
final class SchemaException extends DbException
{
}
