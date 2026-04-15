(function ($) {
  'use strict';

  var cfg = window.DCB_DIGITAL_FORMS || {};
  var forms = cfg.forms || {};

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

    var fields = Array.isArray(form.fields) ? form.fields : [];

    $.post(cfg.ajaxUrl, {
      action: 'dcb_get_digital_form_draft',
      nonce: cfg.draftNonce,
      form_key: formKey
    }).done(function (res) {
      var draft = res && res.success && res.data && res.data.draft ? (res.data.draft.fields || {}) : {};
      fields.forEach(function (field) {
        var v = Object.prototype.hasOwnProperty.call(draft, field.key) ? draft[field.key] : '';
        $form.append(buildField(field, v));
      });
      $form.append('<input type="hidden" name="signature_mode" value="typed"/>');
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
        signer_identity: (cfg.currentUser && cfg.currentUser.name) || ''
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
