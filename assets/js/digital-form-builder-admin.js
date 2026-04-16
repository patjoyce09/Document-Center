(function ($) {
  'use strict';

  function tryParseJSON(raw) {
    try {
      var parsed = JSON.parse(raw || '{}');
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return null;
    }
  }

  function slugify(v) {
    return String(v || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
  }

  function makeDefaultField() {
    return { key: 'new_field', label: 'New Field', type: 'text', required: false, conditions: [] };
  }

  function makeDefaultForm(key) {
    return {
      label: key,
      recipients: '',
      version: 1,
      fields: [],
      hard_stops: [],
      template_blocks: [],
      document_nodes: [],
      sections: [{ key: 'general', label: 'General', field_keys: [] }],
      steps: [{ key: 'step_1', label: 'Step 1', section_keys: ['general'] }],
      repeaters: []
    };
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeFormShape(form) {
    form.fields = Array.isArray(form.fields) ? form.fields : [];
    form.hard_stops = Array.isArray(form.hard_stops) ? form.hard_stops : [];
    form.template_blocks = Array.isArray(form.template_blocks) ? form.template_blocks : [];
    form.document_nodes = Array.isArray(form.document_nodes) ? form.document_nodes : [];
    form.sections = Array.isArray(form.sections) ? form.sections : [];
    form.steps = Array.isArray(form.steps) ? form.steps : [];
    form.repeaters = Array.isArray(form.repeaters) ? form.repeaters : [];
    return form;
  }

  function computeWarnings(form) {
    var warnings = [];
    var seen = {};
    (form.fields || []).forEach(function (field) {
      var key = String(field.key || '');
      if (!key) {
        warnings.push('A field has an empty key.');
        return;
      }
      if (seen[key]) {
        warnings.push('Duplicate field key: ' + key);
      }
      seen[key] = true;
    });

    (form.sections || []).forEach(function (section) {
      (section.field_keys || []).forEach(function (k) {
        if (!seen[String(k || '')]) {
          warnings.push('Section "' + (section.label || section.key || 'section') + '" references missing field: ' + k);
        }
      });
    });

    (form.steps || []).forEach(function (step) {
      if (!Array.isArray(step.section_keys) || !step.section_keys.length) {
        warnings.push('Step "' + (step.label || step.key || 'step') + '" has no section references.');
      }
    });

    return warnings;
  }

  function renderBuilder($root, state) {
    var keys = Object.keys(state.forms || {});
    var selected = state.selectedKey && state.forms[state.selectedKey] ? state.selectedKey : (keys[0] || '');
    state.selectedKey = selected;

    var listHtml = ['<div class="dcb-builder-shell">', '<div class="dcb-builder-sidebar">'];
    listHtml.push('<div class="dcb-builder-sidebar-actions">'
      + '<button type="button" class="button button-primary" data-act="add-form">Add Form</button> '
      + '<button type="button" class="button" data-act="import-json">Import JSON</button> '
      + '<button type="button" class="button" data-act="export-json">Export JSON</button>'
      + '</div>');

    if (!keys.length) {
      listHtml.push('<p>No forms yet.</p>');
    } else {
      listHtml.push('<ul class="dcb-form-list">');
      keys.forEach(function (key) {
        var form = normalizeFormShape(state.forms[key] || {});
        listHtml.push('<li class="' + (key === selected ? 'is-active' : '') + '">' +
          '<button type="button" data-act="select-form" data-key="' + escapeHtml(key) + '">' + escapeHtml(form.label || key) + '</button>' +
          '<span>' + form.fields.length + ' fields</span>' +
          '</li>');
      });
      listHtml.push('</ul>');
    }
    listHtml.push('</div>');

    listHtml.push('<div class="dcb-builder-main">');
    if (!selected) {
      listHtml.push('<p>Create a form to start.</p>');
    } else {
      var form = normalizeFormShape(state.forms[selected] || {});
      listHtml.push('<div class="dcb-form-header">'
        + '<label>Form Key <input type="text" data-act="set-key" value="' + escapeHtml(selected) + '" /></label>'
        + '<label>Label <input type="text" data-act="set-label" value="' + escapeHtml(form.label || '') + '" /></label>'
        + '<label>Recipients <input type="text" data-act="set-recipients" value="' + escapeHtml(form.recipients || '') + '" /></label>'
        + '<button type="button" class="button" data-act="duplicate-form">Duplicate</button> '
        + '<button type="button" class="button button-link-delete" data-act="delete-form">Delete</button>'
        + '</div>');

      listHtml.push('<h3>Fields</h3><table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th></th></tr></thead><tbody>');
      if (!form.fields.length) {
        listHtml.push('<tr><td colspan="5">No fields yet.</td></tr>');
      } else {
        form.fields.forEach(function (field, i) {
          listHtml.push('<tr>'
            + '<td><input type="text" data-act="field-key" data-i="' + i + '" value="' + escapeHtml(field.key || '') + '"/></td>'
            + '<td><input type="text" data-act="field-label" data-i="' + i + '" value="' + escapeHtml(field.label || '') + '"/></td>'
            + '<td><select data-act="field-type" data-i="' + i + '">' + renderTypeOptions(field.type || 'text') + '</select></td>'
            + '<td><input type="checkbox" data-act="field-required" data-i="' + i + '" ' + (field.required ? 'checked' : '') + '/></td>'
            + '<td><button type="button" class="button" data-act="field-conditions" data-i="' + i + '">Conditions</button> <button type="button" class="button-link-delete" data-act="field-delete" data-i="' + i + '">Delete</button></td>'
            + '</tr>');
        });
      }
      listHtml.push('</tbody></table><p><button type="button" class="button" data-act="field-add">Add Field</button></p>');

      listHtml.push('<h3>Structured Editors</h3>');
      listHtml.push('<div class="dcb-builder-grid-two">');
      listHtml.push('<div><h4>Sections</h4><table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Field Keys (csv)</th><th></th></tr></thead><tbody>');
      (form.sections || []).forEach(function (section, i) {
        listHtml.push('<tr>'
          + '<td><input type="text" data-act="section-key" data-i="' + i + '" value="' + escapeHtml(section.key || '') + '"/></td>'
          + '<td><input type="text" data-act="section-label" data-i="' + i + '" value="' + escapeHtml(section.label || '') + '"/></td>'
          + '<td><input type="text" data-act="section-field-keys" data-i="' + i + '" value="' + escapeHtml((section.field_keys || []).join(',')) + '"/></td>'
          + '<td><button type="button" class="button-link-delete" data-act="section-delete" data-i="' + i + '">Delete</button></td>'
          + '</tr>');
      });
      listHtml.push('</tbody></table><p><button type="button" class="button" data-act="section-add">Add Section</button></p></div>');

      listHtml.push('<div><h4>Steps</h4><table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Section Keys (csv)</th><th></th></tr></thead><tbody>');
      (form.steps || []).forEach(function (step, i) {
        listHtml.push('<tr>'
          + '<td><input type="text" data-act="step-key" data-i="' + i + '" value="' + escapeHtml(step.key || '') + '"/></td>'
          + '<td><input type="text" data-act="step-label" data-i="' + i + '" value="' + escapeHtml(step.label || '') + '"/></td>'
          + '<td><input type="text" data-act="step-section-keys" data-i="' + i + '" value="' + escapeHtml((step.section_keys || []).join(',')) + '"/></td>'
          + '<td><button type="button" class="button-link-delete" data-act="step-delete" data-i="' + i + '">Delete</button></td>'
          + '</tr>');
      });
      listHtml.push('</tbody></table><p><button type="button" class="button" data-act="step-add">Add Step</button></p></div>');
      listHtml.push('</div>');

      listHtml.push('<div class="dcb-builder-grid-two">');
      listHtml.push('<div><h4>Repeaters</h4><table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Field Keys (csv)</th><th>Min</th><th>Max</th><th></th></tr></thead><tbody>');
      (form.repeaters || []).forEach(function (rep, i) {
        listHtml.push('<tr>'
          + '<td><input type="text" data-act="repeater-key" data-i="' + i + '" value="' + escapeHtml(rep.key || '') + '"/></td>'
          + '<td><input type="text" data-act="repeater-label" data-i="' + i + '" value="' + escapeHtml(rep.label || '') + '"/></td>'
          + '<td><input type="text" data-act="repeater-field-keys" data-i="' + i + '" value="' + escapeHtml((rep.field_keys || []).join(',')) + '"/></td>'
          + '<td><input type="number" min="0" data-act="repeater-min" data-i="' + i + '" value="' + escapeHtml(String(rep.min || 0)) + '"/></td>'
          + '<td><input type="number" min="0" data-act="repeater-max" data-i="' + i + '" value="' + escapeHtml(String(rep.max || 1)) + '"/></td>'
          + '<td><button type="button" class="button-link-delete" data-act="repeater-delete" data-i="' + i + '">Delete</button></td>'
          + '</tr>');
      });
      listHtml.push('</tbody></table><p><button type="button" class="button" data-act="repeater-add">Add Repeater</button></p></div>');

      listHtml.push('<div><h4>Hard Stops & Nodes</h4>'
        + '<p><button type="button" class="button" data-act="hardstop-add">Add Hard Stop</button> '
        + '<button type="button" class="button" data-act="node-add-field">Add Field Node</button> '
        + '<button type="button" class="button" data-act="node-add-block">Add Block Node</button></p>'
        + '<p class="description">Use JSON panels below for full editing of hard stops/template blocks/document nodes.</p>'
        + '</div>');
      listHtml.push('</div>');

      listHtml.push('<h3>Sections / Steps / Repeaters</h3>');
      listHtml.push('<p><label>Sections JSON</label><textarea rows="5" data-act="sections-json" class="large-text code">' + escapeHtml(JSON.stringify(form.sections || [], null, 2)) + '</textarea></p>');
      listHtml.push('<p><label>Steps JSON</label><textarea rows="5" data-act="steps-json" class="large-text code">' + escapeHtml(JSON.stringify(form.steps || [], null, 2)) + '</textarea></p>');
      listHtml.push('<p><label>Repeaters JSON</label><textarea rows="5" data-act="repeaters-json" class="large-text code">' + escapeHtml(JSON.stringify(form.repeaters || [], null, 2)) + '</textarea></p>');

      listHtml.push('<h3>Conditional Logic / Hard Stops</h3>');
      listHtml.push('<p><label>Hard Stops JSON</label><textarea rows="6" data-act="hardstops-json" class="large-text code">' + escapeHtml(JSON.stringify(form.hard_stops || [], null, 2)) + '</textarea></p>');

      listHtml.push('<h3>Document Nodes & Template Blocks</h3>');
      listHtml.push('<p><label>Template Blocks JSON</label><textarea rows="6" data-act="template-json" class="large-text code">' + escapeHtml(JSON.stringify(form.template_blocks || [], null, 2)) + '</textarea></p>');
      listHtml.push('<p><label>Document Nodes JSON</label><textarea rows="6" data-act="nodes-json" class="large-text code">' + escapeHtml(JSON.stringify(form.document_nodes || [], null, 2)) + '</textarea></p>');

      listHtml.push('<h3>Runtime Preview Snapshot</h3>');
      listHtml.push('<pre class="dcb-preview-json">' + escapeHtml(JSON.stringify(form, null, 2)) + '</pre>');

      var warnings = computeWarnings(form);
      listHtml.push('<h3>Validation / Warning Panel</h3>');
      if (!warnings.length) {
        listHtml.push('<p class="dcb-warning-ok">No structural warnings.</p>');
      } else {
        listHtml.push('<ul class="dcb-warning-list">');
        warnings.forEach(function (w) {
          listHtml.push('<li>' + escapeHtml(w) + '</li>');
        });
        listHtml.push('</ul>');
      }
    }
    listHtml.push('</div></div>');
    $root.html(listHtml.join(''));
  }

  function renderTypeOptions(selected) {
    var all = (window.DCB_DF_BUILDER_ADMIN && window.DCB_DF_BUILDER_ADMIN.fieldTypes) || ['text', 'email', 'date', 'time', 'number', 'select', 'checkbox', 'radio', 'yes_no'];
    return all.map(function (type) {
      return '<option value="' + escapeHtml(type) + '" ' + (type === selected ? 'selected' : '') + '>' + escapeHtml(type) + '</option>';
    }).join('');
  }

  function persist(state, $hidden, $advanced) {
    var canonical = JSON.stringify(state.forms || {}, null, 2);
    $hidden.val(canonical);
    $advanced.val(canonical);
  }

  $(function () {
    var $hidden = $('#th-df-builder-json');
    var $advanced = $('#th-df-builder-json-advanced');
    var $root = $('#th-df-builder-root');
    var forms = tryParseJSON($hidden.val());
    var state = {
      forms: forms || {},
      selectedKey: ''
    };

    if (!state.forms) {
      $root.html('<p style="color:#b32d2e">Invalid builder JSON. Fix raw JSON and apply.</p>');
      return;
    }

    renderBuilder($root, state);

    $root.on('click', '[data-act="select-form"]', function () {
      state.selectedKey = String($(this).data('key') || '');
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="add-form"]', function () {
      var key = prompt('New form key (snake_case):', 'new_form');
      key = slugify(key || '');
      if (!key) return;
      if (state.forms[key]) {
        alert('That key already exists.');
        return;
      }
      state.forms[key] = makeDefaultForm(key);
      state.selectedKey = key;
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="duplicate-form"]', function () {
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      var next = slugify(key + '_copy');
      var n = 2;
      while (state.forms[next]) {
        next = slugify(key + '_copy_' + n);
        n += 1;
      }
      state.forms[next] = JSON.parse(JSON.stringify(state.forms[key]));
      if (!state.forms[next].label) state.forms[next].label = next;
      state.selectedKey = next;
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="delete-form"]', function () {
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      if (!confirm('Delete form "' + key + '"?')) return;
      delete state.forms[key];
      state.selectedKey = '';
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="field-add"]', function () {
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      var field = makeDefaultField();
      var idx = form.fields.length + 1;
      field.key = 'field_' + idx;
      field.label = 'Field ' + idx;
      form.fields.push(field);
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="field-delete"]', function () {
      var key = state.selectedKey;
      var i = Number($(this).data('i'));
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      if (i >= 0 && i < form.fields.length) {
        form.fields.splice(i, 1);
      }
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="field-conditions"]', function () {
      var key = state.selectedKey;
      var i = Number($(this).data('i'));
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      var field = form.fields[i];
      if (!field) return;
      var existing = JSON.stringify(Array.isArray(field.conditions) ? field.conditions : [], null, 2);
      var raw = prompt('Edit conditions JSON for field "' + (field.key || ('field #' + (i + 1))) + '"', existing);
      if (raw === null) return;
      var parsed = tryParseJSON(raw);
      if (!Array.isArray(parsed)) {
        alert('Conditions must be a JSON array.');
        return;
      }
      field.conditions = parsed;
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="section-add"]', function () {
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      var idx = form.sections.length + 1;
      form.sections.push({ key: 'section_' + idx, label: 'Section ' + idx, field_keys: [] });
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="step-add"]', function () {
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      var idx = form.steps.length + 1;
      form.steps.push({ key: 'step_' + idx, label: 'Step ' + idx, section_keys: [] });
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="repeater-add"]', function () {
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      var idx = form.repeaters.length + 1;
      form.repeaters.push({ key: 'repeater_' + idx, label: 'Repeater ' + idx, field_keys: [], min: 0, max: 3 });
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="section-delete"], [data-act="step-delete"], [data-act="repeater-delete"]', function () {
      var key = state.selectedKey;
      var i = Number($(this).data('i'));
      var act = String($(this).data('act') || '');
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      if (act === 'section-delete' && i >= 0 && i < form.sections.length) form.sections.splice(i, 1);
      if (act === 'step-delete' && i >= 0 && i < form.steps.length) form.steps.splice(i, 1);
      if (act === 'repeater-delete' && i >= 0 && i < form.repeaters.length) form.repeaters.splice(i, 1);
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="hardstop-add"]', function () {
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      form.hard_stops.push({ message: 'New hard stop', when: [] });
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('click', '[data-act="node-add-field"], [data-act="node-add-block"]', function () {
      var key = state.selectedKey;
      var act = String($(this).data('act') || '');
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      if (act === 'node-add-field') {
        var fieldKey = (form.fields[0] && form.fields[0].key) ? form.fields[0].key : '';
        form.document_nodes.push({ type: 'field', field_key: fieldKey });
      } else {
        var blockId = (form.template_blocks[0] && form.template_blocks[0].block_id) ? form.template_blocks[0].block_id : '';
        form.document_nodes.push({ type: 'block', block_id: blockId });
      }
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $root.on('input change', 'input[data-act], select[data-act], textarea[data-act]', function () {
      var act = String($(this).data('act') || '');
      var key = state.selectedKey;
      if (!key || !state.forms[key]) return;
      var form = normalizeFormShape(state.forms[key]);
      var idx = Number($(this).data('i'));
      var val = $(this).is(':checkbox') ? $(this).is(':checked') : String($(this).val() || '');

      if (act === 'set-label') form.label = val;
      if (act === 'set-recipients') form.recipients = val;
      if (act === 'set-key') {
        var next = slugify(val);
        if (next && next !== key && !state.forms[next]) {
          state.forms[next] = form;
          delete state.forms[key];
          state.selectedKey = next;
        }
      }

      if (idx >= 0 && form.fields[idx]) {
        if (act === 'field-key') form.fields[idx].key = slugify(val);
        if (act === 'field-label') form.fields[idx].label = val;
        if (act === 'field-type') form.fields[idx].type = val;
        if (act === 'field-required') form.fields[idx].required = !!val;
      }

      if (idx >= 0 && form.sections[idx]) {
        if (act === 'section-key') form.sections[idx].key = slugify(val);
        if (act === 'section-label') form.sections[idx].label = val;
        if (act === 'section-field-keys') form.sections[idx].field_keys = String(val || '').split(',').map(function (v) { return slugify(v); }).filter(Boolean);
      }

      if (idx >= 0 && form.steps[idx]) {
        if (act === 'step-key') form.steps[idx].key = slugify(val);
        if (act === 'step-label') form.steps[idx].label = val;
        if (act === 'step-section-keys') form.steps[idx].section_keys = String(val || '').split(',').map(function (v) { return slugify(v); }).filter(Boolean);
      }

      if (idx >= 0 && form.repeaters[idx]) {
        if (act === 'repeater-key') form.repeaters[idx].key = slugify(val);
        if (act === 'repeater-label') form.repeaters[idx].label = val;
        if (act === 'repeater-field-keys') form.repeaters[idx].field_keys = String(val || '').split(',').map(function (v) { return slugify(v); }).filter(Boolean);
        if (act === 'repeater-min') form.repeaters[idx].min = Math.max(0, Number(val) || 0);
        if (act === 'repeater-max') form.repeaters[idx].max = Math.max(form.repeaters[idx].min || 0, Number(val) || 0);
      }

      if (act === 'sections-json' || act === 'steps-json' || act === 'repeaters-json' || act === 'hardstops-json' || act === 'template-json' || act === 'nodes-json') {
        var parsed = tryParseJSON(val);
        if (parsed !== null) {
          if (act === 'sections-json') form.sections = Array.isArray(parsed) ? parsed : [];
          if (act === 'steps-json') form.steps = Array.isArray(parsed) ? parsed : [];
          if (act === 'repeaters-json') form.repeaters = Array.isArray(parsed) ? parsed : [];
          if (act === 'hardstops-json') form.hard_stops = Array.isArray(parsed) ? parsed : [];
          if (act === 'template-json') form.template_blocks = Array.isArray(parsed) ? parsed : [];
          if (act === 'nodes-json') form.document_nodes = Array.isArray(parsed) ? parsed : [];
        }
      }

      persist(state, $hidden, $advanced);
    });

    $root.on('click', '[data-act="export-json"]', function () {
      var blob = new Blob([JSON.stringify(state.forms || {}, null, 2)], { type: 'application/json' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'dcb-forms-export.json';
      a.click();
      URL.revokeObjectURL(url);
    });

    $root.on('click', '[data-act="import-json"]', function () {
      var raw = prompt('Paste exported JSON');
      if (!raw) return;
      var parsed = tryParseJSON(raw);
      if (!parsed) {
        alert('Invalid JSON.');
        return;
      }
      state.forms = parsed;
      state.selectedKey = Object.keys(parsed)[0] || '';
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $('#th-df-builder-apply-raw').on('click', function () {
      var parsed = tryParseJSON($advanced.val());
      if (!parsed) {
        alert('Invalid JSON. Please fix syntax and try again.');
        return;
      }
      state.forms = parsed;
      state.selectedKey = Object.keys(parsed)[0] || '';
      persist(state, $hidden, $advanced);
      renderBuilder($root, state);
    });

    $('form[action*="admin-post.php"]').on('submit', function () {
      var parsed = tryParseJSON($advanced.val());
      if (parsed) {
        $hidden.val(JSON.stringify(parsed));
      }
    });

    persist(state, $hidden, $advanced);
  });
})(jQuery);
