<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_chart_routing_mode_labels(): array {
    return array(
        'none_manual' => 'None / Manual',
        'api' => 'API Connector',
        'bot' => 'Bot Connector',
        'report_import' => 'Report Import Connector',
    );
}

function dcb_chart_routing_document_types(): array {
    return array(
        'consent' => 'Consent',
        'intake' => 'Intake',
        'physician_order' => 'Physician Order',
        'visit_note' => 'Visit Note',
        'eval' => 'Evaluation',
        'miscellaneous' => 'Miscellaneous',
    );
}

function dcb_chart_routing_normalize_document_type(string $type): string {
    $type = sanitize_key($type);
    $allowed = array_keys(dcb_chart_routing_document_types());
    return in_array($type, $allowed, true) ? $type : 'miscellaneous';
}

function dcb_chart_routing_extract_identifiers(array $payload): array {
    $text = (string) ($payload['ocr_text'] ?? ($payload['text'] ?? ''));
    $provided = isset($payload['extracted_identifiers']) && is_array($payload['extracted_identifiers']) ? $payload['extracted_identifiers'] : array();

    $patient_name = sanitize_text_field((string) ($provided['patient_name'] ?? ''));
    $dob = sanitize_text_field((string) ($provided['dob'] ?? ''));
    $mrn = sanitize_text_field((string) ($provided['mrn'] ?? ($provided['patient_id'] ?? '')));
    $visit_date = sanitize_text_field((string) ($provided['visit_date'] ?? ($provided['service_date'] ?? '')));
    $clinician_name = sanitize_text_field((string) ($provided['clinician_name'] ?? ''));

    if ($mrn === '' && preg_match('/(?:mrn|patient\s*id|chart\s*id)\s*[:#]?\s*([A-Z0-9\-]{4,30})/i', $text, $m)) {
        $mrn = sanitize_text_field((string) ($m[1] ?? ''));
    }

    if ($dob === '' && preg_match('/(?:dob|date\s+of\s+birth)\s*[:#]?\s*([0-9]{1,2}[\/-][0-9]{1,2}[\/-][0-9]{2,4})/i', $text, $m)) {
        $dob = sanitize_text_field((string) ($m[1] ?? ''));
    }

    if ($visit_date === '' && preg_match('/(?:date\s+of\s+service|service\s+date|visit\s+date)\s*[:#]?\s*([0-9]{1,2}[\/-][0-9]{1,2}[\/-][0-9]{2,4})/i', $text, $m)) {
        $visit_date = sanitize_text_field((string) ($m[1] ?? ''));
    }

    if ($patient_name === '' && preg_match('/(?:patient(?:\s+name)?)\s*[:#]?\s*([A-Za-z\'\-\., ]{3,80})/i', $text, $m)) {
        $patient_name = sanitize_text_field(trim((string) ($m[1] ?? '')));
    }

    if ($clinician_name === '' && preg_match('/(?:clinician|provider|physician)\s*[:#]?\s*([A-Za-z\'\-\., ]{3,80})/i', $text, $m)) {
        $clinician_name = sanitize_text_field(trim((string) ($m[1] ?? '')));
    }

    $identifiers = array(
        'patient_name' => $patient_name,
        'dob' => $dob,
        'mrn' => $mrn,
        'visit_date' => $visit_date,
        'clinician_name' => $clinician_name,
        'source_channel' => sanitize_key((string) ($payload['source_channel'] ?? 'direct_upload')),
        'document_type_hint' => dcb_chart_routing_normalize_document_type((string) ($payload['document_type_hint'] ?? 'miscellaneous')),
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_chart_routing_extracted_identifiers', $identifiers, $payload) : $identifiers;
}

function dcb_chart_routing_classify_document_type(array $payload): array {
    $text = strtolower((string) ($payload['ocr_text'] ?? ($payload['text'] ?? '')));
    $hint = dcb_chart_routing_normalize_document_type((string) ($payload['hint'] ?? ($payload['document_type_hint'] ?? '')));

    $rules = array(
        'consent' => array('consent', 'authorization', 'release of information', 'hipaa'),
        'intake' => array('intake', 'demographics', 'new patient', 'registration'),
        'physician_order' => array('physician order', 'doctor order', 'order form', 'prescription'),
        'visit_note' => array('progress note', 'visit note', 'soap', 'subjective'),
        'eval' => array('evaluation', 'assessment', 'initial eval', 're-evaluation'),
    );

    $scores = array();
    foreach ($rules as $type => $needles) {
        $scores[$type] = 0.0;
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($text, $needle) !== false) {
                $scores[$type] += 0.18;
            }
        }
    }

    if ($hint !== 'miscellaneous' && isset($scores[$hint])) {
        $scores[$hint] += 0.25;
    }

    arsort($scores);
    $top_type = 'miscellaneous';
    $top_score = 0.0;
    if (!empty($scores)) {
        $top_type = (string) array_key_first($scores);
        $top_score = (float) ($scores[$top_type] ?? 0.0);
    }

    if ($top_score <= 0.0) {
        $top_type = $hint !== 'miscellaneous' ? $hint : 'miscellaneous';
        $top_score = $hint !== 'miscellaneous' ? 0.35 : 0.2;
    }

    $top_score = max(0.0, min(1.0, $top_score));
    $confidence_tier = dcb_chart_routing_confidence_tier($top_score, false, true);

    return array(
        'document_type' => dcb_chart_routing_normalize_document_type($top_type),
        'confidence' => round($top_score, 4),
        'confidence_tier' => $confidence_tier,
        'is_uncertain' => $top_score < 0.6,
        'scored_labels' => $scores,
    );
}

