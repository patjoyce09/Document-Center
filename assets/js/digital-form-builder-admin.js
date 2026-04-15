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

  function renderList($root, forms) {
    var keys = Object.keys(forms || {});
    if (!keys.length) {
      $root.html('<p>No forms configured yet. Add JSON in Advanced mode, then click Apply.</p>');
      return;
    }

    var html = ['<table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Fields</th><th>Version</th></tr></thead><tbody>'];
    keys.forEach(function (key) {
      var form = forms[key] || {};
      var fields = Array.isArray(form.fields) ? form.fields.length : 0;
      html.push(
        '<tr>' +
          '<td><code>' + key + '</code></td>' +
          '<td>' + (form.label || key) + '</td>' +
          '<td>' + fields + '</td>' +
          '<td>' + (form.version || 1) + '</td>' +
        '</tr>'
      );
    });
    html.push('</tbody></table>');
    $root.html(html.join(''));
  }

  $(function () {
    var $hidden = $('#th-df-builder-json');
    var $advanced = $('#th-df-builder-json-advanced');
    var $root = $('#th-df-builder-root');
    var forms = tryParseJSON($hidden.val());

    if (!forms) {
      $root.html('<p style="color:#b32d2e">Invalid builder JSON. Fix raw JSON and apply.</p>');
      return;
    }

    renderList($root, forms);

    $('#th-df-builder-apply-raw').on('click', function () {
      var parsed = tryParseJSON($advanced.val());
      if (!parsed) {
        alert('Invalid JSON. Please fix syntax and try again.');
        return;
      }
      forms = parsed;
      var canonical = JSON.stringify(forms, null, 2);
      $hidden.val(canonical);
      $advanced.val(canonical);
      renderList($root, forms);
    });

    $('form[action*="admin-post.php"]').on('submit', function () {
      var parsed = tryParseJSON($advanced.val());
      if (parsed) {
        $hidden.val(JSON.stringify(parsed));
      }
    });
  });
})(jQuery);
