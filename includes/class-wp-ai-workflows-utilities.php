<?php

class WP_AI_Workflows_Utilities {

    public function init() {
     
    }

    const LOG_LEVEL_DEBUG = 0;
    const LOG_LEVEL_INFO = 1;
    const LOG_LEVEL_WARNING = 2;
    const LOG_LEVEL_ERROR = 3;

    private static $log_level = self::LOG_LEVEL_INFO; // Default log level

    public static function set_log_level($level) {
        self::$log_level = $level;
    }

    public static function debug_log($message, $type = 'info', $context = array()) {
        if (!WP_AI_WORKFLOWS_DEBUG) {
            return;
        }
    
        $log_file = WP_CONTENT_DIR . '/wp-ai-workflows-lite-debug.log';
        $timestamp = current_time('mysql');
        $context_string = !empty($context) ? wp_json_encode($context) : '';
        $log_entry = "[{$timestamp}] [{$type}] {$message} {$context_string}\n";
    
        error_log($log_entry, 3, $log_file);
    }

    public static function write_log($log_file, $log_entry) {
        $max_size = 5 * 1024 * 1024; // 5MB
    
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;
    
        if ($wp_filesystem->exists($log_file) && $wp_filesystem->size($log_file) > $max_size) {
            $old_content = $wp_filesystem->get_contents($log_file);
            $new_content = substr($old_content, strlen($old_content) / 2) . $log_entry;
            $wp_filesystem->put_contents($log_file, $new_content);
        } else {
            $wp_filesystem->append($log_file, $log_entry);
        }
    }

    private static function get_log_level_from_type($type) {
        switch ($type) {
            case 'debug': return self::LOG_LEVEL_DEBUG;
            case 'info': return self::LOG_LEVEL_INFO;
            case 'warning': return self::LOG_LEVEL_WARNING;
            case 'error': return self::LOG_LEVEL_ERROR;
            default: return self::LOG_LEVEL_INFO;
        }
    }


    public static function debug_function($function_name, $params = array(), $result = null) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : 'Unknown';
        
        $context = array(
            'function' => $function_name,
            'params' => $params,
            'result' => $result,
            'caller' => $caller,
            'memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true)
        );
        
