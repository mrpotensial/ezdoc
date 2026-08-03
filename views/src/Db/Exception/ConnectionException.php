<?php

declare(strict_types=1);

namespace Ezdoc\Db\Exception;

/**
 * Thrown ketika koneksi ke DB gagal dibentuk atau lost mid-request.
 *
 * Contoh:
 *   - DSN salah / host unreachable
 *   - Credentials salah
 *   - Connection dropped by server
 *   - PDO extension untuk driver tertentu tidak ter-install
 */
final class ConnectionException extends DbException
{
}
