<?php
/**
 * Manages all REST API endpoints for the plugin.
 */
class WP_AI_Workflows_REST_API {

    public function init() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function register_rest_routes() {
        // Workflows
        register_rest_route('wp-ai-workflows/v1', '/workflows', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_workflows'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows/(?P<id>[\w-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows/(?P<id>[\w-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/workflows/(?P<id>[\w-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_single_workflow'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Workflow Execution
        register_rest_route('wp-ai-workflows/v1', '/execute-workflow/(?P<id>[\w-]+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'execute_workflow_endpoint'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/executions', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_executions'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/executions/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_execution'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/executions/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'stop_and_delete_execution'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Settings
        register_rest_route('wp-ai-workflows/v1', '/settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_settings'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/settings', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_settings'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Webhooks
        register_rest_route('wp-ai-workflows/v1', '/webhook/(?P<node_id>[\w-]+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook_trigger'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('wp-ai-workflows/v1', '/generate-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_webhook_url'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/sample-webhook/(?P<id>[\w-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'sample_webhook'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Post
        register_rest_route('wp-ai-workflows/v1', '/post-types', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_types'),
            'permission_callback' => array($this, 'authorize_request')
        ));
        
        register_rest_route('wp-ai-workflows/v1', '/post-fields/(?P<post_type>[\w-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_post_fields'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/execute-post-node', array(
            'methods' => 'POST',
            'callback' => array($this, 'execute_post_node'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        // Outputs
        register_rest_route('wp-ai-workflows/v1', '/save-output', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_output'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/outputs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_outputs'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/latest-output', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_latest_output'),
            'permission_callback' => array($this, 'authorize_request')
        ));

        register_rest_route('wp-ai-workflows/v1', '/shortcode-output', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_shortcode_output'),
            'permission_callback' => '__return_true'
        ));

            // Gravity Forms
        register_rest_route('wp-ai-workflows/v1', '/gravity-forms', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_gravity_forms_data'),
            'permission_callback' => array($this, 'authorize_request')
        ));

    }

    public function authorize_request($request) {
        if (current_user_can('manage_options')) {
            return true;
        }
    
        $provided_key = $request->get_header('X-Api-Key');
        $encrypted_key = get_option('wp_ai_workflows_encrypted_api_key');
        
        if ($provided_key && wp_check_password($provided_key, $encrypted_key)) {
            return true;
        }
    
        WP_AI_Workflows_Utilities::debug_log("Authorization failed", "error", [
            'route' => $request->get_route(),
            'method' => $request->get_method()
        ]);
        return new WP_Error('rest_forbidden', 'Unauthorized access', array('status' => 401));
    }

    public function get_workflows($request) {
        return WP_AI_Workflows_Workflow::get_workflows($request);
    }

    public function create_workflow($request) {
        return WP_AI_Workflows_Workflow::create_workflow($request);
    }

    public function update_workflow($request) {
        return WP_AI_Workflows_Workflow::update_workflow($request);
    }

    public function get_gravity_forms_data($request) {
        return WP_AI_Workflows_Utilities::get_gravity_forms_data($request);
    }

    public function delete_workflow($request) {
        return WP_AI_Workflows_Workflow::delete_workflow($request);
    }

    public function get_single_workflow($request) {
        return WP_AI_Workflows_Workflow::get_single_workflow($request);
    }

    public function execute_workflow_endpoint($request) {
        return WP_AI_Workflows_Workflow::execute_workflow_endpoint($request);
    }

    public function get_executions($request) {
        return WP_AI_Workflows_Workflow::get_executions($request);
    }

    public function get_execution($request) {
        return WP_AI_Workflows_Workflow::get_execution($request);
    }

    public function stop_and_delete_execution($request) {
        return WP_AI_Workflows_Workflow::stop_and_delete_execution($request);
    }

    public function handle_webhook_trigger($request) {
        return WP_AI_Workflows_Workflow::handle_webhook_trigger($request);
    }

    public function generate_webhook_url($request) {
        return WP_AI_Workflows_Workflow::generate_webhook_url($request);
    }

    public function save_output($request) {
        return WP_AI_Workflows_Workflow::save_output($request);
    }

    public function get_outputs($request) {
        return WP_AI_Workflows_Workflow::get_outputs($request);
    }

    public function get_latest_output($request) {
        return WP_AI_Workflows_Workflow::get_latest_output($request);
    }

    public function get_shortcode_output($request) {
        return WP_AI_Workflows_Shortcode::get_shortcode_output($request);
    }

    public function get_post_types($request) {
        return WP_AI_Workflows_Node_Execution::get_post_types($request);
    }

    public function get_post_fields($request) {
        return WP_AI_Workflows_Node_Execution::get_post_fields($request);
    }

    public function execute_post_node($request) {
        return WP_AI_Workflows_Node_Execution::execute_post_node($request);
    }

    public function get_settings($request) {
        $settings = array(
            'openai_api_key' => get_option('wp_ai_workflows_openai_api_key', ''),
            'ai_workflow_api_key' => get_option('wp_ai_workflows_api_key', '')
        );
        
        return new WP_REST_Response($settings, 200);
    }

    public function update_settings($request) {
        $response = WP_AI_Workflows_Utilities::update_settings($request);
        $openai_api_key = $request->get_param('openai_api_key');
    
        if ($openai_api_key) {
            update_option('wp_ai_workflows_openai_api_key', $openai_api_key);
        }
    
        return $this->get_settings($request);
    }

    public function sample_webhook($request) {
        $node_id = $request['id'];
        $timeout = 60; // 60 seconds timeout
        $start_time = time();
    
        while (time() - $start_time < $timeout) {
            $webhook_data = get_transient('wp_ai_workflows_webhook_sample_' . $node_id);
            if ($webhook_data) {
                delete_transient('wp_ai_workflows_webhook_sample_' . $node_id);
                return new WP_REST_Response(array('keys' => $this->parse_webhook_keys($webhook_data)), 200);
            }
            sleep(1);
        }
    
        return new WP_REST_Response(array('message' => 'No webhook data received within the timeout period'), 404);
    }
    
    private function parse_webhook_keys($data, $prefix = '') {
        $keys = array();
        foreach ($data as $key => $value) {
            $full_key = $prefix ? $prefix . '/' . $key : $key;
            if (is_array($value) || is_object($value)) {
                $keys = array_merge($keys, $this->parse_webhook_keys($value, $full_key));
            } else {
                $keys[] = array(
                    'key' => $full_key,
                    'type' => $this->get_value_type($value)
                );
            }
        }
        return $keys;
    }
    
    private function get_value_type($value) {
        if (is_numeric($value)) return 'number';
        if (is_bool($value)) return 'boolean';
        return 'string';
    }
}