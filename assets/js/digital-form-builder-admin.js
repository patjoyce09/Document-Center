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
            + '<td><button type="button" class="button-link-delete" data-act="field-delete" data-i="' + i + '">Delete</button></td>'
            + '</tr>');
        });
      }
      listHtml.push('</tbody></table><p><button type="button" class="button" data-act="field-add">Add Field</button></p>');

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
