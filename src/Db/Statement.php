<?php

declare(strict_types=1);

namespace Ezdoc\Db;

/**
 * Ezdoc\Db\Statement — prepared statement handle, driver-agnostic.
 *
 * Diperoleh dari `Connection::prepare()`. Bisa di-execute berulang dengan
 * parameter yang beda supaya prepare overhead tidak diulang.
 *
 * Use case utama: batch insert / update loop.
 *
 * @implementation-notes Contract shape aligned dengan Doctrine DBAL Statement
 *   dan PDOStatement. Reimplemented from spec.
 */
interface Statement
{
    /**
     * Execute statement dengan positional params.
     *
     * @param list<mixed> $params
     * @return int Affected rows (INSERT/UPDATE/DELETE) atau 0 untuk SELECT
     *   (caller pakai fetchAll/fetchOne setelah execute).
     * @throws \Ezdoc\Db\Exception\QueryException
     */
    public function execute(array $params = []): int;

    /**
     * Fetch next row sebagai assoc array, atau null kalau habis.
     *
     * Call setelah `execute()` untuk SELECT statement. Bisa dipanggil berulang
     * untuk streaming besar (row-by-row processing tanpa load semua ke memory).
     *
     * @return array<string,mixed>|null
     */
    public function fetch(): ?array;

    /**
     * Fetch semua row sekaligus sebagai list.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchAll(): array;

    /**
     * Fetch first column dari first row (scalar).
     *
     * @return mixed
     */
    public function fetchScalar();

    /**
     * Free result resources. Auto-called on destruct, tapi bisa manual untuk
     * eager cleanup dalam loop panjang.
     */
    public function close(): void;
}