        self::debug_log("Function execution: {$function_name}", 'debug', $context);
    }

    public static function generate_and_encrypt_api_key() {
        self::debug_function(__FUNCTION__);
        
        $api_key = wp_generate_password(32, false);
        $encrypted_key = wp_hash_password($api_key);
        update_option('wp_ai_workflows_encrypted_api_key', $encrypted_key);
        return $api_key; // Return unencrypted for initial use
    }

    public static function get_api_key() {
        self::debug_function(__FUNCTION__);
        
        $encrypted_key = get_option('wp_ai_workflows_encrypted_api_key');
        return $encrypted_key ? '********' . substr($encrypted_key, -4) : '';
    }

    public static function generate_api_key($request) {
        self::debug_function(__FUNCTION__);
        
        $new_key = self::generate_and_encrypt_api_key();
        return new WP_REST_Response(['api_key' => self::get_api_key()], 200);
    }

    public static function get_settings($request) {
        self::debug_function(__FUNCTION__);
        
        try {
            $settings = get_option('wp_ai_workflows_settings', array());
            
            if (isset($settings['openai_api_key'])) {
                $api_key = $settings['openai_api_key'];
                $masked_key = str_repeat('*', max(strlen($api_key) - 4, 0)) . substr($api_key, -4);
                $settings['openai_api_key'] = $masked_key;
            }

            $encrypted_key = get_option('wp_ai_workflows_encrypted_api_key');
            if ($encrypted_key) {
                $settings['wp_ai_workflows_api_key'] = '********' . substr($encrypted_key, -4);
            } else {
                $settings['wp_ai_workflows_api_key'] = '';
            }
            
            $settings['analytics_opt_out'] = get_option('wp_ai_workflows_analytics_opt_out', false);

            return new WP_REST_Response($settings, 200);
        } catch (Exception $e) {
            return new WP_Error('settings_retrieval_error', $e->getMessage(), array('status' => 500));
        }
    }

    public static function update_settings($request) {
        self::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        $settings = $request->get_json_params();
        $current_settings = get_option('wp_ai_workflows_settings', array());

        if (isset($settings['openai_api_key']) && !empty($settings['openai_api_key'])) {
            $current_settings['openai_api_key'] = $settings['openai_api_key'];
            update_option('wp_ai_workflows_settings', $current_settings);
        }

        if (isset($settings['analytics_opt_out'])) {
            update_option('wp_ai_workflows_analytics_opt_out', (bool)$settings['analytics_opt_out']);
        }

        return new WP_REST_Response($current_settings, 200);
    }

    public static function get_gravity_forms_data($request) {
        self::debug_function(__FUNCTION__);
        
        if (!class_exists('GFAPI')) {
            return new WP_Error('gravity_forms_not_active', 'Gravity Forms is not active', array('status' => 404));
        }

        $forms = GFAPI::get_forms();
        $formatted_forms = array();

        foreach ($forms as $form) {
            $formatted_fields = array();
            foreach ($form['fields'] as $field) {
                $formatted_fields[] = array(
                    'id' => $field->id,
                    'label' => $field->label,
                    'type' => $field->type
                );
            }

            $formatted_forms[] = array(
                'id' => $form['id'],
                'title' => $form['title'],
                'fields' => $formatted_fields
            );
        }

        return new WP_REST_Response($formatted_forms, 200);
    }


    public static function call_openai_api($prompt, $model, $imageUrls = []) {
        self::debug_function(__FUNCTION__, ['prompt' => $prompt, 'model' => $model, 'imageUrls' => $imageUrls]);
        
        $api_key = self::get_openai_api_key();
        if (empty($api_key)) {
            return new WP_Error('openai_api_key_missing', 'OpenAI API key is not set');
        }
    
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        );
    
        $messages = [['role' => 'user', 'content' => []]];
    
        
        if (!empty($prompt)) {
            $messages[0]['content'][] = ['type' => 'text', 'text' => $prompt];
        }
    
        
        foreach ($imageUrls as $imageUrl) {
            if (!empty($imageUrl)) {
                $messages[0]['content'][] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $imageUrl]
                ];
            }
        }
    
        $body = array(
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => 1000 // Adjust as needed
        );
    
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 60 // Increased timeout for potential larger payloads
        ));
    
        if (is_wp_error($response)) {
            self::debug_log("OpenAI API call failed", "error", ['error' => $response->get_error_message()]);
            return new WP_Error('openai_api_wp_error', "WP_Error in API call: " . $response->get_error_message());
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if ($response_code !== 200) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
            self::debug_log("OpenAI API error", "error", ['http_code' => $response_code, 'error' => $error_message]);
            return new WP_Error('openai_api_error', "OpenAI API error (HTTP $response_code): $error_message");
        }
    
        if (isset($data['choices'][0]['message']['content'])) {
            self::debug_log("OpenAI API call successful", "info", ['model' => $model, 'image_count' => count($imageUrls)]);
            return $data['choices'][0]['message']['content'];
        } else {
            self::debug_log("Unexpected OpenAI API response", "error", ['response' => $data]);
            return new WP_Error('openai_api_unexpected_response', 'Unexpected OpenAI API response structure');
        }
    }

    private static function get_openai_api_key() {
        self::debug_function(__FUNCTION__);
        
        $cache_key = 'wp_ai_workflows_openai_api_key';
        $api_key = wp_cache_get($cache_key);
        
        if (false === $api_key) {
            $settings = get_option('wp_ai_workflows_settings', array());
            $api_key = isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '';
            wp_cache_set($cache_key, $api_key, '', 3600); // Cache for 1 hour
        }
        
        return $api_key;
    }

    public static function verify_api_key($request) {
        $provided_key = $request->get_param('api_key');
        $encrypted_key = get_option('wp_ai_workflows_encrypted_api_key');
        
        if (wp_check_password($provided_key, $encrypted_key)) {
            return new WP_REST_Response(array('valid' => true), 200);
        } else {
            return new WP_REST_Response(array('valid' => false), 403);
        }
    }

    public static function calculate_delay_time($delay_value, $delay_unit) {
        self::debug_function(__FUNCTION__, ['delay_value' => $delay_value, 'delay_unit' => $delay_unit]);
        
        $now = time(); // Use UTC time

        switch ($delay_unit) {
            case 'minutes':
                $delay_time = $now + ($delay_value * MINUTE_IN_SECONDS);
                break;
            case 'hours':
                $delay_time = $now + ($delay_value * HOUR_IN_SECONDS);
                break;
            case 'days':
                $delay_time = $now + ($delay_value * DAY_IN_SECONDS);
                break;
            default:
                self::debug_log("Invalid delay unit", "error", ['unit' => $delay_unit]);
                return false;
        }

        self::debug_log("Calculated delay time", "debug", [
            'delay_value' => $delay_value,
            'delay_unit' => $delay_unit,
            'delay_time' => gmdate('Y-m-d H:i:s', $delay_time)
        ]);

        return $delay_time;
    }

    public static function update_execution_status($execution_id, $status, $message = '', $node_id = '') {
        self::debug_function(__FUNCTION__, ['execution_id' => $execution_id, 'status' => $status, 'message' => $message, 'node_id' => $node_id]);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        
        $cache_key = 'wp_ai_workflows_execution_' . $execution_id;
        $current_output = wp_cache_get($cache_key);
        
        if (false === $current_output) {
            $current_output = $wpdb->get_var($wpdb->prepare(
                "SELECT output_data FROM {$table_name} WHERE id = %d",
                $execution_id
            ));
            wp_cache_set($cache_key, $current_output);
        }
    
        $current_output = json_decode($current_output, true);
        if (!is_array($current_output)) {
            $current_output = [];
        }
    
        $current_output[] = array(
            'status' => $status,
            'message' => $message,
            'node_id' => $node_id,
            'timestamp' => current_time('mysql')
        );
        
        $updated = $wpdb->update(
            $table_name,
            array(
                'status' => $status,
                'updated_at' => current_time('mysql'),
                'output_data' => wp_json_encode($current_output)
            ),
            array('id' => $execution_id)
        );
    
        if ($updated !== false) {
            wp_cache_delete($cache_key);
        }
    
        self::debug_log("Execution status updated", "debug", array(
            "execution_id" => $execution_id,
            "status" => $status,
            "message" => $message,
            "node_id" => $node_id,
            "update_success" => $updated !== false
        ));
    }
}