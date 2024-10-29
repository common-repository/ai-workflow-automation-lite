<?php
/**
 * The admin-specific functionality of the plugin.
 */
class WP_AI_Workflows_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu() {
        $capability = 'manage_options';
        WP_AI_Workflows_Utilities::debug_log("Adding admin menu page", "debug", [
            'capability' => $capability,
            'user_has_capability' => current_user_can($capability)
        ]);

        $icon_url = WP_AI_WORKFLOWS_PLUGIN_URL . 'images/AWAIcon.png';
        add_menu_page(
            'AI Workflows Lite',
            'AI Workflows Lite',
            $capability,
            'wp-ai-workflows',
            array($this, 'render_app'),
            $icon_url,
            30
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_wp-ai-workflows' !== $hook) {
            return;
        }

        $js_files = glob(WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/js/main.*.js');
        $css_files = glob(WP_AI_WORKFLOWS_PLUGIN_DIR . 'build/static/css/main.*.css');

        if (!empty($js_files)) {
            wp_enqueue_script(
                'wp-ai-workflows-app',
                WP_AI_WORKFLOWS_PLUGIN_URL . 'build/static/js/' . basename($js_files[0]),
                array(),
                WP_AI_WORKFLOWS_LITE_VERSION,
                true
            );
        }

        if (!empty($css_files)) {
            wp_enqueue_style(
                'wp-ai-workflows-app',
                WP_AI_WORKFLOWS_PLUGIN_URL . 'build/static/css/' . basename($css_files[0]),
                array(),
                WP_AI_WORKFLOWS_LITE_VERSION
            );
        }

        wp_localize_script('wp-ai-workflows-app', 'wp_ai_workflows_vars', array(
            'nonce' => wp_create_nonce('wp_ai_workflows_nonce')
        ));
    }

    public function render_app() {   

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'unknown';
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : 'unknown';

        WP_AI_Workflows_Utilities::debug_log("Attempting to render admin app", "debug", [
            'page' => $page,
            'action' => $action,
            'user_id' => get_current_user_id(),
            'user_roles' => wp_get_current_user()->roles
        ]);

        if ($page !== 'wp-ai-workflows') {
            WP_AI_Workflows_Utilities::debug_log("Attempted to render admin app on incorrect page", "warning", [
                'current_page' => $page
            ]);
            return;
        }

        $current_page = $action !== 'unknown' ? $action : 'management';
        $workflow_id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : null;
        
        $workflow_data = null;
        if (($current_page === 'edit' || $current_page === 'builder') && $workflow_id) {
            try {
                $request = new WP_REST_Request('GET', '/wp-ai-workflows/v1/workflows/' . $workflow_id);
                $request->set_param('id', $workflow_id);
                $response = rest_do_request($request);
                
                if ($response->is_error()) {
                    WP_AI_Workflows_Utilities::debug_log("Error fetching workflow", "error", [
                        'workflow_id' => $workflow_id,
                        'error' => $response->get_error_message()
                    ]);
                } else {
                    $workflow_data = $response->get_data();
                }
            } catch (Exception $e) {
                WP_AI_Workflows_Utilities::debug_log("Exception while fetching workflow", "error", [
                    'workflow_id' => $workflow_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $settings = array(
            'root' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('wp_rest'),
            'page' => $current_page,
            'workflowData' => $workflow_data,
            'isLiteVersion' => true
        );
        
        wp_localize_script('wp-ai-workflows-app', 'wpAiWorkflowsSettings', $settings);

        WP_AI_Workflows_Utilities::debug_log("Rendering admin app", "debug", [
            'page' => $current_page,
            'workflow_id' => $workflow_id,
            'has_workflow_data' => !is_null($workflow_data)
        ]);

        echo '<div id="wp-ai-workflows-root"></div>';

        echo '<div style="text-align: center; margin-top: 20px; padding: 10px; border-top: 1px solid #ccc;">';
        echo '&copy; ' . esc_html(gmdate('Y')) . ' WP AI Workflows Lite. All rights reserved. ';
        echo '<a href="https://wpaiworkflowautomation.com/#pricing" target="_blank">Upgrade to Pro</a>';
        echo '</div>';
    }
}