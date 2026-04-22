(function ($) {
  'use strict';

  var cfg = window.DCB_DIGITAL_FORMS || {};
  var forms = cfg.forms || {};
  var query = new URLSearchParams(window.location.search || '');
  var intakeContext = {
    formKey: String(query.get('dcb_form') || ''),
    uploadLogId: String(query.get('dcb_upload_log') || ''),
    reviewId: String(query.get('dcb_review') || ''),
    traceId: String(query.get('dcb_trace') || ''),
    sourceChannel: String(query.get('dcb_channel') || ''),
    captureType: String(query.get('dcb_capture') || '')
  };
  var runtime = {
    activeFormKey: '',
    activeStepIndex: 0
  };

  function el(tag, attrs, text) {
    var $e = $('<' + tag + '>');
    Object.keys(attrs || {}).forEach(function (k) { $e.attr(k, attrs[k]); });
    if (typeof text === 'string') $e.text(text);
    return $e;
  }

  function asArray(v) {
    return Array.isArray(v) ? v : [];
  }

  function asObject(v) {
    return v && typeof v === 'object' ? v : {};
  }

  function pickFirstDefined(obj, keys, fallback) {
    var source = asObject(obj);
    for (var i = 0; i < keys.length; i += 1) {
      var k = keys[i];
      if (Object.prototype.hasOwnProperty.call(source, k) && source[k] !== undefined && source[k] !== null) {
        return source[k];
      }
    }
    return fallback;
  }

  function formArray(form, snakeKey, camelKey) {
    return asArray(pickFirstDefined(form, [snakeKey, camelKey], []));
  }

  function formObject(form, snakeKey, camelKey) {
    return asObject(pickFirstDefined(form, [snakeKey, camelKey], {}));
  }

  function formSteps(form) {
    return formArray(form, 'steps', 'steps');
  }

  function formSections(form) {
    return formArray(form, 'sections', 'sections');
  }

  function hasUsableGeometry(hint) {
    var g = asObject(hint && hint.geometry);
    return Number(g.w || 0) > 0.02 && Number(g.h || 0) > 0.01;
  }

  function resolveBackgroundForStep(form, stepIndex) {
    var bg = formObject(form, 'digital_twin_background', 'digitalTwinBackground');
    var pages = asArray(bg.pages);
    var targetPage = Math.max(1, Number(stepIndex || 0) + 1);

    if (!pages.length) {
      return {
        page_number: 1,
        image_url: String(bg.image_url || '')
      };
    }

    var row = pages.find(function (p) { return Number(p && p.page_number || 1) === targetPage; }) || pages[0] || {};
    return {
      page_number: Math.max(1, Number(row.page_number || targetPage)),
      image_url: String(row.image_url || '')
    };
  }

  function slugify(v) {
    return String(v || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
  }

  function normalizeRegion(v) {
    var region = slugify(v || 'left');
    return region || 'left';
  }

  function resolveFieldHint(form, field) {
    var hints = formObject(form, 'digital_twin_hints', 'digitalTwinHints');
    var rows = asArray(hints.field_layout);
    var key = String(field && field.key || '');
    if (!key) return {};
    var row = rows.find(function (r) { return String((r && r.field_key) || '') === key; }) || {};
    var meta = asObject(field && field.ocr_meta);
    var geometry = asObject(meta.geometry);
    return {
      page_number: Number(row.page_number || meta.page_number || 1),
      region_hint: normalizeRegion(row.region_hint || meta.region_hint || 'left'),
      confidence_bucket: String(row.confidence_bucket || meta.confidence_bucket || 'low'),
      geometry: {
        x: Math.max(0, Math.min(1, Number(geometry.x || 0))),
        y: Math.max(0, Math.min(1, Number(geometry.y || 0))),
        w: Math.max(0, Math.min(1, Number(geometry.w || 0))),
        h: Math.max(0, Math.min(1, Number(geometry.h || 0))),
        unit: String(geometry.unit || 'page_ratio')
      }
    };
  }

  function renderTemplateBlock(block) {
    block = asObject(block);
    var type = String(block.type || 'paragraph');
    var text = String(block.text || '');
    if (type === 'heading' || type === 'section_header') {
      var level = Math.max(1, Math.min(6, Number(block.level || (type === 'heading' ? 2 : 3))));
      return el('h' + level, { class: 'th-df-block th-df-block-' + type }, text || '');
    }
    if (type === 'divider') {
      return $('<hr class="th-df-block th-df-block-divider" />');
    }
    if (type === 'spacer') {
      var height = Math.max(8, Number(block.height || 20));
      return el('div', { class: 'th-df-block th-df-block-spacer', style: 'height:' + height + 'px' });
    }
    if (type === 'info_row') {
      var $row = el('div', { class: 'th-df-block th-df-block-info-row' });
      asArray(block.columns).forEach(function (col) {
        var c = asObject(col);
        var $col = el('div', { class: 'th-df-info-col' });
        $col.append(el('div', { class: 'th-df-info-label' }, String(c.label || '')));
        $col.append(el('div', { class: 'th-df-info-value' }, String(c.value || '')));
        $row.append($col);
      });
      return $row;
    }
    if (type === 'labeled_value_row') {
      var $lv = el('div', { class: 'th-df-block th-df-block-labeled-value' });
      $lv.append(el('span', { class: 'th-df-lv-label' }, String(block.label || 'Label')));
      $lv.append(el('span', { class: 'th-df-lv-value' }, String(block.value_text || '')));
      return $lv;
    }
    if (type === 'two_column_row') {
      var $two = el('div', { class: 'th-df-block th-df-block-two-col' });
      $two.append(el('div', { class: 'th-df-two-col-left' }, String(block.left_text || '')));
      $two.append(el('div', { class: 'th-df-two-col-right' }, String(block.right_text || '')));
      return $two;
    }
    if (type === 'image_placeholder') {
      var src = String(block.image_url || '');
      if (src) {
        return $('<img class="th-df-block th-df-block-image" alt="" />').attr('src', src).attr('alt', String(block.alt || ''));
      }
      return el('div', { class: 'th-df-block th-df-block-image-placeholder' }, String(block.alt || 'Logo / Image'));
    }
    return el('p', { class: 'th-df-block th-df-block-paragraph' }, text || '');
  }

  function buildField(field, value, hint, useOverlayPosition) {
    var key = field.key || '';
    var type = field.type || 'text';
    var label = field.label || key;
    var required = !!field.required;
    var pageNumber = Math.max(1, Number((hint && hint.page_number) || 1));
    var region = normalizeRegion((hint && hint.region_hint) || 'left');

    var $wrap = el('div', {
      class: 'th-df-field th-df-field--region-' + region,
      'data-key': key,
      'data-page': String(pageNumber)
    });

    if (useOverlayPosition && hint && hint.geometry && Number(hint.geometry.w || 0) > 0.02 && Number(hint.geometry.h || 0) > 0.01) {
      var gx = Math.max(0, Math.min(1, Number(hint.geometry.x || 0)));
      var gy = Math.max(0, Math.min(1, Number(hint.geometry.y || 0)));
      var gw = Math.max(0.02, Math.min(1, Number(hint.geometry.w || 0)));
      $wrap.addClass('th-df-field--overlay').css({
        position: 'absolute',
        left: (gx * 100) + '%',
        top: (gy * 100) + '%',
        width: (gw * 100) + '%',
        margin: 0,
        zIndex: 5
      });
    }
    $wrap.append(el('label', { for: 'th-df-' + key, class: 'th-df-label' }, label + (required ? ' *' : '')));

    var $input;
    if (type === 'textarea') {
      $input = el('textarea', { id: 'th-df-' + key, name: key, class: 'th-df-input', rows: 4 });
      $input.val(value || '');
    } else if (type === 'select' || type === 'yes_no' || type === 'radio') {
      $input = el('select', { id: 'th-df-' + key, name: key, class: 'th-df-input' });
      $input.append(el('option', { value: '' }, 'Select...'));
      var opts = asObject(field.options);
      if (type === 'yes_no' && !Object.keys(opts).length) {
        opts = { yes: 'Yes', no: 'No' };
      }
      Object.keys(opts).forEach(function (ov) {
        var $opt = el('option', { value: ov }, opts[ov]);
        if ((value || '') === ov) $opt.attr('selected', 'selected');
        $input.append($opt);
      });
    } else if (type === 'checkbox') {
      $input = el('input', { id: 'th-df-' + key, name: key, type: 'checkbox', value: '1', class: 'th-df-checkbox' });
      if ((value || '') === '1') $input.prop('checked', true);
    } else if (type === 'date') {
      $input = el('input', { id: 'th-df-' + key, name: key, type: 'date', class: 'th-df-input', value: value || '' });
    } else {
      $input = el('input', { id: 'th-df-' + key, name: key, type: 'text', class: 'th-df-input', value: value || '' });
    }

    if (required) {
      $input.attr('required', 'required');
      $input.attr('aria-required', 'true');
    }

    $wrap.append($input);
    return $wrap;
  }

  function collectFields($form) {
    var payload = {};
    $form.find('[name]').each(function () {
      var $input = $(this);
      var key = $input.attr('name');
      if (!key) return;
      if ($input.is(':checkbox')) {
        payload[key] = $input.is(':checked') ? '1' : '';
      } else {
        payload[key] = String($input.val() || '').trim();
      }
    });
    return payload;
  }

  function parseArrayJSON(value, fallback) {
    if (Array.isArray(value)) return value;
    return fallback || [];
  }

  function resolveFieldOrder(form) {
    var fields = Array.isArray(form.fields) ? form.fields : [];
    var sections = parseArrayJSON(formSections(form), []);
    var ordered = [];
    var seen = {};

    sections.forEach(function (section) {
      var keys = Array.isArray(section.field_keys) ? section.field_keys : [];
      keys.forEach(function (key) {
        var match = fields.find(function (f) { return String(f.key || '') === String(key || ''); });
        if (match && !seen[match.key]) {
          ordered.push(match);
          seen[match.key] = true;
        }
      });
    });

    fields.forEach(function (f) {
      if (!seen[f.key]) {
        ordered.push(f);
      }
    });

    return ordered;
  }

  function resolveDocumentPlan(form) {
    var fields = resolveFieldOrder(form);
    var fieldByKey = {};
    fields.forEach(function (f) {
      var key = String((f && f.key) || '');
      if (key) fieldByKey[key] = f;
    });

    var blockById = {};
    formArray(form, 'template_blocks', 'templateBlocks').forEach(function (b) {
      var block = asObject(b);
      var id = String(block.block_id || '');
      if (id) blockById[id] = block;
    });

    var nodes = formArray(form, 'document_nodes', 'documentNodes');
    var resolved = [];
    if (nodes.length) {
      nodes.forEach(function (node) {
        var n = asObject(node);
        if (String(n.type || '') === 'field') {
          var fieldKey = String(n.field_key || '');
          if (fieldByKey[fieldKey]) resolved.push({ type: 'field', field: fieldByKey[fieldKey] });
          return;
        }
        if (String(n.type || '') === 'block') {
          var blockId = String(n.block_id || '');
          if (blockById[blockId]) resolved.push({ type: 'block', block: blockById[blockId] });
        }
      });
    }

    if (!resolved.length) {
      fields.forEach(function (field) {
        resolved.push({ type: 'field', field: field });
      });
    }

    return resolved;
  }

  function buildStepHeader(form, stepIndex) {
    var steps = parseArrayJSON(formSteps(form), []);
    if (!steps.length) return null;
    var labels = steps.map(function (step, i) {
      var cls = i === stepIndex ? 'is-active' : '';
      return '<span class="th-df-step-pill ' + cls + '">' + (step.label || ('Step ' + (i + 1))) + '</span>';
    });
    return $('<div class="th-df-steps">' + labels.join('') + '</div>');
  }

  function fieldAllowedInStep(form, field, stepIndex) {
    var steps = parseArrayJSON(formSteps(form), []);
    if (!steps.length) return true;
    var sections = parseArrayJSON(formSections(form), []);
    var step = steps[stepIndex] || steps[0] || null;
    if (!step) return true;
    var stepSectionKeys = Array.isArray(step.section_keys) ? step.section_keys.map(String) : [];
    if (!stepSectionKeys.length) return true;

    var inSection = false;
    sections.forEach(function (section) {
      var sKey = String(section.key || '');
      if (!stepSectionKeys.includes(sKey)) return;
      var keys = Array.isArray(section.field_keys) ? section.field_keys.map(String) : [];
      if (keys.includes(String(field.key || ''))) {
        inSection = true;
      }
    });
    return inSection;
  }

  function renderForm(formKey) {
    var form = forms[formKey] || null;
    var $form = $('#th-df-form');
    var $submit = $('#th-df-submit');
    var $status = $('#th-df-status');
    var $errors = $('#th-df-errors');

    $form.empty();
    $status.text('');
    $errors.empty().attr('hidden', true);
    $submit.prop('disabled', !form);

    if (!form) return;
    runtime.activeFormKey = formKey;
    runtime.activeStepIndex = 0;

    var plan = resolveDocumentPlan(form);
    var bgForStep = resolveBackgroundForStep(form, runtime.activeStepIndex);
    var backgroundImageUrl = String(bgForStep.image_url || '');
    var overlayPageNumber = Math.max(1, Number(bgForStep.page_number || 1));

    $.post(cfg.ajaxUrl, {
      action: 'dcb_get_digital_form_draft',
      nonce: cfg.draftNonce,
      form_key: formKey
    }).done(function (res) {
      var draft = res && res.success && res.data && res.data.draft ? (res.data.draft.fields || {}) : {};
      var $stepHeader = buildStepHeader(form, runtime.activeStepIndex);
      if ($stepHeader) $form.append($stepHeader);

      var $paper = $('<div class="th-df-paper"></div>');
      var $overlayStage = null;
      var $overlayFields = null;
      var useOverlay = backgroundImageUrl !== '';
      if (useOverlay) {
        $overlayStage = $('<div class="th-df-overlay-stage"></div>');
        $overlayStage.append($('<img class="th-df-overlay-background" alt="Scanned form background" />').attr('src', backgroundImageUrl));
        $overlayFields = $('<div class="th-df-overlay-fields"></div>');
        $overlayStage.append($overlayFields);
      }
      var renderedFieldCount = 0;
      plan.forEach(function (node) {
        if (node.type === 'block') {
          $paper.append(renderTemplateBlock(node.block));
          return;
        }
        if (node.type !== 'field') return;

        var field = node.field;
        if (!fieldAllowedInStep(form, field, runtime.activeStepIndex)) return;
        var v = Object.prototype.hasOwnProperty.call(draft, field.key) ? draft[field.key] : '';
        var hint = resolveFieldHint(form, field);
        var $fieldNode = buildField(field, v, hint, useOverlay);
        if (useOverlay && Number(hint.page_number || 1) !== overlayPageNumber) {
          return;
        }

        if (useOverlay && $overlayFields && hasUsableGeometry(hint)) {
          $overlayFields.append($fieldNode);
        } else {
          $paper.append($fieldNode);
        }
        renderedFieldCount += 1;
      });

      if (useOverlay && $overlayStage) {
        $paper.append($overlayStage);
      }

      if (!renderedFieldCount) {
        $paper.append('<p class="th-df-empty-state">No fillable fields detected for this step.</p>');
      }
      $form.append($paper);

      $form.append('<input type="hidden" name="signature_mode" value="typed"/>');

      var steps = parseArrayJSON(formSteps(form), []);
      if (steps.length > 1) {
        $form.append('<div class="th-df-step-actions">'
          + '<button type="button" class="th-df-btn th-df-prev-step" disabled>Previous</button>'
          + '<button type="button" class="th-df-btn th-df-next-step">Next</button>'
          + '</div>');
      }
    });
  }

  function showErrors(errors) {
    var $errors = $('#th-df-errors');
    var list = Array.isArray(errors) ? errors : ['Unknown error'];
    $errors.empty();
    list.forEach(function (e) {
      $errors.append($('<li>').text(e));
    });
    $errors.attr('hidden', false);
  }

  function fieldDisplayValue(field, raw) {
    var type = String((field && field.type) || 'text');
    var value = String(raw || '');
    if (type === 'checkbox') return value === '1' ? 'Yes' : 'No';
    if (type === 'select' || type === 'radio' || type === 'yes_no') {
      var opts = asObject(field && field.options);
      if (value && Object.prototype.hasOwnProperty.call(opts, value)) return String(opts[value] || value);
      return value;
    }
    return value;
  }

  function buildPrintableSubmissionHtml(form, fields) {
    var safeForm = asObject(form);
    var values = asObject(fields);
    var plan = resolveDocumentPlan(safeForm);
    var html = [];
    html.push('<!doctype html><html><head><meta charset="utf-8" />');
    html.push('<title>Filled Form</title>');
    html.push('<style>body{font-family:Arial,sans-serif;padding:18px;color:#1f2f48}h1,h2,h3{margin:0 0 10px}hr{border:0;border-top:1px solid #d6e0ef;margin:10px 0} .row{display:grid;grid-template-columns:280px 1fr;gap:10px;padding:6px 0;border-bottom:1px solid #eef2f9} .k{font-weight:700;color:#334a6f} .v{white-space:pre-wrap} .small{color:#5d6f8b;font-size:12px;margin-bottom:10px}</style>');
    html.push('</head><body>');
    html.push('<h2>' + String((safeForm.label || 'Filled Form')).replace(/</g, '&lt;') + '</h2>');
    html.push('<p class="small">Generated from portal submission. Use your browser Print dialog and choose Save as PDF.</p>');

    plan.forEach(function (node) {
      if (node.type === 'block') {
        var b = asObject(node.block);
        var bt = String(b.type || 'paragraph');
        var txt = String(b.text || '').replace(/</g, '&lt;');
        if (bt === 'heading' || bt === 'section_header') html.push('<h3>' + txt + '</h3>');
        else if (bt === 'divider') html.push('<hr/>');
        else if (txt) html.push('<p>' + txt + '</p>');
        return;
      }
      if (node.type !== 'field') return;
      var f = asObject(node.field);
      var key = String(f.key || '');
      var label = String(f.label || key || 'Field');
      var val = fieldDisplayValue(f, values[key]);
      html.push('<div class="row"><div class="k">' + label.replace(/</g, '&lt;') + '</div><div class="v">' + String(val || '').replace(/</g, '&lt;') + '</div></div>');
    });

    html.push('</body></html>');
    return html.join('');
  }

  function openPrintablePdfView(form, fields) {
    var html = buildPrintableSubmissionHtml(form, fields);
    var w = window.open('', '_blank');
    if (!w) return false;
    w.document.open();
    w.document.write(html);
    w.document.close();
    setTimeout(function () {
      try { w.focus(); w.print(); } catch (e) { /* noop */ }
    }, 300);
    return true;
  }

  $(function () {
    var $select = $('#th-df-form-key');
    var $form = $('#th-df-form');
    var $status = $('#th-df-status');
    var $downloadPdf = $('#th-df-download-pdf');
    var lastSubmitted = { formKey: '', fields: {} };

    $select.on('change', function () {
      renderForm(String($(this).val() || ''));
    });

    var saveTimer = null;
    $form.on('input change', '[name]', function () {
      var formKey = String($select.val() || '');
      if (!formKey) return;

      clearTimeout(saveTimer);
      saveTimer = setTimeout(function () {
        $.post(cfg.ajaxUrl, {
          action: 'dcb_save_digital_form_draft',
          nonce: cfg.draftNonce,
          form_key: formKey,
          fields: JSON.stringify(collectFields($form))
        });
      }, 500);
    });

    $form.on('click', '.th-df-next-step, .th-df-prev-step', function () {
      var form = forms[runtime.activeFormKey] || null;
      if (!form) return;
      var steps = parseArrayJSON(form.steps, []);
      if (!steps.length) return;

      if ($(this).hasClass('th-df-next-step')) {
        runtime.activeStepIndex = Math.min(steps.length - 1, runtime.activeStepIndex + 1);
      } else {
        runtime.activeStepIndex = Math.max(0, runtime.activeStepIndex - 1);
      }

      renderForm(runtime.activeFormKey);
    });

    $('#th-df-submit').on('click', function () {
      var formKey = String($select.val() || '');
      if (!formKey) return;

      var fields = collectFields($form);
      $status.text('Submitting...');
      $('#th-df-errors').empty().attr('hidden', true);

      $.post(cfg.ajaxUrl, {
        action: 'dcb_submit_digital_form',
        nonce: cfg.nonce,
        form_key: formKey,
        fields: JSON.stringify(fields),
        signature_mode: 'typed',
        signer_identity: (cfg.currentUser && cfg.currentUser.name) || '',
        intake_upload_log_id: intakeContext.uploadLogId,
        intake_review_id: intakeContext.reviewId,
        intake_trace_id: intakeContext.traceId,
        intake_source_channel: intakeContext.sourceChannel,
        intake_capture_type: intakeContext.captureType
      }).done(function (res) {
        if (!res || !res.success) {
          var errors = res && res.data && res.data.errors ? res.data.errors : [(res && res.data && res.data.message) || 'Submission failed.'];
          showErrors(errors);
          $status.text('Submission failed.');
          return;
        }

        $form[0].reset();
        lastSubmitted.formKey = formKey;
        lastSubmitted.fields = fields;
        $downloadPdf.prop('disabled', false);
        $status.text((res.data && res.data.message) || 'Submitted successfully.');
      }).fail(function () {
        $status.text('Submission failed.');
      });
    });

    $downloadPdf.on('click', function () {
      var formKey = String(lastSubmitted.formKey || $select.val() || '');
      if (!formKey || !forms[formKey]) return;
      var exportFields = Object.keys(lastSubmitted.fields || {}).length ? lastSubmitted.fields : collectFields($form);
      openPrintablePdfView(forms[formKey], exportFields);
    });

    if (intakeContext.formKey && Object.prototype.hasOwnProperty.call(forms, intakeContext.formKey)) {
      $select.val(intakeContext.formKey);
      renderForm(intakeContext.formKey);
    }
  });
})(jQuery);
