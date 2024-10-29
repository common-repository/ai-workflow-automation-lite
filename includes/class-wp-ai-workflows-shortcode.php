<?php

class WP_AI_Workflows_Shortcode {

    public function init() {
        add_shortcode('wp_ai_workflows_output', array($this, 'output_shortcode'));
    }

    public function output_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
        ), $atts, 'wp_ai_workflows_output');
    
        $workflow_id = $atts['id'];
        
        if (!isset($_COOKIE['wp_ai_workflows_session_id'])) {
            $session_id = wp_generate_uuid4();
            setcookie('wp_ai_workflows_session_id', $session_id, time() + (86400 * 30), "/");
        } else {
            $session_id = sanitize_text_field(wp_unslash($_COOKIE['wp_ai_workflows_session_id']));
        }
    
        wp_enqueue_script('wp-ai-workflows-shortcode', plugin_dir_url(dirname(__FILE__)) . 'assets/js/shortcode-output.js', array(), WP_AI_WORKFLOWS_VERSION, true);
        wp_localize_script('wp-ai-workflows-shortcode', 'wpAiWorkflowsShortcode', array(
            'workflowId' => $workflow_id,
            'sessionId' => $session_id,
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_ai_workflows_shortcode_nonce'),
            'apiRoot' => esc_url_raw(rest_url())
        ));
    
        return '<div id="wp-ai-workflows-output-' . esc_attr($workflow_id) . '" data-workflow-id="' . esc_attr($workflow_id) . '"></div>';
    }


    public static function get_shortcode_output($request) {
        $workflow_id = sanitize_text_field($request->get_param('workflow_id'));
        $session_id = sanitize_text_field($request->get_param('session_id'));
        
        if (empty($workflow_id) || empty($session_id)) {
            return new WP_Error('missing_parameters', 'Workflow ID and Session ID are required', array('status' => 400));
        }
        
        $workflow_id = $request->get_param('workflow_id');
        $session_id = $request->get_param('session_id');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';
        
        $output = $wpdb->get_var($wpdb->prepare(
            "SELECT output_data FROM {$table_name} WHERE workflow_id = %s AND session_id = %s ORDER BY updated_at DESC LIMIT 1",
            $workflow_id,
            $session_id
        ));
        
        $debug_info = [
            'workflow_id' => $workflow_id,
            'session_id' => $session_id,
            'query' => $wpdb->last_query,
            'output' => $output,
        ];
    
        return new WP_REST_Response([
            'output' => $output,
            'debug' => $debug_info
        ], 200);
    }
}