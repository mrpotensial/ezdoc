<?php

declare(strict_types=1);

namespace Ezdoc\Admin;

use Ezdoc\Db\Connection;
use Ezdoc\Db\Schema\ColumnIntrospector;

/**
 * Angka-angka yang menjawab "apakah pemasangan ini sehat?".
 *
 * Semuanya BACA saja. Tiga pertanyaan yang selama ini hanya bisa dijawab dengan
 * membuka basis data sendiri:
 *
 *   - tanda tangan mana yang tidak lagi cocok dengan isinya (`tampered`);
 *   - berapa OTP yang gagal atau kedaluwarsa belakangan ini;
 *   - dokumen bertanda tangan mana yang tidak punya arsip tampilan.
 *
 * Yang ketiga perlu penjelasan, bukan tombol perbaikan: dokumen yang dibuat
 * sebelum pembekuan aktif memang tidak punya arsip, dan membekukannya sekarang
 * akan menyimpan tampilan HARI INI — bukan yang dilihat penanda tangannya.
 * Arsip yang menunjukkan hal yang salah lebih buruk daripada tidak ada arsip.
 *
 * Tabel yang belum ada dilewati, bukan menggagalkan halaman: pemasangan bisa
 * saja belum menjalankan migrasi terbaru, dan halaman diagnostik adalah tempat
 * terakhir yang boleh mati karenanya.
 */
final class Diagnostics
{
    /** Jendela waktu untuk angka "belakangan ini", dalam hari. */
    public const JENDELA_HARI = 30;

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string,mixed>
     */
    public function health(): array
    {
        return [
            'jendela_hari'  => self::JENDELA_HARI,
            'tanda_tangan'  => $this->tandaTangan(),
            'otp'           => $this->otp(),
            'dokumen'       => $this->dokumen(),
            'audit'         => $this->audit(),
        ];
    }

    /**
     * Tanda tangan per status verifikasi.
     *
     * `tampered` bukan sekadar angka: ia berarti isi dokumen berubah setelah
     * ditandatangani, dan satu pun sudah cukup untuk ditengok.
     *
     * @return array{total:int,per_status:array<string,int>,bermasalah:int}
     */
    private function tandaTangan(): array
    {
        if (!$this->punyaTabel('ezdoc_signatures')) {
            return ['total' => 0, 'per_status' => [], 'bermasalah' => 0];
        }

        $perStatus = [];
        foreach ($this->db->fetchAll(
            'SELECT verify_status, COUNT(*) AS n FROM ezdoc_signatures WHERE deleted_at IS NULL GROUP BY verify_status'
        ) as $row) {
            $perStatus[(string) ($row['verify_status'] ?? 'unknown')] = (int) $row['n'];
        }

        $bermasalah = 0;
        foreach ($perStatus as $status => $n) {
            if ($status !== 'valid') {
                $bermasalah += $n;
            }
        }

        return ['total' => array_sum($perStatus), 'per_status' => $perStatus, 'bermasalah' => $bermasalah];
    }

    /**
     * OTP dalam jendela waktu: berapa diminta, berapa jadi tanda tangan.
     *
     * @return array{total:int,terverifikasi:int,kedaluwarsa:int,gagal:int}
     */
    private function otp(): array
    {
        $kosong = ['total' => 0, 'terverifikasi' => 0, 'kedaluwarsa' => 0, 'gagal' => 0];
        if (!$this->punyaTabel('ezdoc_ttd_otp_challenges')) {
            return $kosong;
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN verified_at IS NULL THEN 0 ELSE 1 END) AS terverifikasi,'
            . ' SUM(CASE WHEN verified_at IS NULL AND expires_at < ' . $this->sekarang() . ' THEN 1 ELSE 0 END) AS kedaluwarsa'
            . ' FROM ezdoc_ttd_otp_challenges WHERE created_at > ' . $this->batasJendela()
        ) ?: [];

