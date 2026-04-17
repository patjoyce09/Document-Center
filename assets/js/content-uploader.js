(function ($) {
  'use strict';

  var cfg = window.DCB_UPLOAD_CONFIG || {};
  var selectedFiles = [];

  function toTitle(value) {
    var text = String(value || '').replace(/_/g, ' ').trim();
    if (!text) return 'Unknown';
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  function badge(text, kind) {
    return $('<span>').addClass('th-upload-badge th-upload-badge-' + kind).text(text);
  }

  function renderIntakeAlert(rows) {
    var $alert = $('#th-upload-intake-alert');
    var totalWarnings = 0;
    var recommendationSet = {};

    (rows || []).forEach(function (row) {
      totalWarnings += Number(row.captureWarningCount || 0);
      (row.captureRecommendations || []).forEach(function (tip) {
        var t = String(tip || '').trim();
        if (t) recommendationSet[t] = true;
      });
    });

    var recommendations = Object.keys(recommendationSet);
    if (!totalWarnings && !recommendations.length) {
      $alert.empty().attr('hidden', true);
      return;
    }

    $alert.empty().append(
      $('<p>').append($('<strong>').text('Capture guidance: ')).append(
        document.createTextNode(
          totalWarnings > 0
            ? totalWarnings + ' capture warning' + (totalWarnings === 1 ? '' : 's') + ' detected across this batch.'
            : 'No major capture warnings were detected.'
        )
      )
    );

    if (recommendations.length) {
      var $ul = $('<ul>');
      recommendations.slice(0, 5).forEach(function (tip) {
        $ul.append($('<li>').text(tip));
      });
      $alert.append($ul);
    }

    $alert.attr('hidden', false);
  }

  function renderFileList() {
    var $list = $('#th-upload-file-list');
    if (!selectedFiles.length) {
      $list.empty().attr('hidden', true);
      $('#th-upload-submit').attr('hidden', true);
      return;
    }

    $list.empty();
    selectedFiles.forEach(function (file) {
      $list.append($('<li>').text(file.name + ' (' + Math.round(file.size / 1024) + ' KB)'));
    });
    $list.attr('hidden', false);
    $('#th-upload-submit').attr('hidden', false);
  }

  function appendResults(rows) {
    var $tbody = $('#th-upload-results');
    $tbody.empty();

    (rows || []).forEach(function (row) {
      var statusText = row.status || 'unknown';
      if (row.error) statusText += ' — ' + row.error;

      var warningCount = Number(row.captureWarningCount || 0);
      var confidence = typeof row.confidence === 'number' ? row.confidence.toFixed(3) : String(row.confidence || '0.000');
      var confidenceBucket = toTitle(row.confidenceBucket || 'low');
      var sourceType = toTitle(row.inputSourceType || 'unknown');
      var sourceChannel = toTitle(row.sourceChannel || 'direct_upload');
      var captureRisk = toTitle(row.captureRiskBucket || (warningCount > 0 ? 'moderate' : 'clean'));

      var $guidance = $('<div>').addClass('th-upload-guidance-cell');
      $guidance.append(badge(captureRisk, warningCount > 0 ? 'warn' : 'ok'));
      $guidance.append($('<span>').addClass('th-upload-guidance-meta').text(sourceChannel + ' • ' + sourceType + ' • ' + warningCount + ' warning' + (warningCount === 1 ? '' : 's')));

      var recommendations = (row.captureRecommendations || []).slice(0, 2);
      if (recommendations.length) {
        var $tips = $('<ul>').addClass('th-upload-guidance-list');
        recommendations.forEach(function (tip) {
          $tips.append($('<li>').text(String(tip || '')));
        });
        $guidance.append($tips);
      }

      $tbody.append(
        $('<tr>')
          .append($('<td>').text(row.file || ''))
          .append($('<td>').text(row.detectedType || ''))
          .append(
            $('<td>').append(
              $('<div>').addClass('th-upload-confidence-cell')
                .append(badge(confidenceBucket, confidenceBucket.toLowerCase() === 'high' ? 'ok' : (confidenceBucket.toLowerCase() === 'medium' ? 'info' : 'warn')))
                .append($('<span>').addClass('th-upload-confidence-value').text(confidence))
            )
          )
          .append($('<td>').append($guidance))
          .append($('<td>').text(row.sentTo || ''))
          .append($('<td>').text(statusText))
      );
    });

    $('#th-upload-results-wrap').attr('hidden', false);
    renderIntakeAlert(rows || []);
  }

  $(function () {
    var $input = $('#th-upload-files');
    var $status = $('#th-upload-status');
    var $hint = $('#th-upload-type-hint');
    var $channel = $('#th-upload-channel');

    $input.on('change', function () {
      selectedFiles = Array.from(this.files || []);
      renderFileList();
    });

    $('#th-upload-submit').on('click', function () {
      if (!selectedFiles.length) return;

      var fd = new FormData();
      fd.append('action', 'dcb_upload_files');
      fd.append('nonce', cfg.nonce || '');
      fd.append('typeHint', String($hint.val() || ''));
      fd.append('intakeChannel', String($channel.val() || 'direct_upload'));

      selectedFiles.forEach(function (file) {
        fd.append('files[]', file, file.name);
      });

      $status.text('Uploading...');

      $.ajax({
        url: cfg.ajaxUrl,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
      }).done(function (res) {
        if (!res || !res.success) {
          var msg = (res && res.data && res.data.message) || 'Upload failed.';
          $status.text(msg);
          return;
        }
        $status.text('Upload completed.');
        appendResults((res.data && res.data.results) || []);
      }).fail(function () {
        $status.text('Upload failed.');
      });
    });
  });
})(jQuery);
