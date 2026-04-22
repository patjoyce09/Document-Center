(function ($) {
  'use strict';

  var cfg = window.DCB_DF_BUILDER_ADMIN || {};
  var fieldTypes = Array.isArray(cfg.fieldTypes) ? cfg.fieldTypes : ['text', 'email', 'date', 'time', 'number', 'select', 'checkbox', 'radio', 'yes_no'];
  var templateBlockTypes = Array.isArray(cfg.templateBlockTypes) ? cfg.templateBlockTypes : ['heading', 'paragraph', 'divider'];
  var operators = cfg.conditionOperators || {};
  var severities = cfg.hardStopSeverities || { error: 'Error', warning: 'Warning', info: 'Info' };

  function tryParseJSON(raw) {
    try {
      var parsed = JSON.parse(raw || '{}');
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
      return null;
    }
  }

  function escapeHtml(value) {
    return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function slugify(v) {
    return String(v || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
  }

  function asArray(v) {
    return Array.isArray(v) ? v : [];
  }

  function splitList(v) {
    return String(v || '').split(',').map(function (s) { return slugify(s.trim()); }).filter(Boolean);
  }

  function clamp01(v) {
    var n = Number(v || 0);
    if (n < 0) return 0;
    if (n > 1) return 1;
    return n;
  }

  function toPct(v) {
    return Math.round(clamp01(v) * 100);
  }

  function scoreBand(score) {
    var n = clamp01(score);
    if (n >= 0.85) return 'excellent';
    if (n >= 0.7) return 'strong';
    if (n >= 0.55) return 'fair';
    return 'low';
  }

  function computeOcrLayoutFidelity(state) {
    var payload = state && state.ocrDraftPayload ? state.ocrDraftPayload : {};
    var rawDraft = payload.raw_draft || payload.draft || {};
    var rows = asArray(state && state.ocrReviewRows);
    var accepted = rows.filter(function (r) { return r && r.accept !== false; });

    if (!accepted.length) {
      return {
        score: 0,
        band: 'low',
        accepted_count: 0,
        candidate_count: rows.length,
        confidence_avg: 0,
        hint_coverage: 0,
        section_coverage: 0,
        node_coverage: 0,
        acceptance_coverage: 0
      };
    }

    var acceptedKeys = accepted.map(function (r, idx) {
      return slugify((r && r.suggested_key) || '') || slugify((r && r.field_label) || '') || ('ocr_field_' + (idx + 1));
    });

    var hintRows = asArray(rawDraft.digital_twin_hints && rawDraft.digital_twin_hints.field_layout);
    var hintByKey = {};
    hintRows.forEach(function (h) {
      var key = slugify(h && h.field_key);
      if (key) hintByKey[key] = true;
    });

    var sectionRefs = {};
    asArray(rawDraft.sections).forEach(function (s) {
      asArray(s && s.field_keys).forEach(function (k) {
        var key = slugify(k);
        if (key) sectionRefs[key] = true;
      });
    });

    var nodeRefs = {};
    asArray(rawDraft.document_nodes).forEach(function (n) {
      if (!n || n.type !== 'field') return;
      var key = slugify(n.field_key);
      if (key) nodeRefs[key] = true;
    });

    var hintHits = 0;
    var sectionHits = 0;
    var nodeHits = 0;
    var confidenceSum = 0;
    accepted.forEach(function (r, idx) {
      var key = acceptedKeys[idx];
      if (hintByKey[key]) hintHits += 1;
      if (sectionRefs[key]) sectionHits += 1;
      if (nodeRefs[key]) nodeHits += 1;
      confidenceSum += clamp01(Number((r && r.confidence_score) || 0));
    });

    var acceptedCount = accepted.length;
    var confidenceAvg = acceptedCount ? clamp01(confidenceSum / acceptedCount) : 0;
    var hintCoverage = acceptedCount ? (hintHits / acceptedCount) : 0;
    var sectionCoverage = acceptedCount ? (sectionHits / acceptedCount) : 0;
    var nodeCoverage = acceptedCount ? (nodeHits / acceptedCount) : 0;
    var acceptanceCoverage = rows.length ? (acceptedCount / rows.length) : 1;

    var score = clamp01(
      (0.35 * confidenceAvg)
      + (0.25 * hintCoverage)
      + (0.25 * nodeCoverage)
      + (0.15 * sectionCoverage)
    );

    return {
      score: score,
      band: scoreBand(score),
      accepted_count: acceptedCount,
      candidate_count: rows.length,
      confidence_avg: confidenceAvg,
      hint_coverage: hintCoverage,
      section_coverage: sectionCoverage,
      node_coverage: nodeCoverage,
      acceptance_coverage: acceptanceCoverage
    };
  }

  function ensureFormShape(form) {
    var f = form && typeof form === 'object' ? form : {};
    f.label = String(f.label || '');
    f.recipients = String(f.recipients || '');
    f.version = Number(f.version || 1);
    f.fields = asArray(f.fields);
    f.sections = asArray(f.sections);
    f.steps = asArray(f.steps);
    f.repeaters = asArray(f.repeaters);
    f.hard_stops = asArray(f.hard_stops);
    f.template_blocks = asArray(f.template_blocks);
    f.document_nodes = asArray(f.document_nodes);
    f.ocr_candidates = asArray(f.ocr_candidates);
    f.ocr_review = f.ocr_review && typeof f.ocr_review === 'object' ? f.ocr_review : {};
    return f;
  }

  function makeDefaultField(n) {
    return { key: 'field_' + n, label: 'Field ' + n, type: 'text', required: false, conditions: [] };
  }

  function makeDefaultCondition() {
    return { field: '', operator: 'eq', value: '' };
  }

  function makeDefaultForm(key) {
    return {
      label: key,
      recipients: '',
      version: 1,
      fields: [makeDefaultField(1)],
      sections: [{ key: 'general', label: 'General', field_keys: ['field_1'] }],
      steps: [{ key: 'step_1', label: 'Step 1', section_keys: ['general'] }],
      repeaters: [],
      hard_stops: [],
      template_blocks: [{ type: 'heading', block_id: 'blk_1', text: 'Draft Form', level: 2 }],
      document_nodes: [{ type: 'block', block_id: 'blk_1' }, { type: 'field', field_key: 'field_1' }],
      ocr_candidates: [],
      ocr_review: {}
    };
  }

  function clone(v) {
    return JSON.parse(JSON.stringify(v));
  }

  function selectOptions(map, selected) {
    return Object.keys(map).map(function (k) {
      return '<option value="' + escapeHtml(k) + '" ' + (String(selected || '') === String(k) ? 'selected' : '') + '>' + escapeHtml(map[k]) + '</option>';
    }).join('');
  }

  function typeOptions(selected) {
    var map = {};
    fieldTypes.forEach(function (t) { map[t] = t; });
    return selectOptions(map, selected || 'text');
  }

  function conditionTargetOptions(form, selected) {
    var map = { '': 'Select field...' };
    asArray(form && form.fields).forEach(function (f) {
      var key = slugify(f && f.key);
      if (!key) return;
      map[key] = key + (f && f.label ? ' — ' + String(f.label) : '');
    });
    return selectOptions(map, selected || '');
  }

  function fieldTemplateButtons() {
    return [
      { k: 'text', l: 'Text Input' },
      { k: 'email', l: 'Email' },
      { k: 'date', l: 'Date' },
      { k: 'number', l: 'Numeric' },
      { k: 'checkbox_attestation', l: 'Attestation Checkbox' },
      { k: 'signature_pack', l: 'Signature Pack' },
      { k: 'ocr_identity_pack', l: 'OCR Identity Pack' }
    ].map(function (row) {
      return '<button type="button" class="button" data-act="field-template-add" data-template="' + row.k + '">' + row.l + '</button>';
    }).join(' ');
  }

  function previewHtml(form) {
    var sections = asArray(form.sections);
    var steps = asArray(form.steps);
    var fields = asArray(form.fields);
    var nodes = asArray(form.document_nodes);
    var blocks = asArray(form.template_blocks);
    var blockById = {};
    blocks.forEach(function (b) { if (b && b.block_id) blockById[String(b.block_id)] = b; });

    var chunks = ['<div class="dcb-preview-surface">'];
    chunks.push('<h4>Steps & Sections</h4>');
    if (!steps.length) {
      chunks.push('<p class="description">No steps defined.</p>');
    } else {
      steps.forEach(function (step, idx) {
        chunks.push('<div class="dcb-preview-step"><strong>' + escapeHtml(step.label || ('Step ' + (idx + 1))) + '</strong>');
        var sectionKeys = asArray(step.section_keys).map(String);
        var sectionRows = sections.filter(function (s) { return sectionKeys.indexOf(String(s.key || '')) >= 0; });
        if (!sectionRows.length) sectionRows = sections;
        sectionRows.forEach(function (s) {
          chunks.push('<div class="dcb-preview-section"><em>' + escapeHtml(s.label || s.key || 'Section') + '</em><ol>');
          asArray(s.field_keys).forEach(function (fk) {
            var f = fields.find(function (row) { return String(row.key || '') === String(fk || ''); });
            if (f) chunks.push('<li>' + escapeHtml(f.label || f.key) + ' <small>(' + escapeHtml(f.type || 'text') + ')</small></li>');
          });
          chunks.push('</ol></div>');
        });
        chunks.push('</div>');
      });
    }

    chunks.push('<h4>Document Output Order</h4><ol>');
    if (!nodes.length) {
      chunks.push('<li><em>No document nodes configured.</em></li>');
    } else {
      nodes.forEach(function (n) {
        if (n.type === 'field') {
          chunks.push('<li>Field: ' + escapeHtml(n.field_key || '') + '</li>');
        } else if (n.type === 'block') {
          var b = blockById[String(n.block_id || '')] || {};
          chunks.push('<li>Block: ' + escapeHtml(n.block_id || '') + ' <small>(' + escapeHtml(b.type || 'block') + ')</small></li>');
        }
      });
    }
    chunks.push('</ol>');

    chunks.push('<h4>Hard Stop Examples</h4>');
    if (!asArray(form.hard_stops).length) {
      chunks.push('<p class="description">No hard-stop rules.</p>');
    } else {
      chunks.push('<ul>');
      asArray(form.hard_stops).forEach(function (h) {
        chunks.push('<li><strong>' + escapeHtml(h.label || 'Rule') + ':</strong> ' + escapeHtml(h.message || '') + '</li>');
      });
      chunks.push('</ul>');
    }
    chunks.push('</div>');
    return chunks.join('');
  }

  function previewHtmlFromPayload(payload, fallbackForm) {
    if (!payload || typeof payload !== 'object') {
      return previewHtml(fallbackForm || {});
    }

    var steps = asArray(payload.steps);
    var documentOutput = asArray(payload.document_output);
    var templateBlocks = asArray(payload.template_blocks);
    var fieldOrder = asArray(payload.field_order);

    var chunks = ['<div class="dcb-preview-surface">'];
    chunks.push('<h4>Steps & Sections</h4>');
    if (!steps.length) {
      chunks.push('<p class="description">No steps defined.</p>');
    } else {
      steps.forEach(function (step, idx) {
        chunks.push('<div class="dcb-preview-step"><strong>' + escapeHtml(step.label || ('Step ' + (idx + 1))) + '</strong>');
        asArray(step.sections).forEach(function (section) {
          chunks.push('<div class="dcb-preview-section"><em>' + escapeHtml(section.label || section.key || 'Section') + '</em><ol>');
          asArray(section.fields).forEach(function (field) {
            chunks.push('<li>' + escapeHtml(field.label || field.key || '') + ' <small>(' + escapeHtml(field.type || 'text') + ')</small></li>');
          });
          chunks.push('</ol></div>');
        });
        chunks.push('</div>');
      });
    }

    chunks.push('<h4>Document Output Order</h4><ol>');
    if (!documentOutput.length) {
      chunks.push('<li><em>No document nodes configured.</em></li>');
    } else {
      documentOutput.forEach(function (node) {
        if (node.type === 'field') {
          chunks.push('<li>Field: ' + escapeHtml((node.field && node.field.key) || node.field_key || '') + '</li>');
        } else if (node.type === 'block') {
          chunks.push('<li>Block: ' + escapeHtml((node.block && node.block.block_id) || node.block_id || '') + ' <small>(' + escapeHtml((node.block && node.block.type) || 'block') + ')</small></li>');
        }
      });
    }
    chunks.push('</ol>');

    chunks.push('<h4>Template Blocks</h4>');
    if (!templateBlocks.length) {
      chunks.push('<p class="description">No template blocks.</p>');
    } else {
      chunks.push('<ul>');
      templateBlocks.forEach(function (block) {
        chunks.push('<li>' + escapeHtml(block.block_id || '') + ' <small>(' + escapeHtml(block.type || 'paragraph') + ')</small> ' + escapeHtml(block.text || '') + '</li>');
      });
      chunks.push('</ul>');
    }

    chunks.push('<h4>Field Order</h4>');
    if (!fieldOrder.length) {
      chunks.push('<p class="description">No fields.</p>');
    } else {
      chunks.push('<ol>');
      fieldOrder.forEach(function (field) {
        chunks.push('<li>' + escapeHtml(field.label || field.key || '') + ' <small>(' + escapeHtml(field.type || 'text') + ')</small></li>');
      });
      chunks.push('</ol>');
    }

    chunks.push('</div>');
    return chunks.join('');
  }

  function frontPreviewBlockHtml(block) {
    block = block || {};
    var type = String(block.type || 'paragraph');
    var text = String(block.text || '');
    if (type === 'heading' || type === 'section_header') {
      var level = Math.max(1, Math.min(6, Number(block.level || (type === 'heading' ? 2 : 3))));
      return '<h' + level + ' class="th-df-block th-df-block-' + escapeHtml(type) + '">' + escapeHtml(text) + '</h' + level + '>';
    }
    if (type === 'divider') {
      return '<hr class="th-df-block th-df-block-divider" />';
    }
    return '<p class="th-df-block th-df-block-paragraph">' + escapeHtml(text) + '</p>';
  }

  function frontPreviewFieldHtml(field) {
    field = field || {};
    var key = String(field.key || '');
    var type = String(field.type || 'text');
    var label = String(field.label || key || 'Field');
    var required = !!field.required;
    var req = required ? ' *' : '';

    var input = '';
    if (type === 'textarea') {
      input = '<textarea class="th-df-input" rows="3" disabled placeholder="' + escapeHtml(label) + '"></textarea>';
    } else if (type === 'checkbox') {
      input = '<input type="checkbox" class="th-df-checkbox" disabled />';
    } else if (type === 'date') {
      input = '<input type="date" class="th-df-input" disabled />';
    } else if (type === 'select' || type === 'yes_no' || type === 'radio') {
      input = '<select class="th-df-input" disabled><option>Select...</option></select>';
    } else {
      input = '<input type="text" class="th-df-input" disabled placeholder="' + escapeHtml(label) + '" />';
    }

    return '<div class="th-df-field"><label class="th-df-label">' + escapeHtml(label + req) + '</label>' + input + '</div>';
  }

  function frontEndPreviewHtml(form) {
    var fields = asArray(form && form.fields);
    var blocks = asArray(form && form.template_blocks);
    var nodes = asArray(form && form.document_nodes);
    var sections = asArray(form && form.sections);
    var steps = asArray(form && form.steps);

    var blockById = {};
    blocks.forEach(function (b) { if (b && b.block_id) blockById[String(b.block_id)] = b; });
    var fieldByKey = {};
    fields.forEach(function (f) { if (f && f.key) fieldByKey[String(f.key)] = f; });

    var step = steps[0] || null;
    var allowed = {};
    if (step && asArray(step.section_keys).length) {
      var sectionKeys = asArray(step.section_keys).map(String);
      sections.forEach(function (s) {
        if (sectionKeys.indexOf(String((s && s.key) || '')) < 0) return;
        asArray(s && s.field_keys).forEach(function (k) {
          var key = String(k || '');
          if (key) allowed[key] = true;
        });
      });
    }

    var out = ['<div class="th-df-card"><div class="th-df-paper">'];
    if (!nodes.length) {
      fields.forEach(function (f) {
        if (Object.keys(allowed).length && !allowed[String((f && f.key) || '')]) return;
        out.push(frontPreviewFieldHtml(f));
      });
    } else {
      nodes.forEach(function (n) {
        if (!n || typeof n !== 'object') return;
        if (n.type === 'block') {
          var b = blockById[String(n.block_id || '')] || null;
          if (b) out.push(frontPreviewBlockHtml(b));
          return;
        }
        if (n.type === 'field') {
          var f = fieldByKey[String(n.field_key || '')] || null;
          if (!f) return;
          if (Object.keys(allowed).length && !allowed[String(f.key || '')]) return;
          out.push(frontPreviewFieldHtml(f));
        }
      });
    }
    out.push('</div></div>');
    return out.join('');
  }

  function getBackgroundImageForLayout(form) {
    var bg = form && form.digital_twin_background && typeof form.digital_twin_background === 'object' ? form.digital_twin_background : {};
    var pages = asArray(bg.pages);
    if (pages.length) {
      var first = pages[0] && typeof pages[0] === 'object' ? pages[0] : {};
      return String(first.image_url || bg.image_url || '');
    }
    return String(bg.image_url || '');
  }

  function ensureFieldGeometry(field, idx) {
    if (!field || typeof field !== 'object') return null;
    field.ocr_meta = field.ocr_meta && typeof field.ocr_meta === 'object' ? field.ocr_meta : {};
    var g = field.ocr_meta.geometry && typeof field.ocr_meta.geometry === 'object' ? field.ocr_meta.geometry : {};

    var x = Number(g.x);
    var y = Number(g.y);
    var w = Number(g.w);
    var h = Number(g.h);

    if (!(x >= 0 && x <= 1) || !(y >= 0 && y <= 1) || !(w > 0 && w <= 1) || !(h > 0 && h <= 1)) {
      var col = idx % 2;
      var row = Math.floor(idx / 2);
      x = col === 0 ? 0.06 : 0.54;
      y = Math.min(0.92, 0.08 + (row * 0.075));
      w = 0.4;
      h = 0.06;
    }

    x = clamp01(x);
    y = clamp01(y);
    w = Math.max(0.08, Math.min(1, w));
    h = Math.max(0.03, Math.min(1, h));

    field.ocr_meta.geometry = {
      x: x,
      y: y,
      w: w,
      h: h,
      unit: 'page_ratio'
    };

    return field.ocr_meta.geometry;
  }

  function layoutEditorHtml(form) {
    var bg = getBackgroundImageForLayout(form);
    if (!bg) {
      return '<p class="description">No scanned background found for this form. Re-run OCR extract from seed PDF/image to enable drag positioning.</p>';
    }

    var out = ['<div class="dcb-layout-stage">', '<img src="' + escapeHtml(bg) + '" alt="Scanned form background" class="dcb-layout-bg"/>', '<div class="dcb-layout-overlay">'];
    asArray(form.fields).forEach(function (f, i) {
      if (!f || !f.key) return;
      var g = ensureFieldGeometry(f, i);
      out.push('<div class="dcb-geom-box" data-act="geom-drag" data-i="' + i + '" style="left:' + (g.x * 100) + '%;top:' + (g.y * 100) + '%;width:' + (g.w * 100) + '%;height:' + (g.h * 100) + '%;"><span class="dcb-geom-label">' + escapeHtml(f.label || f.key) + '</span></div>');
    });
    out.push('</div></div>');
    out.push('<p class="description">Drag boxes to reposition fields over the scan. Hard stops/validation still apply to field values.</p>');
    return out.join('');
  }

  function validateForm(form) {
    var errors = [];
    var warnings = [];
    var fieldKeys = {};

    asArray(form.fields).forEach(function (f, i) {
      var key = slugify(f && f.key);
      if (!key) errors.push('Field row ' + (i + 1) + ' is missing key.');
      if (!String((f && f.label) || '').trim()) errors.push('Field ' + (key || ('#' + (i + 1))) + ' is missing label.');
      if (key) fieldKeys[key] = (fieldKeys[key] || 0) + 1;
      asArray(f && f.conditions).forEach(function (c, ci) {
        if (!slugify(c && c.field) || !operators[String(c && c.operator || '')]) errors.push('Field ' + (key || ('#' + (i + 1))) + ' has invalid condition #' + (ci + 1) + '.');
      });
    });
    Object.keys(fieldKeys).forEach(function (k) { if (fieldKeys[k] > 1) errors.push('Duplicate field key "' + k + '".'); });

    var sectionKeys = {};
    asArray(form.sections).forEach(function (s, i) {
      var key = slugify(s && s.key);
      if (!key) errors.push('Section row ' + (i + 1) + ' is missing key.');
      if (key) sectionKeys[key] = (sectionKeys[key] || 0) + 1;
      asArray(s && s.field_keys).forEach(function (fk) {
        fk = slugify(fk);
        if (!fieldKeys[fk]) errors.push('Section ' + (key || '#'+ (i + 1)) + ' references missing field ' + fk + '.');
      });
    });

    asArray(form.steps).forEach(function (s, i) {
      var key = slugify(s && s.key);
      if (!key) errors.push('Step row ' + (i + 1) + ' is missing key.');
      asArray(s && s.section_keys).forEach(function (sk) {
        sk = slugify(sk);
        if (!sectionKeys[sk]) errors.push('Step ' + (key || '#'+ (i + 1)) + ' references missing section ' + sk + '.');
      });
    });

    asArray(form.repeaters).forEach(function (r, i) {
      var key = slugify(r && r.key);
      if (!key) errors.push('Repeater row ' + (i + 1) + ' is missing key.');
      asArray(r && r.field_keys).forEach(function (fk) {
        fk = slugify(fk);
        if (!fieldKeys[fk]) errors.push('Repeater ' + (key || '#'+ (i + 1)) + ' references missing field ' + fk + '.');
      });
    });

    var blockIds = {};
    asArray(form.template_blocks).forEach(function (b, i) {
      var bid = slugify(b && b.block_id);
      if (!bid) warnings.push('Template block row ' + (i + 1) + ' is missing block_id.');
      if (bid) blockIds[bid] = true;
    });

    asArray(form.document_nodes).forEach(function (n, i) {
      if (n.type === 'field' && !fieldKeys[slugify(n.field_key)]) errors.push('Document node #' + (i + 1) + ' references missing field.');
      if (n.type === 'block' && !blockIds[slugify(n.block_id)]) errors.push('Document node #' + (i + 1) + ' references missing block.');
      if (!n.type || (n.type !== 'field' && n.type !== 'block')) errors.push('Document node #' + (i + 1) + ' has invalid type.');
    });

    asArray(form.hard_stops).forEach(function (h, i) {
      if (!String(h && h.message || '').trim()) errors.push('Hard stop row ' + (i + 1) + ' is missing message.');
      if (!asArray(h && h.when).length) errors.push('Hard stop row ' + (i + 1) + ' is missing conditions.');
      asArray(h && h.when).forEach(function (c, ci) {
        if (!slugify(c && c.field) || !operators[String(c && c.operator || '')]) errors.push('Hard stop row ' + (i + 1) + ' has invalid condition #' + (ci + 1) + '.');
      });
    });

    return { errors: errors, warnings: warnings };
  }

  function persist(state, $hidden, $advanced) {
    var canonical = JSON.stringify(state.forms || {}, null, 2);
    $hidden.val(canonical);
    $advanced.val(canonical);
  }

  function renderConditions(form, conditions, path) {
    var out = ['<div class="dcb-cond-list">'];
    asArray(conditions).forEach(function (c, ci) {
      var op = String(c.operator || 'eq');
      var isListOp = op === 'in' || op === 'not_in';
      var valueText = isListOp ? asArray(c.values).join(', ') : (c.value || '');
      out.push('<div class="dcb-cond-row">'
        + '<select data-act="set-cond-field" data-path="' + path + '" data-ci="' + ci + '">' + conditionTargetOptions(form, c.field || '') + '</select>'
        + '<select data-act="set-cond-op" data-path="' + path + '" data-ci="' + ci + '">' + selectOptions(operators, c.operator || 'eq') + '</select>'
        + '<input type="text" placeholder="' + (isListOp ? 'comma list: a,b,c' : 'single value') + '" data-act="set-cond-value" data-path="' + path + '" data-ci="' + ci + '" value="' + escapeHtml(valueText) + '"/>'
        + '<button type="button" class="button-link-delete" data-act="del-cond" data-path="' + path + '" data-ci="' + ci + '">Delete</button>'
        + '</div>');
    });
    out.push('<button type="button" class="button" data-act="add-cond" data-path="' + path + '">Add Condition</button></div>');
    return out.join('');
  }

  function renderBuilder($root, state) {
    var keys = Object.keys(state.forms || {});
    var selected = state.selectedKey && state.forms[state.selectedKey] ? state.selectedKey : (keys[0] || '');
    state.selectedKey = selected;

    var html = ['<div class="dcb-builder-shell"><aside class="dcb-builder-sidebar">'];
    html.push('<div class="dcb-builder-sidebar-actions"><button type="button" class="button button-primary" data-act="add-form">Add Form</button><button type="button" class="button" data-act="import-json">Import JSON</button><button type="button" class="button" data-act="export-json">Export JSON</button></div>');
    if (!keys.length) {
      html.push('<p>No forms yet.</p>');
    } else {
      html.push('<ul class="dcb-form-list">');
      keys.forEach(function (k) {
        var f = ensureFormShape(state.forms[k]);
        html.push('<li class="' + (k === selected ? 'is-active' : '') + '"><button type="button" data-act="select-form" data-key="' + escapeHtml(k) + '">' + escapeHtml(f.label || k) + '</button><span>' + asArray(f.fields).length + ' fields</span></li>');
      });
      html.push('</ul>');
    }
    html.push('</aside><main class="dcb-builder-main">');

    if (!selected) {
      html.push('<p>Create a form to start.</p></main></div>');
      $root.html(html.join(''));
      return;
    }

    var form = ensureFormShape(state.forms[selected]);
    var localIssues = validateForm(form);
    var remoteValidation = state.serverValidation[selected] || null;
    var issues = {
      errors: [],
      warnings: []
    };
    if (remoteValidation && remoteValidation.ok) {
      issues.errors = asArray(remoteValidation.errors);
      issues.warnings = asArray(remoteValidation.warnings);
    } else {
      issues.errors = localIssues.errors;
      issues.warnings = localIssues.warnings;
    }
    html.push('<div class="dcb-form-header"><label>Form Key<input type="text" data-act="set-key" value="' + escapeHtml(selected) + '"/></label><label>Label<input type="text" data-act="set-label" value="' + escapeHtml(form.label || '') + '"/></label><label>Recipients<input type="text" data-act="set-recipients" value="' + escapeHtml(form.recipients || '') + '"/></label><button type="button" class="button" data-act="duplicate-form">Duplicate</button><button type="button" class="button button-link-delete" data-act="delete-form">Delete</button></div>');

    html.push('<section class="dcb-panel"><h3>Validation</h3>');
    if (remoteValidation && remoteValidation.loading) {
      html.push('<p class="description">Validating with server rules…</p>');
    }
    if (remoteValidation && !remoteValidation.ok && remoteValidation.message) {
      html.push('<div class="notice notice-warning inline"><p>' + escapeHtml(remoteValidation.message) + '</p></div>');
      if (localIssues.errors.length || localIssues.warnings.length) {
        html.push('<p class="description">Showing local validation fallback.</p>');
      }
    }
    if (!issues.errors.length && !issues.warnings.length) {
      html.push('<p class="description">No validation issues detected.</p>');
    } else {
      if (issues.errors.length) html.push('<div class="notice notice-error inline"><p><strong>Errors</strong></p><ul><li>' + issues.errors.map(escapeHtml).join('</li><li>') + '</li></ul></div>');
      if (issues.warnings.length) html.push('<div class="notice notice-warning inline"><p><strong>Warnings</strong></p><ul><li>' + issues.warnings.map(escapeHtml).join('</li><li>') + '</li></ul></div>');
    }
    html.push('</section>');

    html.push('<section class="dcb-panel"><h3>Fields</h3><p class="dcb-template-buttons">' + fieldTemplateButtons() + ' <button type="button" class="button" data-act="field-add">Add Blank Field</button></p>');
    html.push('<table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th>Actions</th></tr></thead><tbody>');
    asArray(form.fields).forEach(function (field, i) {
      html.push('<tr><td><input type="text" data-act="field-key" data-i="' + i + '" value="' + escapeHtml(field.key || '') + '"/></td><td><input type="text" data-act="field-label" data-i="' + i + '" value="' + escapeHtml(field.label || '') + '"/></td><td><select data-act="field-type" data-i="' + i + '">' + typeOptions(field.type || 'text') + '</select></td><td><input type="checkbox" data-act="field-required" data-i="' + i + '" ' + (field.required ? 'checked' : '') + '/></td><td><button type="button" class="button" data-act="field-insert-above" data-i="' + i + '">Insert Above</button> <button type="button" class="button" data-act="field-insert-below" data-i="' + i + '">Insert Below</button> <button type="button" class="button-link-delete" data-act="field-delete" data-i="' + i + '">Delete</button></td></tr>');
      html.push('<tr><td colspan="5"><strong>Conditions</strong>' + renderConditions(form, field.conditions, 'field:' + i) + '</td></tr>');
    });
    if (!asArray(form.fields).length) html.push('<tr><td colspan="5">No fields yet.</td></tr>');
    html.push('</tbody></table></section>');

    html.push('<section class="dcb-panel"><h3>Sections</h3><table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Field Keys (comma)</th><th></th></tr></thead><tbody>');
    asArray(form.sections).forEach(function (s, i) {
      html.push('<tr><td><input type="text" data-act="section-key" data-i="' + i + '" value="' + escapeHtml(s.key || '') + '"/></td><td><input type="text" data-act="section-label" data-i="' + i + '" value="' + escapeHtml(s.label || '') + '"/></td><td><input type="text" data-act="section-fields" data-i="' + i + '" value="' + escapeHtml(asArray(s.field_keys).join(', ')) + '"/></td><td><button type="button" class="button-link-delete" data-act="section-delete" data-i="' + i + '">Delete</button></td></tr>');
    });
    html.push('</tbody></table><p><button type="button" class="button" data-act="section-add">Add Section</button></p></section>');

    html.push('<section class="dcb-panel"><h3>Steps / Pages</h3><table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Section Keys (comma)</th><th></th></tr></thead><tbody>');
    asArray(form.steps).forEach(function (s, i) {
      html.push('<tr><td><input type="text" data-act="step-key" data-i="' + i + '" value="' + escapeHtml(s.key || '') + '"/></td><td><input type="text" data-act="step-label" data-i="' + i + '" value="' + escapeHtml(s.label || '') + '"/></td><td><input type="text" data-act="step-sections" data-i="' + i + '" value="' + escapeHtml(asArray(s.section_keys).join(', ')) + '"/></td><td><button type="button" class="button-link-delete" data-act="step-delete" data-i="' + i + '">Delete</button></td></tr>');
    });
    html.push('</tbody></table><p><button type="button" class="button" data-act="step-add">Add Step</button></p></section>');

    html.push('<section class="dcb-panel"><h3>Repeaters</h3><table class="widefat striped"><thead><tr><th>Key</th><th>Label</th><th>Field Keys (comma)</th><th>Min</th><th>Max</th><th></th></tr></thead><tbody>');
    asArray(form.repeaters).forEach(function (r, i) {
      html.push('<tr><td><input type="text" data-act="rep-key" data-i="' + i + '" value="' + escapeHtml(r.key || '') + '"/></td><td><input type="text" data-act="rep-label" data-i="' + i + '" value="' + escapeHtml(r.label || '') + '"/></td><td><input type="text" data-act="rep-fields" data-i="' + i + '" value="' + escapeHtml(asArray(r.field_keys).join(', ')) + '"/></td><td><input type="number" min="0" data-act="rep-min" data-i="' + i + '" value="' + escapeHtml(r.min || 0) + '"/></td><td><input type="number" min="0" data-act="rep-max" data-i="' + i + '" value="' + escapeHtml(r.max || 1) + '"/></td><td><button type="button" class="button-link-delete" data-act="rep-delete" data-i="' + i + '">Delete</button></td></tr>');
    });
    html.push('</tbody></table><p><button type="button" class="button" data-act="rep-add">Add Repeater</button></p></section>');

    html.push('<section class="dcb-panel"><h3>Hard Stops</h3><table class="widefat striped"><thead><tr><th>Rule Label</th><th>Severity</th><th>Type</th><th>Message</th><th></th></tr></thead><tbody>');
    asArray(form.hard_stops).forEach(function (h, i) {
      html.push('<tr><td><input type="text" data-act="hs-label" data-i="' + i + '" value="' + escapeHtml(h.label || '') + '"/></td><td><select data-act="hs-severity" data-i="' + i + '">' + selectOptions(severities, h.severity || 'error') + '</select></td><td><input type="text" data-act="hs-type" data-i="' + i + '" value="' + escapeHtml(h.type || '') + '"/></td><td><input type="text" data-act="hs-message" data-i="' + i + '" value="' + escapeHtml(h.message || '') + '"/></td><td><button type="button" class="button-link-delete" data-act="hs-delete" data-i="' + i + '">Delete</button></td></tr>');
      html.push('<tr><td colspan="5"><strong>Conditions</strong>' + renderConditions(form, h.when, 'hard:' + i) + '</td></tr>');
    });
    html.push('</tbody></table><p><button type="button" class="button" data-act="hs-add">Add Hard Stop</button></p></section>');

    html.push('<section class="dcb-panel"><h3>Template Blocks</h3><table class="widefat striped"><thead><tr><th>block_id</th><th>type</th><th>text</th><th></th></tr></thead><tbody>');
    asArray(form.template_blocks).forEach(function (b, i) {
      var blockTypeMap = {};
      templateBlockTypes.forEach(function (t) { blockTypeMap[t] = t; });
      html.push('<tr><td><input type="text" data-act="tb-id" data-i="' + i + '" value="' + escapeHtml(b.block_id || '') + '"/></td><td><select data-act="tb-type" data-i="' + i + '">' + selectOptions(blockTypeMap, b.type || 'paragraph') + '</select></td><td><input type="text" data-act="tb-text" data-i="' + i + '" value="' + escapeHtml(b.text || '') + '"/></td><td><button type="button" class="button-link-delete" data-act="tb-delete" data-i="' + i + '">Delete</button></td></tr>');
    });
    html.push('</tbody></table><p><button type="button" class="button" data-act="tb-add">Add Template Block</button></p></section>');

    html.push('<section class="dcb-panel"><h3>Document Nodes</h3><table class="widefat striped"><thead><tr><th>Type</th><th>Field Key</th><th>Block ID</th><th></th></tr></thead><tbody>');
    asArray(form.document_nodes).forEach(function (n, i) {
      html.push('<tr><td><select data-act="dn-type" data-i="' + i + '"><option value="field" ' + (n.type === 'field' ? 'selected' : '') + '>field</option><option value="block" ' + (n.type === 'block' ? 'selected' : '') + '>block</option></select></td><td><input type="text" data-act="dn-field" data-i="' + i + '" value="' + escapeHtml(n.field_key || '') + '"/></td><td><input type="text" data-act="dn-block" data-i="' + i + '" value="' + escapeHtml(n.block_id || '') + '"/></td><td><button type="button" class="button-link-delete" data-act="dn-delete" data-i="' + i + '">Delete</button></td></tr>');
    });
    html.push('</tbody></table><p><button type="button" class="button" data-act="dn-add-field">Add Field Node</button> <button type="button" class="button" data-act="dn-add-block">Add Block Node</button></p></section>');

    var previewPayload = state.serverPreview[selected] || null;
    html.push('<section class="dcb-panel"><h3>Preview</h3>' + previewHtmlFromPayload(previewPayload, form) + '</section>');
    html.push('<section class="dcb-panel"><h3>Front-end Preview (Step 1)</h3><p class="description">Approximate portal rendering for the first step/page.</p>' + frontEndPreviewHtml(form) + '</section>');
    html.push('<section class="dcb-panel"><h3>Layout Positioning (Drag)</h3>' + layoutEditorHtml(form) + '</section>');
    html.push('</main></div>');
    $root.html(html.join(''));
  }

  function scheduleServerValidation(state) {
    var key = state.selectedKey;
    if (!key || !state.forms[key] || !cfg.ajaxUrl || !cfg.validationAction || !cfg.validationNonce) {
      return;
    }

    var form = ensureFormShape(state.forms[key]);
    var fingerprint = JSON.stringify(form || {});
    if (state.serverValidationFingerprint[key] && state.serverValidationFingerprint[key] === fingerprint) {
      return;
    }

    if (state.validationTimer) {
      clearTimeout(state.validationTimer);
    }

    state.serverValidation[key] = { loading: true, ok: false, errors: [], warnings: [] };

    state.validationTimer = setTimeout(function () {
      $.post(cfg.ajaxUrl, {
        action: cfg.validationAction,
        nonce: cfg.validationNonce,
        form: JSON.stringify(form)
      }).done(function (res) {
        if (!res || !res.success || !res.data) {
          state.serverValidation[key] = { loading: false, ok: false, errors: [], warnings: [], message: 'Server validation returned an invalid response.' };
          return;
        }
        state.serverValidation[key] = {
          loading: false,
          ok: true,
          errors: asArray(res.data.errors),
          warnings: asArray(res.data.warnings)
        };
        state.serverPreview[key] = res.data.preview || null;
        state.serverValidationFingerprint[key] = fingerprint;
      }).fail(function () {
        state.serverValidation[key] = { loading: false, ok: false, errors: [], warnings: [], message: 'Server validation unavailable. Using local checks.' };
      }).always(function () {
        renderBuilder($('#th-df-builder-root'), state);
      });
    }, 250);
  }

  function resolveContext(state) {
    var key = state.selectedKey;
    if (!key || !state.forms[key]) return null;
    return ensureFormShape(state.forms[key]);
  }

  function applyTemplate(form, template) {
    var rows = [];
    if (template === 'signature_pack') {
      rows = [
        { key: 'attest_truth', label: 'I attest the information is accurate', type: 'checkbox', required: true },
        { key: 'signature_name', label: 'Signer Full Name', type: 'text', required: true },
        { key: 'signature_date', label: 'Signature Date', type: 'date', required: true }
      ];
    } else if (template === 'checkbox_attestation') {
      rows = [{ key: 'attestation', label: 'I agree and attest this submission is complete', type: 'checkbox', required: true }];
    } else if (template === 'ocr_identity_pack') {
      rows = [
        { key: 'full_name', label: 'Full Name', type: 'text', required: true },
        { key: 'dob', label: 'Date of Birth', type: 'date', required: true },
        { key: 'email', label: 'Email Address', type: 'email', required: false }
      ];
    } else {
      rows = [{ key: 'field_' + (form.fields.length + 1), label: 'New Field', type: template, required: false }];
    }

    rows.forEach(function (r) {
      var key = slugify(r.key || ('field_' + (form.fields.length + 1)));
      var n = 2;
      while (form.fields.find(function (f) { return String(f.key || '') === key; })) {
        key = slugify(r.key || 'field') + '_' + n;
        n += 1;
      }
      form.fields.push({ key: key, label: r.label, type: r.type, required: !!r.required, conditions: [] });
    });
  }

  function upsertFormFromOcrDraft(state, payload) {
    var key = slugify(payload.form_key || 'scanned_form');
    if (!key) key = 'scanned_form_' + Date.now();
    while (state.forms[key]) key = key + '_copy';

    var draft = ensureFormShape(clone(payload.raw_draft || payload.draft || {}));
    draft.label = payload.form_label || draft.label || key;
    if (!asArray(draft.sections).length) draft.sections = [{ key: 'general', label: 'General', field_keys: asArray(draft.fields).map(function (f) { return slugify(f.key); }).filter(Boolean) }];
    if (!asArray(draft.steps).length) draft.steps = [{ key: 'step_1', label: 'Step 1', section_keys: ['general'] }];
    if (!asArray(draft.document_nodes).length) {
      draft.document_nodes = [];
      asArray(draft.template_blocks).forEach(function (b) { if (b.block_id) draft.document_nodes.push({ type: 'block', block_id: b.block_id }); });
      asArray(draft.fields).forEach(function (f) { if (f.key) draft.document_nodes.push({ type: 'field', field_key: f.key }); });
    }
    state.forms[key] = draft;
    state.selectedKey = key;
  }

  function renderOcrWorkbench($root, state) {
    if (!$root.length) return;
    var payload = state.ocrDraftPayload;
    if (!payload) {
      $root.html('<p class="description">OCR review workbench will appear after extraction.</p>');
      return;
    }
    var rows = asArray(state.ocrReviewRows);
    var fidelity = computeOcrLayoutFidelity(state);
    var html = ['<section class="dcb-panel"><h3>OCR Draft Review Workbench</h3><p>Review candidate fields before applying draft to builder.</p>'];
    html.push('<div class="notice inline ' + (fidelity.score >= 0.7 ? 'notice-success' : (fidelity.score >= 0.55 ? 'notice-warning' : 'notice-error')) + '"><p><strong>Layout Fidelity Preview:</strong> ' + toPct(fidelity.score) + '% (' + escapeHtml(fidelity.band) + ')'
      + ' · accepted ' + Number(fidelity.accepted_count || 0) + '/' + Number(fidelity.candidate_count || 0)
      + ' · confidence ' + toPct(fidelity.confidence_avg) + '%'
      + ' · layout hints ' + toPct(fidelity.hint_coverage) + '%'
      + ' · document nodes ' + toPct(fidelity.node_coverage) + '%'
      + ' · section mapping ' + toPct(fidelity.section_coverage) + '%</p></div>');
    html.push('<table class="widefat striped"><thead><tr><th>Use</th><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th>Confidence</th></tr></thead><tbody>');
    rows.forEach(function (r, i) {
      html.push('<tr><td><input type="checkbox" data-act="ocr-accept" data-i="' + i + '" ' + (r.accept !== false ? 'checked' : '') + '/></td><td><input type="text" data-act="ocr-key" data-i="' + i + '" value="' + escapeHtml(r.suggested_key || '') + '"/></td><td><input type="text" data-act="ocr-label" data-i="' + i + '" value="' + escapeHtml(r.field_label || '') + '"/></td><td><select data-act="ocr-type" data-i="' + i + '">' + typeOptions(r.suggested_type || 'text') + '</select></td><td><input type="checkbox" data-act="ocr-required" data-i="' + i + '" ' + (r.required_guess ? 'checked' : '') + '/></td><td>' + escapeHtml((r.confidence_bucket || 'low') + ' (' + Number(r.confidence_score || 0).toFixed(2) + ')') + '</td></tr>');
    });
    html.push('</tbody></table><p><button type="button" class="button button-primary" data-act="ocr-apply-draft">Apply Accepted Candidates to Builder</button></p></section>');
    $root.html(html.join(''));
  }

  $(function () {
    var $hidden = $('#th-df-builder-json');
    var $advanced = $('#th-df-builder-json-advanced');
    var $builderRoot = $('#th-df-builder-root');
    var $ocrRoot = $('#dcb-ocr-review-root');
    var parsedForms = tryParseJSON($hidden.val());
    var state = {
      forms: parsedForms || {},
      selectedKey: '',
      ocrDraftPayload: null,
      ocrReviewRows: [],
      dragState: null,
      serverValidation: {},
      serverPreview: {},
      serverValidationFingerprint: {},
      validationTimer: null
    };

    if (!state.forms) {
      $builderRoot.html('<p style="color:#b32d2e">Invalid builder JSON. Fix raw JSON and apply.</p>');
      return;
    }

    function rerender() {
      renderBuilder($builderRoot, state);
      renderOcrWorkbench($ocrRoot, state);
      persist(state, $hidden, $advanced);
      scheduleServerValidation(state);
    }

    rerender();

    $builderRoot.on('click', '[data-act="select-form"]', function () {
      state.selectedKey = String($(this).data('key') || '');
      rerender();
    });

    $builderRoot.on('click', '[data-act="add-form"]', function () {
      var key = slugify(prompt('New form key (snake_case):', 'new_form') || '');
      if (!key) return;
      if (state.forms[key]) return alert('That key already exists.');
      state.forms[key] = makeDefaultForm(key);
      state.selectedKey = key;
      rerender();
    });

    $builderRoot.on('mousedown', '[data-act="geom-drag"]', function (e) {
      var form = resolveContext(state); if (!form) return;
      var i = Number($(this).data('i'));
      if (i < 0 || i >= asArray(form.fields).length) return;
      var field = form.fields[i];
      var g = ensureFieldGeometry(field, i);
      var $overlay = $(this).closest('.dcb-layout-overlay');
      if (!$overlay.length) return;
      var off = $overlay.offset();
      if (!off) return;
      var ow = $overlay.outerWidth() || 1;
      var oh = $overlay.outerHeight() || 1;
      var px = (g.x * ow);
      var py = (g.y * oh);
      state.dragState = {
        i: i,
        startMouseX: e.pageX,
        startMouseY: e.pageY,
        startX: g.x,
        startY: g.y,
        width: g.w,
        height: g.h,
        overlayLeft: off.left,
        overlayTop: off.top,
        overlayWidth: ow,
        overlayHeight: oh,
        $node: $(this)
      };
      e.preventDefault();
    });

    $(document).on('mousemove.dcbGeom', function (e) {
      if (!state.dragState) return;
      var d = state.dragState;
      var form = resolveContext(state); if (!form) return;
      var field = form.fields[d.i];
      if (!field) return;

      var dx = (e.pageX - d.startMouseX) / Math.max(1, d.overlayWidth);
      var dy = (e.pageY - d.startMouseY) / Math.max(1, d.overlayHeight);
      var x = Math.max(0, Math.min(1 - d.width, d.startX + dx));
      var y = Math.max(0, Math.min(1 - d.height, d.startY + dy));
      field.ocr_meta = field.ocr_meta && typeof field.ocr_meta === 'object' ? field.ocr_meta : {};
      field.ocr_meta.geometry = {
        x: x,
        y: y,
        w: d.width,
        h: d.height,
        unit: 'page_ratio'
      };

      d.$node.css({ left: (x * 100) + '%', top: (y * 100) + '%' });
    });

    $(document).on('mouseup.dcbGeom', function () {
      if (!state.dragState) return;
      state.dragState = null;
      rerender();
    });

    $builderRoot.on('click', '[data-act="duplicate-form"]', function () {
      if (!state.selectedKey || !state.forms[state.selectedKey]) return;
      var next = slugify(state.selectedKey + '_copy');
      var n = 2;
      while (state.forms[next]) { next = slugify(state.selectedKey + '_copy_' + n); n += 1; }
      state.forms[next] = clone(state.forms[state.selectedKey]);
      state.selectedKey = next;
      rerender();
    });

    $builderRoot.on('click', '[data-act="delete-form"]', function () {
      if (!state.selectedKey || !state.forms[state.selectedKey]) return;
      if (!confirm('Delete form "' + state.selectedKey + '"?')) return;
      delete state.forms[state.selectedKey];
      state.selectedKey = '';
      rerender();
    });

    $builderRoot.on('click', '[data-act="field-add"]', function () {
      var form = resolveContext(state); if (!form) return;
      form.fields.push(makeDefaultField(form.fields.length + 1));
      rerender();
    });

    $builderRoot.on('click', '[data-act="field-template-add"]', function () {
      var form = resolveContext(state); if (!form) return;
      applyTemplate(form, String($(this).data('template') || 'text'));
      rerender();
    });

    $builderRoot.on('click', '[data-act="field-insert-above"], [data-act="field-insert-below"], [data-act="field-delete"]', function () {
      var form = resolveContext(state); if (!form) return;
      var i = Number($(this).data('i'));
      if (i < 0 || i >= form.fields.length) return;
      var act = String($(this).data('act'));
      if (act === 'field-delete') form.fields.splice(i, 1);
      if (act === 'field-insert-above') form.fields.splice(i, 0, makeDefaultField(form.fields.length + 1));
      if (act === 'field-insert-below') form.fields.splice(i + 1, 0, makeDefaultField(form.fields.length + 1));
      rerender();
    });

    $builderRoot.on('click', '[data-act="section-add"], [data-act="section-delete"], [data-act="step-add"], [data-act="step-delete"], [data-act="rep-add"], [data-act="rep-delete"], [data-act="hs-add"], [data-act="hs-delete"], [data-act="tb-add"], [data-act="tb-delete"], [data-act="dn-add-field"], [data-act="dn-add-block"], [data-act="dn-delete"]', function () {
      var form = resolveContext(state); if (!form) return;
      var act = String($(this).data('act'));
      var i = Number($(this).data('i'));
      if (act === 'section-add') form.sections.push({ key: 'section_' + (form.sections.length + 1), label: 'Section ' + (form.sections.length + 1), field_keys: [] });
      if (act === 'section-delete' && i >= 0) form.sections.splice(i, 1);
      if (act === 'step-add') form.steps.push({ key: 'step_' + (form.steps.length + 1), label: 'Step ' + (form.steps.length + 1), section_keys: [] });
      if (act === 'step-delete' && i >= 0) form.steps.splice(i, 1);
      if (act === 'rep-add') form.repeaters.push({ key: 'rep_' + (form.repeaters.length + 1), label: 'Repeater ' + (form.repeaters.length + 1), field_keys: [], min: 0, max: 3 });
      if (act === 'rep-delete' && i >= 0) form.repeaters.splice(i, 1);
      if (act === 'hs-add') form.hard_stops.push({ label: 'Rule ' + (form.hard_stops.length + 1), severity: 'error', type: 'generic', message: '', when: [makeDefaultCondition()] });
      if (act === 'hs-delete' && i >= 0) form.hard_stops.splice(i, 1);
      if (act === 'tb-add') form.template_blocks.push({ block_id: 'blk_' + (form.template_blocks.length + 1), type: 'paragraph', text: '' });
      if (act === 'tb-delete' && i >= 0) form.template_blocks.splice(i, 1);
      if (act === 'dn-add-field') form.document_nodes.push({ type: 'field', field_key: '' });
      if (act === 'dn-add-block') form.document_nodes.push({ type: 'block', block_id: '' });
      if (act === 'dn-delete' && i >= 0) form.document_nodes.splice(i, 1);
      rerender();
    });

    $builderRoot.on('click', '[data-act="add-cond"], [data-act="del-cond"]', function () {
      var form = resolveContext(state); if (!form) return;
      var path = String($(this).data('path') || '');
      var ci = Number($(this).data('ci'));
      var bits = path.split(':');
      if (bits.length !== 2) return;
      var type = bits[0];
      var idx = Number(bits[1]);
      var target = type === 'field' ? (form.fields[idx] || null) : (form.hard_stops[idx] || null);
      if (!target) return;
      var list = type === 'field' ? asArray(target.conditions) : asArray(target.when);
      if ($(this).data('act') === 'add-cond') list.push(makeDefaultCondition());
      if ($(this).data('act') === 'del-cond' && ci >= 0 && ci < list.length) list.splice(ci, 1);
      if (type === 'field') target.conditions = list;
      else target.when = list;
      rerender();
    });

    $builderRoot.on('input change', 'input[data-act], select[data-act]', function () {
      var form = resolveContext(state); if (!form) return;
      var act = String($(this).data('act') || '');
      var i = Number($(this).data('i'));
      var val = $(this).is(':checkbox') ? $(this).is(':checked') : String($(this).val() || '');

      if (act === 'set-label') form.label = val;
      else if (act === 'set-recipients') form.recipients = val;
      else if (act === 'set-key') {
        var next = slugify(val);
        if (next && next !== state.selectedKey && !state.forms[next]) {
          state.forms[next] = form;
          delete state.forms[state.selectedKey];
          state.selectedKey = next;
        }
      } else if (i >= 0 && form.fields[i]) {
        if (act === 'field-key') form.fields[i].key = slugify(val);
        if (act === 'field-label') form.fields[i].label = val;
        if (act === 'field-type') form.fields[i].type = slugify(val) || 'text';
        if (act === 'field-required') form.fields[i].required = !!val;
      }

      if (i >= 0 && form.sections[i]) {
        if (act === 'section-key') form.sections[i].key = slugify(val);
        if (act === 'section-label') form.sections[i].label = val;
        if (act === 'section-fields') form.sections[i].field_keys = splitList(val);
      }
      if (i >= 0 && form.steps[i]) {
        if (act === 'step-key') form.steps[i].key = slugify(val);
        if (act === 'step-label') form.steps[i].label = val;
        if (act === 'step-sections') form.steps[i].section_keys = splitList(val);
      }
      if (i >= 0 && form.repeaters[i]) {
        if (act === 'rep-key') form.repeaters[i].key = slugify(val);
        if (act === 'rep-label') form.repeaters[i].label = val;
        if (act === 'rep-fields') form.repeaters[i].field_keys = splitList(val);
        if (act === 'rep-min') form.repeaters[i].min = Math.max(0, parseInt(val, 10) || 0);
        if (act === 'rep-max') form.repeaters[i].max = Math.max(form.repeaters[i].min || 0, parseInt(val, 10) || 0);
      }
      if (i >= 0 && form.hard_stops[i]) {
        if (act === 'hs-label') form.hard_stops[i].label = val;
        if (act === 'hs-severity') form.hard_stops[i].severity = slugify(val);
        if (act === 'hs-type') form.hard_stops[i].type = slugify(val);
        if (act === 'hs-message') form.hard_stops[i].message = val;
      }
      if (i >= 0 && form.template_blocks[i]) {
        if (act === 'tb-id') form.template_blocks[i].block_id = slugify(val);
        if (act === 'tb-type') form.template_blocks[i].type = slugify(val);
        if (act === 'tb-text') form.template_blocks[i].text = val;
      }
      if (i >= 0 && form.document_nodes[i]) {
        if (act === 'dn-type') form.document_nodes[i].type = val === 'block' ? 'block' : 'field';
        if (act === 'dn-field') form.document_nodes[i].field_key = slugify(val);
        if (act === 'dn-block') form.document_nodes[i].block_id = slugify(val);
      }

      if (/^set-cond-/.test(act)) {
        var path = String($(this).data('path') || '');
        var bits = path.split(':');
        var ci = Number($(this).data('ci'));
        if (bits.length === 2) {
          var type = bits[0];
          var idx = Number(bits[1]);
          var target = type === 'field' ? (form.fields[idx] || null) : (form.hard_stops[idx] || null);
          if (target) {
            var list = type === 'field' ? asArray(target.conditions) : asArray(target.when);
            if (list[ci]) {
              if (act === 'set-cond-field') list[ci].field = slugify(val);
              if (act === 'set-cond-op') list[ci].operator = slugify(val);
              if (act === 'set-cond-value') {
                var bitsv = String(val || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                if (list[ci].operator === 'in' || list[ci].operator === 'not_in') {
                  list[ci].values = bitsv;
                  delete list[ci].value;
                } else {
                  list[ci].value = bitsv[0] || '';
                  delete list[ci].values;
                }
              }
            }
            if (type === 'field') target.conditions = list;
            else target.when = list;
          }
        }
      }

      rerender();
    });

    $builderRoot.on('click', '[data-act="export-json"]', function () {
      var blob = new Blob([JSON.stringify(state.forms || {}, null, 2)], { type: 'application/json' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'dcb-forms-export.json';
      a.click();
      URL.revokeObjectURL(url);
    });

    $builderRoot.on('click', '[data-act="import-json"]', function () {
      var raw = prompt('Paste exported JSON');
      if (!raw) return;
      var parsed = tryParseJSON(raw);
      if (!parsed) return alert('Invalid JSON.');
      state.forms = parsed;
      state.selectedKey = Object.keys(parsed)[0] || '';
      rerender();
    });

    $('#th-df-builder-apply-raw').on('click', function () {
      var parsed = tryParseJSON($advanced.val());
      if (!parsed) return alert('Invalid JSON. Please fix syntax and try again.');
      state.forms = parsed;
      state.selectedKey = Object.keys(parsed)[0] || '';
      rerender();
    });

    $('#dcb-ocr-extract-review').on('click', function () {
      var canRun = String($ocrRoot.data('canRunOcr') || '0') === '1';
      if (!canRun) {
        alert('OCR extraction requires OCR tools capability.');
        return;
      }
      var fileInput = document.getElementById('dcb-ocr-seed-file');
      if (!fileInput || !fileInput.files || !fileInput.files.length) {
        alert('Choose a seed file first.');
        return;
      }
      var fd = new FormData();
      fd.append('action', 'dcb_builder_ocr_seed_extract');
      fd.append('nonce', String($ocrRoot.data('ocrNonce') || ''));
      fd.append('ocr_seed_file', fileInput.files[0]);
      fd.append('ocr_form_key', String($('#dcb-ocr-form-key').val() || ''));
      fd.append('ocr_form_label', String($('#dcb-ocr-form-label').val() || ''));
      fd.append('ocr_form_recipients', String($('#dcb-ocr-recipients').val() || ''));

      $ocrRoot.html('<p>Extracting OCR draft...</p>');
      $.ajax({ url: cfg.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false }).done(function (res) {
        if (!res || !res.success || !res.data) {
          $ocrRoot.html('<p style="color:#b32d2e">OCR extraction failed.</p>');
          return;
        }
        state.ocrDraftPayload = res.data;
        state.ocrReviewRows = asArray((res.data.raw_draft && res.data.raw_draft.ocr_candidates) || (res.data.draft && res.data.draft.ocr_candidates) || []).map(function (row) {
          row = row || {};
          row.accept = true;
          return row;
        });
        renderOcrWorkbench($ocrRoot, state);
      }).fail(function () {
        $ocrRoot.html('<p style="color:#b32d2e">OCR extraction failed.</p>');
      });
    });

    $ocrRoot.on('input change', 'input[data-act], select[data-act]', function () {
      var i = Number($(this).data('i'));
      if (i < 0 || i >= state.ocrReviewRows.length) return;
      var row = state.ocrReviewRows[i];
      var act = String($(this).data('act') || '');
      var val = $(this).is(':checkbox') ? $(this).is(':checked') : String($(this).val() || '');
      if (act === 'ocr-accept') row.accept = !!val;
      if (act === 'ocr-key') row.suggested_key = slugify(val);
      if (act === 'ocr-label') row.field_label = val;
      if (act === 'ocr-type') row.suggested_type = slugify(val);
      if (act === 'ocr-required') row.required_guess = !!val;
      renderOcrWorkbench($ocrRoot, state);
    });

    $ocrRoot.on('click', '[data-act="ocr-apply-draft"]', function () {
      if (!state.ocrDraftPayload) return;
      var payload = clone(state.ocrDraftPayload);
      var accepted = state.ocrReviewRows.filter(function (r) { return r.accept !== false; });
      if (!accepted.length) {
        alert('No accepted candidates to apply.');
        return;
      }
      payload.raw_draft = payload.raw_draft || {};
      payload.raw_draft.ocr_candidates = state.ocrReviewRows.map(function (r) {
        return $.extend({}, r, { decision: r.accept === false ? 'reject' : 'accept' });
      });
      var seenKeys = {};
      payload.raw_draft.fields = accepted.map(function (r, idx) {
        var base = slugify(r.suggested_key || '') || slugify(r.field_label || '') || ('ocr_field_' + (idx + 1));
        var key = base;
        var n = 2;
        while (seenKeys[key]) {
          key = base + '_' + n;
          n += 1;
        }
        seenKeys[key] = true;

        return {
          key: key,
          label: String(r.field_label || key),
          type: slugify(r.suggested_type || 'text') || 'text',
          required: !!r.required_guess,
          ocr_meta: {
            page_number: Number(r.page_number || 1),
            source_text_snippet: String(r.source_text_snippet || ''),
            confidence_bucket: String(r.confidence_bucket || 'low'),
            confidence_score: Number(r.confidence_score || 0),
            source_engine: String(r.source_engine || ''),
            geometry: r.geometry && typeof r.geometry === 'object' ? {
              x: Number(r.geometry.x || 0),
              y: Number(r.geometry.y || 0),
              w: Number(r.geometry.w || 0),
              h: Number(r.geometry.h || 0),
              unit: String(r.geometry.unit || 'page_ratio')
            } : undefined,
            review_state: 'confirmed'
          }
        };
      });

      var fieldKeys = payload.raw_draft.fields.map(function (f) { return String(f.key || ''); }).filter(Boolean);
      payload.raw_draft.sections = asArray(payload.raw_draft.sections).map(function (s) {
        var section = $.extend({}, s || {});
        section.field_keys = asArray(section.field_keys).map(function (k) { return String(k || ''); }).filter(function (k) { return fieldKeys.indexOf(k) >= 0; });
        return section;
      }).filter(function (s) { return asArray(s.field_keys).length > 0; });

      payload.raw_draft.document_nodes = asArray(payload.raw_draft.document_nodes).filter(function (n) {
        if (!n || typeof n !== 'object') return false;
        if (String(n.type || '') === 'field') {
          return fieldKeys.indexOf(String(n.field_key || '')) >= 0;
        }
        return String(n.type || '') === 'block';
      });

      payload.raw_draft.ocr_review = payload.raw_draft.ocr_review && typeof payload.raw_draft.ocr_review === 'object' ? payload.raw_draft.ocr_review : {};
      payload.raw_draft.ocr_review.layout_fidelity_preview = computeOcrLayoutFidelity(state);
      payload.raw_draft.ocr_review.accepted_candidate_count = accepted.length;
      payload.raw_draft.ocr_review.reviewed_candidate_count = state.ocrReviewRows.length;

      upsertFormFromOcrDraft(state, payload);
      state.ocrDraftPayload = null;
      state.ocrReviewRows = [];
      rerender();
      $ocrRoot.html('<div class="notice notice-success inline"><p>OCR draft applied to builder. Save Builder to persist.</p></div>');
    });

    $('form[action*="admin-post.php"]').on('submit', function () {
      var parsed = tryParseJSON($advanced.val());
      if (parsed) $hidden.val(JSON.stringify(parsed));
    });
  });
})(jQuery);
