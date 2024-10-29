<?php
/**
 * Manages the execution of different node types.
 */
class WP_AI_Workflows_Node_Execution {


    public function init() {
        // Any initialization code if needed
    }

    public static function execute_node($node, $node_data, $edges, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
        
        $node_id = $node['id'];
        $node_type = $node['type'];

        $input_data = self::get_node_input_data($node_id, $edges, $node_data);
        WP_AI_Workflows_Utilities::debug_log("Node input data", "debug", ["node_id" => $node_id, "input_data" => $input_data]);

        if ($node_type === 'trigger' && $node['data']['triggerType'] === 'webhook') {
            $webhook_data = $input_data;
            $webhook_keys = $node['data']['webhookKeys'] ?? [];
            $result = array();
            foreach ($webhook_keys as $key) {
                $value = self::get_nested_value($webhook_data, explode('/', $key['key']));
                $result[$key['key']] = array(
                    'type' => 'webhookInput',
                    'content' => $value
                );
            }
            return $result;
        }

        // Replace input tags in node content before execution
        if (isset($node['data']['content'])) {
            $node['data']['content'] = self::replace_input_tags($node['data']['content'], $input_data);
            WP_AI_Workflows_Utilities::debug_log("Node content after input tag replacement", "debug", ["node_id" => $node_id, "content" => $node['data']['content']]);
        }
        
        $result = null;
        switch ($node_type) {
            case 'trigger':
                $result = self::execute_trigger_node($node, $input_data, $execution_id);
                break;
            case 'aiModel':
                $result = self::execute_ai_model_node($node, $input_data, $execution_id);
                break;
            case 'output':
                $result = self::execute_output_node($node, $input_data, $execution_id);
                break;
            case 'post':
                $result = self::execute_post_node($node, $input_data, $execution_id);
                break;
            default:
                WP_AI_Workflows_Utilities::debug_log("Unsupported node type", "error", ["node_type" => $node_type]);
                $result = self::create_node_data('error', "Unsupported node type: " . $node_type);
        }

        WP_AI_Workflows_Utilities::debug_log("Node execution result", "debug", ["node_id" => $node_id, "result" => $result]);

        return $result;
    }

    private static function get_nested_value($array, $keys) {
        foreach ($keys as $key) {
            if (isset($array[$key])) {
                $array = $array[$key];
            } else {
                return null;
            }
        }
        return $array;
    }

    public static function execute_trigger_node($node, $input_data, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
        
        $triggerType = isset($node['data']['triggerType']) ? $node['data']['triggerType'] : 'manual';
        
        switch ($triggerType) {
            case 'webhook':
                global $initial_webhook_data;
                if (is_array($initial_webhook_data) && isset($initial_webhook_data['output'])) {
                    $outputData = $initial_webhook_data['output'];
                } elseif (is_string($initial_webhook_data)) {
                    $outputData = $initial_webhook_data;
                } else {
                    $outputData = wp_json_encode($initial_webhook_data);
                }
                break;
            case 'gravityForms':
                $outputData = $input_data;
                break;
            case 'manual':
            default:
                $outputData = isset($node['data']['content']) ? $node['data']['content'] : '';
                break;
        }
        
        WP_AI_Workflows_Utilities::debug_log("Trigger node output", "debug", array("triggerType" => $triggerType, "outputData" => $outputData));
        WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Executed trigger node');
        return self::create_node_data('trigger', $outputData);
    }




    public static function execute_ai_model_node($node, $input_data, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
    
        $content = isset($node['data']['content']) ? $node['data']['content'] : "Default prompt";
        $model = isset($node['data']['model']) ? $node['data']['model'] : "gpt-4o-mini";
        $imageUrls = isset($node['data']['imageUrls']) ? $node['data']['imageUrls'] : [];
        
        WP_AI_Workflows_Utilities::debug_log("AI model input", "debug", ["content" => $content, "model" => $model, "imageUrls" => $imageUrls]);
    
        // Replace input tags in the prompt
        $prompt = self::replace_input_tags($content, $input_data);
    
        // Replace input tags in image URLs
        $processedImageUrls = array_map(function($url) use ($input_data) {
            return self::replace_input_tags($url, $input_data);
        }, $imageUrls);
    
        WP_AI_Workflows_Utilities::debug_log("AI model prompt after replacement", "debug", ["prompt" => $prompt, "processedImageUrls" => $processedImageUrls]);
    
        $response = WP_AI_Workflows_Utilities::call_openai_api($prompt, $model, $processedImageUrls);
    
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            WP_AI_Workflows_Utilities::debug_log("Error in AI model response", "error", ["error_message" => $error_message]);
            return self::create_node_data('error', "Error: " . $error_message);
        }
        
