<?php

declare(strict_types=1);

namespace Ezdoc\Db\Exception;

/**
 * Thrown untuk error terkait transaction state.
 *
 * Contoh:
 *   - `commit()` / `rollback()` tanpa `beginTransaction()`
 *   - Nested transaction pada driver yang tidak support savepoint
 *   - Deadlock detected → forced rollback
 */
final class TransactionException extends DbException
{
}
