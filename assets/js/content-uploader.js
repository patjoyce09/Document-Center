(function ($) {
  'use strict';

  var cfg = window.DCB_UPLOAD_CONFIG || {};
  var selectedFiles = [];

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
      $tbody.append(
        $('<tr>')
          .append($('<td>').text(row.file || ''))
          .append($('<td>').text(row.detectedType || ''))
          .append($('<td>').text(row.sentTo || ''))
          .append($('<td>').text(statusText))
      );
    });

    $('#th-upload-results-wrap').attr('hidden', false);
  }

  $(function () {
    var $input = $('#th-upload-files');
    var $status = $('#th-upload-status');
    var $hint = $('#th-upload-type-hint');

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