        // Process the response
        $processed_response = self::process_ai_response($response);
        
        WP_AI_Workflows_Utilities::debug_log("AI model response", "debug", ["response" => $processed_response]);
    
        WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Executed AI model node');
        return self::create_node_data('aiModel', $processed_response);
    }

    public static function execute_output_node($node, $input_data, $execution_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
        
        WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Starting output node execution');

        global $wpdb;
        
        $output_content = '';
        foreach ($input_data as $input_node) {
            if (isset($input_node['content'])) {
                $output_content .= $input_node['content'] . "\n\n";
            }
        }
        
        $output_content = trim($output_content);
        
        $output_type = isset($node['data']['outputType']) ? $node['data']['outputType'] : 'display';
        
        $result = array(
            'type' => 'output',
            'content' => $output_content,
            'status' => 'success',
            'message' => ''
        );

        // Check if delay is enabled
        if (isset($node['data']['delayEnabled']) && $node['data']['delayEnabled']) {
            $delay_time = WP_AI_Workflows_Utilities::calculate_delay_time($node['data']['delayValue'], $node['data']['delayUnit']);
            
            if ($delay_time === false) {
                WP_AI_Workflows_Utilities::debug_log("Failed to calculate delay time", "error", [
                    'node_id' => $node['id'],
                    'delay_value' => $node['data']['delayValue'],
                    'delay_unit' => $node['data']['delayUnit']
                ]);
                WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'error', 'Failed to schedule delayed output');
                return self::create_node_data('error', "Failed to schedule delayed output due to invalid delay settings");
            }
            
            // Schedule the output execution
            wp_schedule_single_event($delay_time, 'wp_ai_workflows_execute_delayed_output', [
                'node' => $node,
                'output_content' => $output_content,
                'execution_id' => $execution_id
            ]);

            WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'scheduled', "Output scheduled for execution at: " . get_date_from_gmt(gmdate('Y-m-d H:i:s', $delay_time), 'Y-m-d H:i:s'));
            return self::create_node_data('output', "Output scheduled for execution at: " . get_date_from_gmt(gmdate('Y-m-d H:i:s', $delay_time), 'Y-m-d H:i:s'));
        }

        // If no delay, continue with immediate execution
        switch ($output_type) {
            case 'save':
                break;
            
                
            case 'webhook':
                $webhook_url = isset($node['data']['webhookUrl']) ? $node['data']['webhookUrl'] : '';
                if (!empty($webhook_url)) {
                    $response = wp_remote_post($webhook_url, array(
                        'body' => wp_json_encode(array('output' => $output_content)),
                        'headers' => array('Content-Type' => 'application/json'),
                        'timeout' => 15
                    ));
                    
                    if (is_wp_error($response)) {
                        $result['status'] = 'error';
                        $result['message'] = 'Webhook request failed: ' . $response->get_error_message();
                        WP_AI_Workflows_Utilities::debug_log("Webhook error: " . $response->get_error_message(), "error");
                    } else {
                        $response_code = wp_remote_retrieve_response_code($response);
                        if ($response_code < 200 || $response_code >= 300) {
                            $result['status'] = 'warning';
                            $result['message'] = "Webhook request received non-200 response: $response_code";
                            WP_AI_Workflows_Utilities::debug_log("Webhook non-200 response: $response_code", "warning");
                        }
                    }
                } else {
                    $result['status'] = 'error';
                    $result['message'] = 'Webhook URL is empty';
                    WP_AI_Workflows_Utilities::debug_log("Webhook URL is empty", "warning");
                }
                break;

            case 'html':
                // No additional processing needed for HTML output
                break;

            case 'display':
                // No additional processing needed for display output
                break;

            default:
                $result['status'] = 'error';
                $result['message'] = 'Invalid output type';
                WP_AI_Workflows_Utilities::debug_log("Invalid output type: $output_type", "error");
                break;
        }
        
        // Save output regardless of the output type
        $saved_outputs = get_option('wp_ai_workflows_outputs', array());
        $saved_outputs[$node['id']] = array(
            'data' => $output_content,
            'timestamp' => current_time('mysql'),
            'status' => $result['status'],
            'message' => $result['message']
            );
            update_option('wp_ai_workflows_outputs', $saved_outputs);
            WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Output node execution completed');
            return $result;
            }

            public static function execute_post_node($node, $input_data, $execution_id) {
                WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node' => $node, 'execution_id' => $execution_id]);
            
                $post_data = [
                    'post_type' => isset($node['data']['selectedPostType']) ? $node['data']['selectedPostType'] : 'post',
                    'post_status' => isset($node['data']['postStatus']) ? $node['data']['postStatus'] : 'publish',
                ];
            
                $acf_fields = [];
            
                // Handle field mappings
                if (isset($node['data']['fieldMappings'])) {
                    foreach ($node['data']['fieldMappings'] as $field => $value) {
                        $replaced_value = self::replace_input_tags($value, $input_data);
                        if (strpos($field, 'acf_') === 0) {
                            $acf_fields[substr($field, 4)] = $replaced_value;
                        } else {
                            $post_data[$field] = $replaced_value;
                        }
                    }
                }
            
                // If no title is set, use a default title
                if (!isset($post_data['post_title'])) {
                    $post_data['post_title'] = 'Auto-generated post ' . current_time('mysql');
                }
            
                // If no content is set, use the input from the previous nodes
                if (!isset($post_data['post_content'])) {
                    $post_data['post_content'] = '';
                    foreach ($input_data as $input) {
                        if (isset($input['content'])) {
                            $post_data['post_content'] .= $input['content'] . "\n\n";
                        }
                    }
                    $post_data['post_content'] = trim($post_data['post_content']);
                }
            
                // Handle scheduled posts
                if ($post_data['post_status'] === 'future') {
                    if (isset($node['data']['scheduledDate'])) {
                        $post_data['post_date'] = $node['data']['scheduledDate'];
                        $post_data['post_date_gmt'] = get_gmt_from_date($node['data']['scheduledDate']);
                    } else {
                        // If no date is set, default to publishing immediately
                        $post_data['post_status'] = 'publish';
                    }
                }
            
                WP_AI_Workflows_Utilities::debug_log("Post data prepared", "debug", ["post_data" => $post_data, "acf_fields" => $acf_fields]);
            
                $post_id = wp_insert_post($post_data);
            
                if (is_wp_error($post_id)) {
                    WP_AI_Workflows_Utilities::debug_log("Error in post node execution", "error", ["error" => $post_id->get_error_message()]);
                    return self::create_node_data('error', $post_id->get_error_message());
                }
            
                // Handle ACF fields
                if (!empty($acf_fields) && function_exists('update_field')) {
                    foreach ($acf_fields as $field_name => $field_value) {
                        update_field($field_name, $field_value, $post_id);
                    }
                    WP_AI_Workflows_Utilities::debug_log("ACF fields updated", "debug", ["acf_fields" => $acf_fields]);
                }
            
                $result = "Post created with ID: {$post_id}";
                WP_AI_Workflows_Utilities::debug_log("Post node execution result", "debug", ["result" => $result]);
                WP_AI_Workflows_Utilities::update_execution_status($execution_id, 'processing', 'Executed Post node');
                return self::create_node_data('post', $result);
            }

            public static function get_post_types() {
                WP_AI_Workflows_Utilities::debug_log("Fetching post types", "debug");
                $post_types = get_post_types(array('public' => true), 'objects');
                $formatted_types = array();
                foreach ($post_types as $post_type) {
                    $formatted_types[] = array(
                        'name' => $post_type->name,
                        'label' => $post_type->label
                    );
                }
                WP_AI_Workflows_Utilities::debug_log("Post types fetched", "debug", ['post_types' => $formatted_types]);
                return new WP_REST_Response($formatted_types, 200);
            }
            
            public static function get_post_fields($request) {
                $post_type = $request->get_param('post_type');
                $post_type_object = get_post_type_object($post_type);
                
                if (!$post_type_object) {
                    return new WP_Error('invalid_post_type', 'Invalid post type', array('status' => 400));
                }
            
                $fields = array();
            
                // Add default WordPress fields
                $default_fields = array(
                    'post_title' => 'Title',
                    'post_content' => 'Content',
                    'post_excerpt' => 'Excerpt'
                );
            
                foreach ($default_fields as $name => $label) {
                    $fields[] = array('name' => $name, 'label' => $label);
                }
            
                // Get registered meta keys
                $registered_meta = get_registered_meta_keys($post_type);
                foreach ($registered_meta as $meta_key => $meta_args) {
                    $fields[] = array('name' => $meta_key, 'label' => ucfirst(str_replace('_', ' ', $meta_key)));
                }
            
                // Check for WooCommerce product fields
                if ($post_type === 'product' && class_exists('WC_Product')) {
                    $wc_fields = array(
                        '_regular_price' => 'Regular Price',
                        '_sale_price' => 'Sale Price',
                        '_sku' => 'SKU',
                        '_stock' => 'Stock Quantity',
                        // Add more WooCommerce fields as needed
                    );
                    foreach ($wc_fields as $name => $label) {
                        $fields[] = array('name' => $name, 'label' => $label);
                    }
                }
            
                // Check for ACF fields
                if (function_exists('acf_get_field_groups')) {
                    $field_groups = acf_get_field_groups(array('post_type' => $post_type));
                    foreach ($field_groups as $field_group) {
                        $acf_fields = acf_get_fields($field_group);
                        foreach ($acf_fields as $field) {
                            $fields[] = array('name' => 'acf_' . $field['name'], 'label' => $field['label'] . ' (ACF)');
                        }
                    }
                }
            
                // Allow other plugins to add their custom fields
                $fields = apply_filters('wp_ai_workflows_post_fields', $fields, $post_type);
            
                // Remove any duplicate fields
                $unique_fields = array_unique($fields, SORT_REGULAR);
                return new WP_REST_Response($unique_fields, 200);
            }

            

            


