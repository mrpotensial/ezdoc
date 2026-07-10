<?php
/**
 * Ezdoc starter — document generate form.
 *
 * STARTER TEMPLATE. Consumer typically publishes this file, then edits
 * to add app-specific fields (patient picker, org selector, etc):
 *
 *   php cli/publish.php views /app/resources/views/vendor/ezdoc
 *
 * The published copy in /app/resources/views/vendor/ezdoc/document/form.php
 * is automatically preferred by ViewResolver. See docs/UI-CUSTOMIZATION.md.
 *
 * Expected vars:
 *   @var \Ezdoc\Config  $config
 *   @var \Ezdoc\UI\Theme $theme
 *   @var array          $templates   [{uuid,name,description}, ...]
 *   @var array          $selected    Partially-filled document (uuid, template_uuid, field_values, ...)
 *   @var string         $submitUrl   POST target
 *   @var string         $csrfToken   CSRF token value
 */

$pageTitle    = (string) $config->get('pages.form.title', 'Create Document');
$submitLabel  = (string) $config->get('pages.form.submit_label', 'Save Document');
$selected     = isset($selected) && is_array($selected) ? $selected : [];
$fieldValues  = isset($selected['field_values']) && is_array($selected['field_values'])
                    ? $selected['field_values'] : [];
$templateUuid = isset($selected['template_uuid']) ? (string) $selected['template_uuid'] : '';
$subjectType  = isset($selected['subject_type']) ? (string) $selected['subject_type'] : '';
$subjectId    = isset($selected['subject_id']) ? (string) $selected['subject_id'] : '';
$title        = isset($selected['title']) ? (string) $selected['title'] : '';
?>
<section class="ezdoc-document-form">
    <h1 class="h4 mb-3"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>

    <form method="post" action="<?= htmlspecialchars($submitUrl, ENT_QUOTES, 'UTF-8') ?>"
          class="ezdoc-card p-3" data-ezdoc-form="document-generate">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?= \Ezdoc\UI\Slot::render('document-form:before-fields', ['selected' => $selected]) ?>

        <div class="mb-3">
            <label class="form-label" for="ezdoc-template">Template <span class="text-danger">*</span></label>
            <select name="template_uuid" id="ezdoc-template" class="form-select" required
                    data-ezdoc-target="template-selector">
                <option value="">-- Pilih template --</option>
                <?php foreach ((array) $templates as $tpl): ?>
                    <option value="<?= htmlspecialchars((string) $tpl['uuid'], ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($templateUuid === $tpl['uuid']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $tpl['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label" for="ezdoc-title">Title</label>
            <input type="text" name="title" id="ezdoc-title" class="form-control"
                   value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Optional — falls back to template name">
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label" for="ezdoc-subject-type">Subject type</label>
                <input type="text" name="subject_type" id="ezdoc-subject-type" class="form-control"
                       value="<?= htmlspecialchars($subjectType, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="e.g. patient / employee / order">
            </div>
            <div class="col-md-8">
                <label class="form-label" for="ezdoc-subject-id">Subject ID</label>
                <input type="text" name="subject_id" id="ezdoc-subject-id" class="form-control"
                       value="<?= htmlspecialchars($subjectId, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Domain-agnostic subject identifier">
            </div>
        </div>

        <fieldset class="mb-3 ezdoc-field-values" data-ezdoc-target="field-values">
            <legend class="h6">Field values</legend>
            <p class="small text-muted mb-2">
                Loaded dynamically berdasarkan template pilihan. Field ini di-render oleh
                <code>Ezdoc.slots</code> via AJAX endpoint <code>actions/template/fields.php</code>.
            </p>
            <?php foreach ($fieldValues as $fname => $fval): ?>
                <div class="mb-2">
                    <label class="form-label" for="fv-<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" class="form-control"
                           id="fv-<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>"
                           name="field_values[<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>]"
                           value="<?= htmlspecialchars(is_scalar($fval) ? (string) $fval : json_encode($fval), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            <?php endforeach; ?>
        </fieldset>

        <div class="mb-3 ezdoc-signature-slot" data-ezdoc-target="signature-slot">
            <legend class="h6">Signature</legend>
            <div class="border rounded p-3 text-muted small">
                Signature capture UI — plug in via <code>Ezdoc.slots.register('document-form:signature', ...)</code>
                atau override view untuk render pad langsung.
            </div>
        </div>

        <?= \Ezdoc\UI\Slot::render('document-form:after-fields', ['selected' => $selected]) ?>

        <div class="d-flex gap-2">
            <button type="submit" class="btn ezdoc-btn ezdoc-btn-primary">
                <?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?>
            </button>
            <a class="btn btn-link" href="<?= htmlspecialchars((string) $config->get('urls.list', '#'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
        </div>
    </form>
</section>
