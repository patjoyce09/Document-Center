<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class DCB_Workflow_Queue_Table extends WP_List_Table {
    private array $filters = array();
    private array $statuses = array();
    private array $reviewers = array();
    private array $roles = array();

    public function __construct() {
        parent::__construct(array(
            'singular' => 'dcb-review-item',
            'plural' => 'dcb-review-queue',
            'ajax' => false,
        ));

        $this->filters = DCB_Workflow::get_queue_filters_from_request($_REQUEST);
        $this->statuses = DCB_Workflow::statuses();
        $this->reviewers = DCB_Workflow::get_queue_reviewers();
        $this->roles = DCB_Workflow::get_queue_roles();
    }

    public function get_columns(): array {
        return array(
            'cb' => '<input type="checkbox" />',
            'id' => __('ID', 'document-center-builder'),
            'record' => __('Record', 'document-center-builder'),
            'status' => __('Status', 'document-center-builder'),
            'age' => __('Age', 'document-center-builder'),
            'signals' => __('Signals', 'document-center-builder'),
            'assignee' => __('Assignee', 'document-center-builder'),
            'role_queue' => __('Role Queue', 'document-center-builder'),
            'review_thread' => __('Review Thread', 'document-center-builder'),
            'submitted' => __('Submitted', 'document-center-builder'),
            'actions' => __('Actions', 'document-center-builder'),
        );
    }

    protected function get_sortable_columns(): array {
        return array(
            'id' => array('id', true),
            'record' => array('record', false),
            'status' => array('status', false),
            'age' => array('age', false),
            'assignee' => array('assignee', false),
            'submitted' => array('submitted', false),
        );
    }

    protected function get_bulk_actions(): array {
        return array(
            'bulk_status_in_review' => __('Set status: In Review', 'document-center-builder'),
            'bulk_status_needs_correction' => __('Set status: Needs Correction', 'document-center-builder'),
            'bulk_status_approved' => __('Set status: Approved', 'document-center-builder'),
            'bulk_status_rejected' => __('Set status: Rejected', 'document-center-builder'),
            'bulk_status_finalized' => __('Set status: Finalized', 'document-center-builder'),
        );
    }

    protected function extra_tablenav($which): void {
        if ($which !== 'top') {
            return;
        }

        $status_filter = sanitize_key((string) ($this->filters['status'] ?? ''));
        $assignee_filter = isset($this->filters['assignee']) ? (int) $this->filters['assignee'] : -1;
        $role_filter = sanitize_key((string) ($this->filters['role'] ?? ''));
        $form_filter = sanitize_key((string) ($this->filters['form'] ?? ''));
        $date_from = sanitize_text_field((string) ($this->filters['date_from'] ?? ''));
        $date_to = sanitize_text_field((string) ($this->filters['date_to'] ?? ''));

        echo '<div class="alignleft actions" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">';

        echo '<label class="screen-reader-text" for="dcb-workflow-status-filter">' . esc_html__('Filter by status', 'document-center-builder') . '</label>';
        echo '<select id="dcb-workflow-status-filter" name="workflow_status">';
        echo '<option value="">' . esc_html__('All statuses', 'document-center-builder') . '</option>';
        foreach ($this->statuses as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status_filter, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="dcb-workflow-assignee-filter">' . esc_html__('Filter by assignee', 'document-center-builder') . '</label>';
        echo '<select id="dcb-workflow-assignee-filter" name="workflow_assignee">';
        echo '<option value="-1">' . esc_html__('All assignees', 'document-center-builder') . '</option>';
        echo '<option value="0" ' . selected($assignee_filter, 0, false) . '>' . esc_html__('Unassigned', 'document-center-builder') . '</option>';
        foreach ($this->reviewers as $reviewer) {
            if (!$reviewer instanceof WP_User) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $reviewer->ID) . '" ' . selected($assignee_filter, (int) $reviewer->ID, false) . '>' . esc_html(DCB_Workflow::reviewer_display_label($reviewer, (int) $reviewer->ID)) . '</option>';
        }
        echo '</select>';

        echo '<label class="screen-reader-text" for="dcb-workflow-role-filter">' . esc_html__('Filter by role queue', 'document-center-builder') . '</label>';
        echo '<select id="dcb-workflow-role-filter" name="workflow_role">';
        echo '<option value="">' . esc_html__('All role queues', 'document-center-builder') . '</option>';
        foreach ($this->roles as $role_key => $role_data) {
            $role_key = sanitize_key((string) $role_key);
            $name = is_array($role_data) ? (string) ($role_data['name'] ?? $role_key) : $role_key;
            echo '<option value="' . esc_attr($role_key) . '" ' . selected($role_filter, $role_key, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select>';

        echo '<input type="text" name="workflow_form" value="' . esc_attr($form_filter) . '" placeholder="' . esc_attr__('Form key', 'document-center-builder') . '" />';
        echo '<input type="date" name="workflow_date_from" value="' . esc_attr($date_from) . '" />';
        echo '<input type="date" name="workflow_date_to" value="' . esc_attr($date_to) . '" />';

        submit_button(__('Filter queue', 'document-center-builder'), 'secondary', 'filter_action', false);

        echo '</div>';

        echo '<div class="alignright actions">';
        echo '<select name="bulk_assignee_user_id">';
        echo '<option value="">' . esc_html__('Bulk assign reviewer…', 'document-center-builder') . '</option>';
        echo '<option value="0">' . esc_html__('Unassigned', 'document-center-builder') . '</option>';
        foreach ($this->reviewers as $reviewer) {
            if (!$reviewer instanceof WP_User) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $reviewer->ID) . '">' . esc_html(DCB_Workflow::reviewer_display_label($reviewer, (int) $reviewer->ID)) . '</option>';
        }
        echo '</select>';
        submit_button(__('Apply Assignee', 'document-center-builder'), 'secondary', 'dcb_bulk_assign_submit', false);
        echo '</div>';
    }

    public function no_items(): void {
        $has_filters = !empty($this->filters['status']) || (isset($this->filters['assignee']) && (int) $this->filters['assignee'] >= 0) || !empty($this->filters['role']) || !empty($this->filters['form']) || !empty($this->filters['date_from']) || !empty($this->filters['date_to']);
        if ($has_filters) {
            esc_html_e('No submissions match the current filters.', 'document-center-builder');
            return;
        }
        esc_html_e('No submissions in queue yet.', 'document-center-builder');
    }

    protected function column_cb($item): string {
        $submission_id = $item instanceof WP_Post ? (int) $item->ID : 0;
        if ($submission_id < 1) {
            return '';
        }

        $token = DCB_Workflow::queue_state_token($submission_id);
        return '<input type="checkbox" name="submission_ids[]" value="' . esc_attr((string) $submission_id) . '" />'
            . '<input type="hidden" name="state_tokens[' . esc_attr((string) $submission_id) . ']" value="' . esc_attr($token) . '" />';
    }

    protected function column_id($item): string {
        return (string) (int) ($item instanceof WP_Post ? $item->ID : 0);
    }

    protected function column_record($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        $title = get_the_title($submission_id);
        $edit_url = admin_url('post.php?post=' . $submission_id . '&action=edit');

        $output = '<strong><a href="' . esc_url($edit_url) . '">' . esc_html((string) $title) . '</a></strong>';
        $actions = array(
            'open' => '<a href="' . esc_url($edit_url) . '">' . esc_html__('Open', 'document-center-builder') . '</a>',
            'print' => '<a href="' . esc_url(DCB_Renderer::submission_print_url($submission_id)) . '">' . esc_html__('Print', 'document-center-builder') . '</a>',
            'json' => '<a href="' . esc_url(DCB_Renderer::submission_export_url($submission_id)) . '">' . esc_html__('JSON', 'document-center-builder') . '</a>',
        );

        $output .= $this->row_actions($actions);
        return $output;
    }

    protected function column_status($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        $status = DCB_Workflow::get_status($submission_id);
        $label = (string) ($this->statuses[$status] ?? $status);

        $out = '<strong>' . esc_html($label) . '</strong>';
        if ($status === 'finalized') {
            $out .= '<div><span class="description">' . esc_html__('Finalized (protected)', 'document-center-builder') . '</span></div>';
        }

        return $out;
    }

    protected function column_assignee($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        $assignee_user_id = (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true);
        $assignee_user = $assignee_user_id > 0 ? get_user_by('id', $assignee_user_id) : null;

        return esc_html(DCB_Workflow::reviewer_display_label($assignee_user instanceof WP_User ? $assignee_user : null, $assignee_user_id));
    }

    protected function column_age($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        $age_days = DCB_Workflow::queue_age_days($submission_id);
        return esc_html(sprintf(__('%d day(s)', 'document-center-builder'), $age_days));
    }

    protected function column_signals($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $signal = DCB_Workflow::queue_signals_for_submission((int) $item->ID);
        $parts = array();
        if (!empty($signal['overdue'])) {
            $parts[] = '<span class="notice-inline" style="color:#b32d2e;font-weight:600;">' . esc_html__('Overdue', 'document-center-builder') . '</span>';
        } elseif (!empty($signal['stale'])) {
            $parts[] = '<span class="notice-inline" style="color:#996800;font-weight:600;">' . esc_html__('Stale review', 'document-center-builder') . '</span>';
        }

        if (empty($parts)) {
            $parts[] = '<span class="description">' . esc_html__('Within SLA', 'document-center-builder') . '</span>';
        }

        return implode('<br/>', $parts);
    }

    protected function column_role_queue($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        $assignee_role = sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_assignee_role', true));
        return $assignee_role !== '' ? esc_html($assignee_role) : '—';
    }

    protected function column_review_thread($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        $snippet = DCB_Workflow::queue_latest_thread_snippets($submission_id);
        $parts = array();

        if (!empty($snippet['correction'])) {
            $parts[] = '<div><strong>' . esc_html__('Correction:', 'document-center-builder') . '</strong> ' . esc_html((string) $snippet['correction']) . '</div>';
        }
        if (!empty($snippet['review'])) {
            $parts[] = '<div><strong>' . esc_html__('Review:', 'document-center-builder') . '</strong> ' . esc_html((string) $snippet['review']) . '</div>';
        }

        return !empty($parts) ? implode('', $parts) : '—';
    }

    protected function column_submitted($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        return esc_html((string) get_post_meta($submission_id, '_dcb_form_submitted_at', true));
    }

    protected function column_actions($item): string {
        if (!$item instanceof WP_Post) {
            return '—';
        }

        $submission_id = (int) $item->ID;
        $status = DCB_Workflow::get_status($submission_id);
        $allowed = DCB_Workflow::transitions()[$status] ?? array();
        $assignee_user_id = (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true);
        $state_token = DCB_Workflow::queue_state_token($submission_id);

        $out = '';

        if (!empty($allowed)) {
            $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:6px;">';
            wp_nonce_field('dcb_workflow_queue_action', 'dcb_workflow_queue_nonce');
            $out .= '<input type="hidden" name="action_replay_token" value="' . esc_attr(DCB_Workflow::new_action_replay_token()) . '" />';
            $out .= '<input type="hidden" name="action" value="dcb_workflow_queue_action" />';
            $out .= '<input type="hidden" name="queue_action" value="quick_transition" />';
            $out .= '<input type="hidden" name="submission_id" value="' . esc_attr((string) $submission_id) . '" />';
            $out .= '<input type="hidden" name="from_status" value="' . esc_attr($status) . '" />';
            $out .= '<input type="hidden" name="state_token" value="' . esc_attr($state_token) . '" />';
            $out .= '<select name="to_status">';
            foreach ($allowed as $next_status) {
                $label = (string) ($this->statuses[$next_status] ?? $next_status);
                $out .= '<option value="' . esc_attr($next_status) . '">' . esc_html($label) . '</option>';
            }
            $out .= '</select> ';
            $out .= '<button type="submit" class="button button-small">' . esc_html__('Set', 'document-center-builder') . '</button>';
            $out .= '</form>';
        } else {
            $out .= '<div class="description" style="margin-bottom:6px;">' . esc_html__('No further transitions.', 'document-center-builder') . '</div>';
        }

        $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_workflow_queue_action', 'dcb_workflow_queue_nonce');
        $out .= '<input type="hidden" name="action_replay_token" value="' . esc_attr(DCB_Workflow::new_action_replay_token()) . '" />';
        $out .= '<input type="hidden" name="action" value="dcb_workflow_queue_action" />';
        $out .= '<input type="hidden" name="queue_action" value="quick_assign" />';
        $out .= '<input type="hidden" name="submission_id" value="' . esc_attr((string) $submission_id) . '" />';
        $out .= '<input type="hidden" name="expected_assignee_user_id" value="' . esc_attr((string) $assignee_user_id) . '" />';
        $out .= '<input type="hidden" name="state_token" value="' . esc_attr($state_token) . '" />';
        $out .= '<select name="assignee_user_id">';
        $out .= '<option value="0">' . esc_html__('Unassigned', 'document-center-builder') . '</option>';
        foreach ($this->reviewers as $reviewer) {
            if (!$reviewer instanceof WP_User) {
                continue;
            }
            $out .= '<option value="' . esc_attr((string) $reviewer->ID) . '" ' . selected($assignee_user_id, (int) $reviewer->ID, false) . '>' . esc_html(DCB_Workflow::reviewer_display_label($reviewer, (int) $reviewer->ID)) . '</option>';
        }
        $out .= '</select> ';
        $out .= '<button type="submit" class="button button-small">' . esc_html__('Assign', 'document-center-builder') . '</button>';
        $out .= '</form>';

        return $out;
    }

    protected function column_default($item, $column_name): string {
        return '—';
    }

    public function prepare_items(): void {
        $per_page = (int) $this->get_items_per_page('dcb_review_queue_per_page', 20);
        $per_page = max(5, $per_page);
        $paged = max(1, $this->get_pagenum());

        $orderby = sanitize_key((string) ($_REQUEST['orderby'] ?? 'submitted'));
        $order = strtoupper(sanitize_text_field((string) ($_REQUEST['order'] ?? 'DESC')));
        if (!in_array($order, array('ASC', 'DESC'), true)) {
            $order = 'DESC';
        }

        $query_args = array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'order' => $order,
            'meta_query' => DCB_Workflow::queue_meta_query($this->filters),
            'date_query' => DCB_Workflow::queue_date_query($this->filters),
        );

        if ($orderby === 'id') {
            $query_args['orderby'] = 'ID';
        } elseif ($orderby === 'record') {
            $query_args['orderby'] = 'title';
        } elseif ($orderby === 'status') {
            $query_args['meta_key'] = '_dcb_workflow_status';
            $query_args['orderby'] = 'meta_value';
        } elseif ($orderby === 'age') {
            $query_args['meta_key'] = '_dcb_form_submitted_at';
            $query_args['orderby'] = 'meta_value';
            $query_args['order'] = $order === 'ASC' ? 'DESC' : 'ASC';
        } elseif ($orderby === 'assignee') {
            $query_args['meta_key'] = '_dcb_workflow_assignee_user_id';
            $query_args['orderby'] = 'meta_value_num';
        } elseif ($orderby === 'submitted') {
            $query_args['meta_key'] = '_dcb_form_submitted_at';
            $query_args['orderby'] = 'meta_value';
        } else {
            $query_args['orderby'] = 'date';
        }

        $query = new WP_Query($query_args);
        $this->items = is_array($query->posts) ? $query->posts : array();

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns(), 'record');

        $this->set_pagination_args(array(
            'total_items' => (int) $query->found_posts,
            'per_page' => $per_page,
            'total_pages' => max(1, (int) $query->max_num_pages),
        ));
    }
}
