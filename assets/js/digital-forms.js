(function ($) {
  'use strict';

  var cfg = window.DCB_DIGITAL_FORMS || {};
  var forms = cfg.forms || {};
  var runtime = {
    activeFormKey: '',
    activeStepIndex: 0,
    drawnSignatureData: ''
  };

  function el(tag, attrs, text) {
    var $e = $('<' + tag + '>');
    Object.keys(attrs || {}).forEach(function (k) { $e.attr(k, attrs[k]); });
    if (typeof text === 'string') $e.text(text);
    return $e;
  }

  function buildField(field, value) {
    var key = field.key || '';
    var type = field.type || 'text';
    var label = field.label || key;
    var required = !!field.required;

    var $wrap = el('div', { class: 'th-df-field', 'data-key': key });
    $wrap.append(el('label', { for: 'th-df-' + key, class: 'th-df-label' }, label + (required ? ' *' : '')));

    var $input;
    if (type === 'textarea') {
      $input = el('textarea', { id: 'th-df-' + key, name: key, class: 'th-df-input', rows: 4 });
      $input.val(value || '');
    } else if (type === 'select' || type === 'yes_no' || type === 'radio') {
      $input = el('select', { id: 'th-df-' + key, name: key, class: 'th-df-input' });
      $input.append(el('option', { value: '' }, 'Select...'));
      var opts = field.options || {};
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

  function signaturePanelHTML(currentUserName) {
    return '<div class="th-df-signature-wrap">'
      + '<h3>Signature</h3>'
      + '<label class="th-df-label">Signature Mode</label>'
      + '<select class="th-df-select" id="dcb-signature-mode">'
      + '<option value="typed">Typed</option>'
      + '<option value="drawn">Drawn</option>'
      + '</select>'
      + '<label class="th-df-label" for="dcb-signer-identity">Signer Identity</label>'
      + '<input type="text" id="dcb-signer-identity" class="th-df-input" value="' + (currentUserName || '') + '" />'
      + '<div id="dcb-signature-typed-wrap">'
      + '<label class="th-df-label" for="dcb-signature-typed">Typed Signature</label>'
      + '<input type="text" id="dcb-signature-typed" class="th-df-input" placeholder="Type your full name" />'
      + '</div>'
      + '<div id="dcb-signature-drawn-wrap" hidden>'
      + '<label class="th-df-label">Drawn Signature</label>'
      + '<canvas id="dcb-signature-canvas" width="540" height="170" style="width:100%;height:170px;border:1px solid #c8d3e6;border-radius:8px;background:#fff"></canvas>'
      + '<div style="margin-top:6px;"><button type="button" class="th-df-btn" id="dcb-signature-clear">Clear Signature</button></div>'
      + '</div>'
      + '</div>';
  }

  function setupSignatureCanvas() {
    var canvas = document.getElementById('dcb-signature-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false;

    function point(evt) {
      var rect = canvas.getBoundingClientRect();
      var x = (evt.touches ? evt.touches[0].clientX : evt.clientX) - rect.left;
      var y = (evt.touches ? evt.touches[0].clientY : evt.clientY) - rect.top;
      return { x: x, y: y };
    }

    function start(evt) {
      drawing = true;
      var p = point(evt);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      evt.preventDefault();
    }

    function move(evt) {
      if (!drawing) return;
      var p = point(evt);
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#1d2c44';
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      evt.preventDefault();
    }

    function stop() {
      if (!drawing) return;
      drawing = false;
      runtime.drawnSignatureData = canvas.toDataURL('image/png');
    }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', stop);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', stop);

    $('#dcb-signature-clear').on('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      runtime.drawnSignatureData = '';
    });
  }

  function parseArrayJSON(value, fallback) {
    if (Array.isArray(value)) return value;
    return fallback || [];
  }

  function resolveFieldOrder(form) {
    var fields = Array.isArray(form.fields) ? form.fields : [];
    var sections = parseArrayJSON(form.sections, []);
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

  function buildStepHeader(form, stepIndex) {
    var steps = parseArrayJSON(form.steps, []);
    if (!steps.length) return null;
    var labels = steps.map(function (step, i) {
      var cls = i === stepIndex ? 'is-active' : '';
      return '<span class="th-df-step-pill ' + cls + '">' + (step.label || ('Step ' + (i + 1))) + '</span>';
    });
    return $('<div class="th-df-steps">' + labels.join('') + '</div>');
  }

  function fieldAllowedInStep(form, field, stepIndex) {
    var steps = parseArrayJSON(form.steps, []);
    if (!steps.length) return true;
    var sections = parseArrayJSON(form.sections, []);
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

    var fields = resolveFieldOrder(form);

    $.post(cfg.ajaxUrl, {
      action: 'dcb_get_digital_form_draft',
      nonce: cfg.draftNonce,
      form_key: formKey
    }).done(function (res) {
      var draft = res && res.success && res.data && res.data.draft ? (res.data.draft.fields || {}) : {};
      var $stepHeader = buildStepHeader(form, runtime.activeStepIndex);
      if ($stepHeader) $form.append($stepHeader);

      fields.forEach(function (field) {
        if (!fieldAllowedInStep(form, field, runtime.activeStepIndex)) return;
        var v = Object.prototype.hasOwnProperty.call(draft, field.key) ? draft[field.key] : '';
        $form.append(buildField(field, v));
      });
      $form.append('<input type="hidden" name="signature_mode" value="typed"/>');

      var steps = parseArrayJSON(form.steps, []);
      if (steps.length > 1) {
        $form.append('<div class="th-df-step-actions">'
          + '<button type="button" class="th-df-btn th-df-prev-step" disabled>Previous</button>'
          + '<button type="button" class="th-df-btn th-df-next-step">Next</button>'
          + '</div>');
      }

      $form.append(signaturePanelHTML((cfg.currentUser && cfg.currentUser.name) || ''));
      setupSignatureCanvas();
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

  $(function () {
    var $select = $('#th-df-form-key');
    var $form = $('#th-df-form');
    var $status = $('#th-df-status');

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

    $form.on('change', '#dcb-signature-mode', function () {
      var mode = String($(this).val() || 'typed');
      var isDrawn = mode === 'drawn';
      $('#dcb-signature-typed-wrap').attr('hidden', isDrawn);
      $('#dcb-signature-drawn-wrap').attr('hidden', !isDrawn);
    });

    $('#th-df-submit').on('click', function () {
      var formKey = String($select.val() || '');
      if (!formKey) return;

      var fields = collectFields($form);
      var signatureMode = String($('#dcb-signature-mode').val() || 'typed');
      var signerIdentity = String($('#dcb-signer-identity').val() || '').trim();
      var typedSignature = String($('#dcb-signature-typed').val() || '').trim();
      if (typedSignature && !fields.signature_name) {
        fields.signature_name = typedSignature;
      }
      $status.text('Submitting...');
      $('#th-df-errors').empty().attr('hidden', true);

      $.post(cfg.ajaxUrl, {
        action: 'dcb_submit_digital_form',
        nonce: cfg.nonce,
        form_key: formKey,
        fields: JSON.stringify(fields),
        signature_mode: signatureMode,
        signature_drawn_data: signatureMode === 'drawn' ? runtime.drawnSignatureData : '',
        signer_identity: signerIdentity || ((cfg.currentUser && cfg.currentUser.name) || ''),
        signature_timestamp: new Date().toISOString(),
        consent_text_version: (cfg.signature && cfg.signature.consentTextVersion) || '',
        attestation_text_version: (cfg.signature && cfg.signature.attestationTextVersion) || ''
      }).done(function (res) {
        if (!res || !res.success) {
          var errors = res && res.data && res.data.errors ? res.data.errors : [(res && res.data && res.data.message) || 'Submission failed.'];
          showErrors(errors);
          $status.text('Submission failed.');
          return;
        }

        $form[0].reset();
        $status.text((res.data && res.data.message) || 'Submitted successfully.');
      }).fail(function () {
        $status.text('Submission failed.');
      });
    });
  });
})(jQuery);