        $gagal = 0;
        if ($this->punyaTabel('ezdoc_audit_log')) {
            $baris = $this->db->fetchOne(
                "SELECT COUNT(*) AS n FROM ezdoc_audit_log"
                . " WHERE event_type = 'otp.verify_failed' AND occurred_at > " . $this->batasJendela()
            ) ?: [];
            $gagal = (int) ($baris['n'] ?? 0);
        }

        return [
            'total'         => (int) ($row['total'] ?? 0),
            'terverifikasi' => (int) ($row['terverifikasi'] ?? 0),
            'kedaluwarsa'   => (int) ($row['kedaluwarsa'] ?? 0),
            'gagal'         => $gagal,
        ];
    }

    /**
     * @return array{total:int,bertanda_tangan:int,tanpa_arsip:int,terkunci:int,tersembunyi:int}
     */
    private function dokumen(): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS terkunci,'
            . ' SUM(CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END) AS tersembunyi'
            . ' FROM ezdoc_documents'
        ) ?: [];

        $bertandaTangan = 0;
        $tanpaArsip = 0;
        if ($this->punyaTabel('ezdoc_signatures')) {
            $b = $this->db->fetchOne(
                'SELECT COUNT(DISTINCT s.document_id) AS n FROM ezdoc_signatures s'
                . ' JOIN ezdoc_documents d ON d.id = s.document_id'
                . ' WHERE s.deleted_at IS NULL AND d.deleted_at IS NULL'
            ) ?: [];
            $bertandaTangan = (int) ($b['n'] ?? 0);

            if ($this->punyaTabel('ezdoc_document_artifacts')) {
                $t = $this->db->fetchOne(
                    'SELECT COUNT(DISTINCT s.document_id) AS n FROM ezdoc_signatures s'
                    . ' JOIN ezdoc_documents d ON d.id = s.document_id'
                    . ' WHERE s.deleted_at IS NULL AND d.deleted_at IS NULL'
                    . ' AND NOT EXISTS (SELECT 1 FROM ezdoc_document_artifacts a WHERE a.document_id = s.document_id)'
                ) ?: [];
                $tanpaArsip = (int) ($t['n'] ?? 0);
            } else {
                $tanpaArsip = $bertandaTangan;
            }
        }

        return [
            'total'           => (int) ($row['total'] ?? 0),
            'bertanda_tangan' => $bertandaTangan,
            'tanpa_arsip'     => $tanpaArsip,
            'terkunci'        => (int) ($row['terkunci'] ?? 0),
            'tersembunyi'     => (int) ($row['tersembunyi'] ?? 0),
        ];
    }

    /**
     * Kejadian yang TIDAK berhasil dalam jendela waktu.
     *
     * `success` adalah satu-satunya hasil yang baik; apa pun selain itu —
     * `denied`, `error` — masuk hitungan ini.
     *
     * @return array{gagal:int,per_jenis:array<string,int>}
     */
    private function audit(): array
    {
        if (!$this->punyaTabel('ezdoc_audit_log')) {
            return ['gagal' => 0, 'per_jenis' => []];
        }

        $perJenis = [];
        foreach ($this->db->fetchAll(
            "SELECT event_type, result, COUNT(*) AS n FROM ezdoc_audit_log"
            . " WHERE result <> 'success' AND occurred_at > " . $this->batasJendela()
            . ' GROUP BY event_type, result ORDER BY n DESC LIMIT 10'
        ) as $row) {
            $perJenis[$row['event_type'] . ' · ' . $row['result']] = (int) $row['n'];
        }

        return ['gagal' => array_sum($perJenis), 'per_jenis' => $perJenis];
    }

    /**
     * Kejadian audit terbaru, dengan saringan.
     *
     * Dibatasi jumlahnya dan dibaca mundur dari yang terbaru: yang dicari orang
     * di halaman ini hampir selalu "apa yang baru saja terjadi".
     *
     * @param array{event_type?:string,result?:string,actor?:string,doc_id?:int} $saring
     * @return list<array<string,mixed>>
     */
    public function recentEvents(array $saring = [], int $limit = 50, int $offset = 0): array
    {
        if (!$this->punyaTabel('ezdoc_audit_log')) {
            return [];
        }

        $syarat = [];
        $nilai = [];
        foreach ([
            'event_type' => 'event_type',
            'result'     => 'result',
            'actor'      => 'actor_id',
        ] as $kunci => $kolom) {
            $isi = trim((string) ($saring[$kunci] ?? ''));
            if ($isi !== '') {
                $syarat[] = $kolom . ' = ?';
                $nilai[] = $isi;
            }
        }
        if (!empty($saring['doc_id'])) {
            $syarat[] = 'doc_id = ?';
            $nilai[] = (int) $saring['doc_id'];
        }

        $where = $syarat === [] ? '' : ' WHERE ' . implode(' AND ', $syarat);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return $this->db->fetchAll(
            'SELECT id, event_type, result, actor_id, actor_roles, doc_id, template_id,'
            . ' ip_address, message, occurred_at FROM ezdoc_audit_log'
            . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $nilai
        );
    }

    /**
     * Jenis kejadian yang benar-benar ada, untuk mengisi saringannya.
     *
     * Diambil dari data, bukan dari daftar tetap: jenis kejadian bertambah
     * seiring fitur, dan daftar tetap akan selalu ketinggalan.
     *
     * @return list<string>
     */
    public function eventTypes(): array
    {
        if (!$this->punyaTabel('ezdoc_audit_log')) {
            return [];
        }

        $jenis = [];
        foreach ($this->db->fetchAll(
            'SELECT DISTINCT event_type FROM ezdoc_audit_log ORDER BY event_type LIMIT 100'
        ) as $row) {
            $jenis[] = (string) $row['event_type'];
        }

        return $jenis;
    }

    /**
     * Siapa boleh apa, per template.
     *
     * Template tanpa `access_config` memakai aturan bawaan pustaka — dan itu
     * keadaan yang paling sering disalahpahami: orang mengira sudah membatasi
     * sesuatu padahal belum. Karena itu ia ditandai, bukan dibiarkan kosong.
     *
     * @return list<array{id:int,nama:string,versi:int,akses:array<string,string>,bawaan:bool}>
     */
    public function templateAccess(): array
    {
        if (!$this->punyaTabel('ezdoc_templates')) {
            return [];
        }

        $hasil = [];
        foreach ($this->db->fetchAll(
            'SELECT id, name, version, access_config FROM ezdoc_templates'
            . ' WHERE deleted_at IS NULL AND is_current = 1 ORDER BY name'
        ) as $row) {
            $mentah = (string) ($row['access_config'] ?? '');
            $config = json_decode($mentah, true);
            $config = is_array($config) ? $config : [];

            $akses = [];
            foreach ($config as $tindakan => $isi) {
                if ($tindakan === 'mode' || !is_array($isi)) {
                    continue;
                }
                $peran = array_merge(
                    array_map('strval', (array) ($isi['roles'] ?? [])),
                    array_map(static function ($u): string { return '#' . $u; }, (array) ($isi['users'] ?? []))
                );
                if ($peran !== []) {
                    $akses[(string) $tindakan] = implode(', ', $peran);
                }
            }

            $hasil[] = [
                'id'     => (int) $row['id'],
                'nama'   => (string) $row['name'],
                'versi'  => (int) $row['version'],
                'mode'   => (string) ($config['mode'] ?? ''),
                'akses'  => $akses,
                'bawaan' => $akses === [],
            ];
        }

        return $hasil;
    }

    private function batasJendela(): string
    {
        return $this->db->grammar()->name() === 'sqlite'
            ? "datetime('now', '-" . self::JENDELA_HARI . " days')"
            : 'DATE_SUB(NOW(), INTERVAL ' . self::JENDELA_HARI . ' DAY)';
    }

    private function sekarang(): string
    {
        return $this->db->grammar()->name() === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    private function punyaTabel(string $nama): bool
    {
        try {
            return (new ColumnIntrospector($this->db))->columns($nama) !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }
}
