<?php
/**
 * Ezdoc starter — document list view.
 *
 * STARTER TEMPLATE — publish + edit untuk customization:
 *   php cli/publish.php views /app/resources/views/vendor/ezdoc
 * See docs/UI-CUSTOMIZATION.md.
 *
 * Expected vars:
 *   @var \Ezdoc\Config                       $config
 *   @var \Ezdoc\UI\Theme                     $theme
 *   @var \Ezdoc\Document\Document[]          $documents
 *   @var array<string,mixed>                 $filters   Current filter state (subject_type, status, q)
 *   @var string                              $baseUrl   Action endpoint base
 */

$pageTitle = (string) $config->get('pages.list.title', 'Documents');
$emptyMsg  = (string) $config->get('pages.list.empty_message', 'Belum ada dokumen. Klik "Buat Dokumen" untuk mulai.');
?>
<section class="ezdoc-document-list">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <a href="<?= htmlspecialchars($baseUrl . '/document/new', ENT_QUOTES, 'UTF-8') ?>"
           class="btn ezdoc-btn ezdoc-btn-primary">+ Buat Dokumen</a>
    </div>

    <form method="get" class="row g-2 mb-3 ezdoc-filters">
        <div class="col-sm-4">
            <input type="search" name="q" class="form-control"
                   placeholder="Cari judul / referensi..."
                   value="<?= htmlspecialchars((string)($filters['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-sm-3">
            <select name="status" class="form-select">
                <option value="">-- Semua status --</option>
                <?php foreach (['draft','issued','signed','void'] as $st): ?>
                    <option value="<?= $st ?>"
                        <?= (isset($filters['status']) && $filters['status'] === $st) ? 'selected' : '' ?>>
                        <?= ucfirst($st) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?= \Ezdoc\UI\Slot::render('document-list:filters-extra') ?>

        <div class="col-sm-auto">
            <button class="btn btn-outline-secondary" type="submit">Filter</button>
        </div>
    </form>

    <?php if (empty($documents)): ?>
        <div class="ezdoc-empty-state text-center text-muted py-5 border rounded">
            <p class="mb-0"><?= htmlspecialchars($emptyMsg, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ezdoc-table align-middle">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td>
                            <a href="<?= htmlspecialchars($baseUrl . '/document/view?uuid=' . urlencode($doc->getUuid()), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string) ($doc->getTitle() ?? '(untitled)'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?php if ($ref = $doc->getReferenceNumber()): ?>
                                <div class="small text-muted"><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sid = $doc->getSubjectId()): ?>
                                <span class="text-muted small"><?= htmlspecialchars((string) $doc->getSubjectType(), ENT_QUOTES, 'UTF-8') ?>:</span>
                                <?= htmlspecialchars($sid, ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="ezdoc-badge ezdoc-badge-<?= htmlspecialchars($doc->getStatus(), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($doc->getStatus(), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="small text-muted">
                            <?= htmlspecialchars((string) ($doc->getCreatedAt() ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= htmlspecialchars($baseUrl . '/document/print?uuid=' . urlencode($doc->getUuid()), ENT_QUOTES, 'UTF-8') ?>">Print</a>
                            <?= \Ezdoc\UI\Slot::render('document-list:actions-extra', ['document' => $doc]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