function dcb_chart_routing_confidence_tier(float $score, bool $name_only_match = false, bool $allow_no_match = true): string {
    $score = max(0.0, min(1.0, $score));

    if ($allow_no_match && $score <= 0.0) {
        return 'no_match';
    }

    if ($name_only_match) {
        return $score >= 0.2 ? 'low_confidence' : 'no_match';
    }

    if ($score >= 0.85) {
        return 'high_confidence';
    }
    if ($score >= 0.65) {
        return 'medium_confidence';
    }
    if ($score > 0.0) {
        return 'low_confidence';
    }

    return 'no_match';
}

function dcb_chart_routing_score_candidate(array $identifiers, array $candidate): array {
    $score = 0.0;
    $reasons = array();
    $evidence = array();

    $candidate_name = strtolower(trim((string) ($candidate['full_name'] ?? ($candidate['patient_name'] ?? ''))));
    $candidate_dob = strtolower(trim((string) ($candidate['dob'] ?? '')));
    $candidate_mrn = strtolower(trim((string) ($candidate['mrn'] ?? ($candidate['patient_id'] ?? ''))));
    $candidate_visit = strtolower(trim((string) ($candidate['visit_date'] ?? ($candidate['service_date'] ?? ''))));
    $candidate_clinician = strtolower(trim((string) ($candidate['clinician_name'] ?? '')));

    $name = strtolower(trim((string) ($identifiers['patient_name'] ?? '')));
    $dob = strtolower(trim((string) ($identifiers['dob'] ?? '')));
    $mrn = strtolower(trim((string) ($identifiers['mrn'] ?? '')));
    $visit_date = strtolower(trim((string) ($identifiers['visit_date'] ?? '')));
    $clinician = strtolower(trim((string) ($identifiers['clinician_name'] ?? '')));

    if ($mrn !== '' && $candidate_mrn !== '' && $mrn === $candidate_mrn) {
        $score += 0.65;
        $reasons[] = 'Exact MRN/patient ID match';
        $evidence[] = 'mrn_exact';
    }

    if ($name !== '' && $candidate_name !== '') {
        if ($name === $candidate_name) {
            $score += 0.30;
            $reasons[] = 'Exact patient name match';
            $evidence[] = 'name_exact';
        } elseif (strpos($candidate_name, $name) !== false || strpos($name, $candidate_name) !== false) {
            $score += 0.15;
            $reasons[] = 'Partial patient name match';
            $evidence[] = 'name_partial';
        }
    }

    if ($dob !== '' && $candidate_dob !== '' && $dob === $candidate_dob) {
        $score += 0.30;
        $reasons[] = 'DOB match';
        $evidence[] = 'dob_exact';
    }

    if ($visit_date !== '' && $candidate_visit !== '' && $visit_date === $candidate_visit) {
        $score += 0.15;
        $reasons[] = 'Visit/service date match';
        $evidence[] = 'visit_date_exact';
    }

    if ($clinician !== '' && $candidate_clinician !== '' && $clinician === $candidate_clinician) {
        $score += 0.10;
        $reasons[] = 'Clinician match';
        $evidence[] = 'clinician_exact';
    }

    $name_only = !empty($evidence) && count(array_diff($evidence, array('name_exact', 'name_partial'))) === 0;
    if ($name_only) {
        $score = min($score, 0.49);
        $reasons[] = 'Name-only evidence: requires manual review';
        $evidence[] = 'name_only_guardrail';
    }

    $score = max(0.0, min(1.0, $score));
    $tier = dcb_chart_routing_confidence_tier($score, $name_only, true);

    $candidate['score'] = round($score, 4);
    $candidate['confidence_tier'] = $tier;
    $candidate['reasons'] = array_values(array_unique($reasons));
    $candidate['evidence'] = array_values(array_unique($evidence));
    $candidate['name_only_match'] = $name_only;

    if (empty($candidate['candidate_key'])) {
        $candidate['candidate_key'] = substr(hash('sha256', wp_json_encode(array(
            'name' => (string) ($candidate['full_name'] ?? ($candidate['patient_name'] ?? '')),
            'dob' => (string) ($candidate['dob'] ?? ''),
            'mrn' => (string) ($candidate['mrn'] ?? ($candidate['patient_id'] ?? '')),
            'chart_target' => (string) ($candidate['chart_target_id'] ?? ''),
        ))), 0, 20);
    }

    return $candidate;
}

