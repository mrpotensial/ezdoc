<?php
/**
 * Ezdoc starter — document generate form (Tailwind CSS + @tailwindcss/forms).
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
<section>
    <h1 class="text-2xl font-semibold tracking-tight text-gray-900 mb-6">
        <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
    </h1>

    <form method="post" action="<?= htmlspecialchars($submitUrl, ENT_QUOTES, 'UTF-8') ?>"
          class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm space-y-5"
          data-ezdoc-form="document-generate">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <?= \Ezdoc\UI\Slot::render('document-form:before-fields', ['selected' => $selected]) ?>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="ezdoc-template">
                Template <span class="text-red-500">*</span>
            </label>
            <select name="template_uuid" id="ezdoc-template" required
                    data-ezdoc-target="template-selector"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
                <option value="">-- Pilih template --</option>
                <?php foreach ((array) $templates as $tpl): ?>
                    <option value="<?= htmlspecialchars((string) $tpl['uuid'], ENT_QUOTES, 'UTF-8') ?>"
                        <?= ($templateUuid === $tpl['uuid']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $tpl['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="ezdoc-title">Title</label>
            <input type="text" name="title" id="ezdoc-title"
                   value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="Optional — falls back to template name"
                   class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="ezdoc-subject-type">Subject type</label>
                <input type="text" name="subject_type" id="ezdoc-subject-type"
                       value="<?= htmlspecialchars($subjectType, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="e.g. patient / employee / order"
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1" for="ezdoc-subject-id">Subject ID</label>
                <input type="text" name="subject_id" id="ezdoc-subject-id"
                       value="<?= htmlspecialchars($subjectId, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Domain-agnostic subject identifier"
                       class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
            </div>
        </div>

        <fieldset data-ezdoc-target="field-values" class="rounded-md border border-gray-200 p-4">
            <legend class="px-1.5 text-sm font-semibold text-gray-700">Field values</legend>
            <p class="text-xs text-gray-500 mb-3">
                Loaded dynamically berdasarkan template pilihan. Field ini di-render oleh
                <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">Ezdoc.slots</code> via AJAX endpoint
                <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">actions/template/fields.php</code>.
            </p>
            <div class="space-y-2">
            <?php foreach ($fieldValues as $fname => $fval): ?>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1" for="fv-<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text"
                           id="fv-<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>"
                           name="field_values[<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>]"
                           value="<?= htmlspecialchars(is_scalar($fval) ? (string) $fval : json_encode($fval), ENT_QUOTES, 'UTF-8') ?>"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-400 focus:ring-1 focus:ring-gray-400 text-sm">
                </div>
            <?php endforeach; ?>
            </div>
        </fieldset>

        <fieldset data-ezdoc-target="signature-slot" class="rounded-md border border-gray-200 p-4">
            <legend class="px-1.5 text-sm font-semibold text-gray-700">Signature</legend>
            <div class="rounded border-2 border-dashed border-gray-300 bg-gray-50 p-4 text-xs text-gray-500">
                Signature capture UI — plug in via
                <code class="rounded bg-white px-1 py-0.5">Ezdoc.slots.register('document-form:signature', ...)</code>
                atau override view untuk render signature pad langsung.
            </div>
        </fieldset>

        <?= \Ezdoc\UI\Slot::render('document-form:after-fields', ['selected' => $selected]) ?>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
                    style="background-color: var(--ezdoc-primary);">
                <?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?>
            </button>
            <a class="text-sm font-medium text-gray-600 hover:text-gray-900"
               href="<?= htmlspecialchars((string) $config->get('urls.list', '#'), ENT_QUOTES, 'UTF-8') ?>">
                Cancel
            </a>
        </div>
    </form>
</section>