// Helper methods

private static function get_node_input_data($node_id, $edges, $node_data) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node_id' => $node_id]);
    
    $input_data = array();
    foreach ($edges as $edge) {
        if ($edge['target'] == $node_id) {
            $source_id = $edge['source'];
            if (isset($node_data[$source_id])) {
                $input_data[$source_id] = $node_data[$source_id];
            }
        }
    }
    return $input_data;
}

private static function replace_input_tags($content, $input_data) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['content' => $content, 'input_data' => $input_data]);
    
    // Handle input from any node type using node ID
    $content = preg_replace_callback('/\[Input from ([\w-]+)\]/', function($matches) use ($input_data) {
        $node_id = $matches[1];
        if (isset($input_data[$node_id])) {
            if ($input_data[$node_id]['type'] === 'condition') {
                // For condition nodes, use the input data from the previous node
                $prev_node_data = reset($input_data[$node_id]['input_data']);
                return is_array($prev_node_data['content']) 
                    ? wp_json_encode($prev_node_data['content']) 
                    : strval($prev_node_data['content']);
            } else {
                return is_array($input_data[$node_id]['content']) 
                    ? wp_json_encode($input_data[$node_id]['content']) 
                    : strval($input_data[$node_id]['content']);
            }
        }
        return $matches[0];
    }, $content);

    // Handle specific fields from any node type using node ID
    $content = preg_replace_callback('/\[\[([^\]]+)\] from ([\w-]+)\]/', function($matches) use ($input_data) {
        $field_name = $matches[1];
        $node_id = $matches[2];
        if (isset($input_data[$node_id])) {
            if ($input_data[$node_id]['type'] === 'condition') {
                // For condition nodes, use the input data from the previous node
                $prev_node_data = reset($input_data[$node_id]['input_data']);
                $node_content = $prev_node_data['content'];
            } else {
                $node_content = $input_data[$node_id]['content'];
            }
            
            if (is_array($node_content)) {
                $field_value = $node_content[$field_name] ?? $matches[0];
                if (is_array($field_value)) {
                    return implode(' ', array_filter($field_value));
                }
                return $field_value;
            }
        }
        return $matches[0];
    }, $content);

    WP_AI_Workflows_Utilities::debug_log("Final content after replacements", "debug", ["replaced_content" => $content]);
    return $content;
}

private static function create_node_data($type, $content) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['type' => $type, 'content' => $content]);
    
    return [
        'type' => $type,
        'content' => $content,
    ];
}

private static function process_ai_response($response) {
    // Remove any existing <br> tags
    $response = str_replace('<br>', "\n", $response);
    $response = str_replace('<br />', "\n", $response);

    // Convert Markdown to HTML
    $response = self::markdown_to_html($response);

    // Ensure proper spacing for list items
    $response = preg_replace('/<\/li><li>/', "</li>\n<li>", $response);

    return $response;
}

private static function markdown_to_html($text) {
    // Convert Markdown-style bold to HTML
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    
    // Convert Markdown-style lists to HTML
    $text = preg_replace('/^\s*-\s+/m', '<li>', $text);
    $text = preg_replace('/(<li>.*?)(\n|$)/s', '$1</li>$2', $text);
    $text = preg_replace('/((?:<li>.*?<\/li>\s*)+)/', '<ul>$1</ul>', $text);

    // Convert newlines to <br> tags, but not within list items
    $text = preg_replace('/(?<!>)\n(?!<)/', '<br>', $text);

    return $text;
}
}