function dcb_chart_routing_rank_candidates(array $identifiers, array $candidates): array {
    $rows = array();
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $rows[] = dcb_chart_routing_score_candidate($identifiers, $candidate);
    }

    usort($rows, static function ($a, $b) {
        $as = (float) ($a['score'] ?? 0.0);
        $bs = (float) ($b['score'] ?? 0.0);
        if ($as === $bs) {
            return 0;
        }
        return $as > $bs ? -1 : 1;
    });

    return $rows;
}

function dcb_chart_routing_build_match_result(array $identifiers, array $candidates): array {
    $ranked = dcb_chart_routing_rank_candidates($identifiers, $candidates);
    $top = !empty($ranked) ? $ranked[0] : array();
    $top_score = (float) ($top['score'] ?? 0.0);
    $name_only = !empty($top['name_only_match']);
    $tier = dcb_chart_routing_confidence_tier($top_score, $name_only, true);

    return array(
        'confidence_tier' => $tier,
        'confidence_score' => round($top_score, 4),
        'top_candidate' => $top,
        'candidates' => $ranked,
        'name_only_guardrail_triggered' => $name_only,
        'auto_route_allowed' => $tier === 'high_confidence' && !$name_only,
    );
}

function dcb_chart_routing_queue_payload_shape(array $payload): array {
    return array(
        'source_artifact_id' => max(0, (int) ($payload['source_artifact_id'] ?? 0)),
        'trace_id' => sanitize_text_field((string) ($payload['trace_id'] ?? '')),
        'extracted_identifiers' => isset($payload['extracted_identifiers']) && is_array($payload['extracted_identifiers']) ? $payload['extracted_identifiers'] : array(),
        'candidate_count' => max(0, (int) ($payload['candidate_count'] ?? 0)),
        'confidence_tier' => sanitize_key((string) ($payload['confidence_tier'] ?? 'no_match')),
        'confidence_score' => max(0.0, min(1.0, (float) ($payload['confidence_score'] ?? 0.0))),
        'document_type' => dcb_chart_routing_normalize_document_type((string) ($payload['document_type'] ?? 'miscellaneous')),
        'document_type_confidence' => max(0.0, min(1.0, (float) ($payload['document_type_confidence'] ?? 0.0))),
        'route_status' => sanitize_key((string) ($payload['route_status'] ?? 'needs_review')),
        'connector_mode' => sanitize_key((string) ($payload['connector_mode'] ?? 'none_manual')),
    );
}

function dcb_chart_routing_audit_payload_shape(array $payload): array {
    return array(
        'source_artifact_id' => max(0, (int) ($payload['source_artifact_id'] ?? 0)),
        'trace_id' => sanitize_text_field((string) ($payload['trace_id'] ?? '')),
        'extracted_identifiers_snapshot' => isset($payload['extracted_identifiers_snapshot']) && is_array($payload['extracted_identifiers_snapshot']) ? $payload['extracted_identifiers_snapshot'] : array(),
        'candidate_list_summary' => isset($payload['candidate_list_summary']) && is_array($payload['candidate_list_summary']) ? $payload['candidate_list_summary'] : array(),
        'chosen_patient_target' => isset($payload['chosen_patient_target']) && is_array($payload['chosen_patient_target']) ? $payload['chosen_patient_target'] : array(),
        'chosen_document_type' => dcb_chart_routing_normalize_document_type((string) ($payload['chosen_document_type'] ?? 'miscellaneous')),
        'route_method' => sanitize_key((string) ($payload['route_method'] ?? 'manual')),
        'confirmed_by_user_id' => max(0, (int) ($payload['confirmed_by_user_id'] ?? 0)),
        'confirmed_by_name' => sanitize_text_field((string) ($payload['confirmed_by_name'] ?? '')),
        'confirmed_at' => sanitize_text_field((string) ($payload['confirmed_at'] ?? current_time('mysql'))),
        'result' => sanitize_key((string) ($payload['result'] ?? 'unknown')),
        'result_message' => sanitize_text_field((string) ($payload['result_message'] ?? '')),
    );
}
