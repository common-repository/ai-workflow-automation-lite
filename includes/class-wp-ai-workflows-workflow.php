<?php
/**
 * Handles workflow-related operations.
 */
class WP_AI_Workflows_Workflow {

    public function init() {
        add_action('wp_ai_workflows_execute_scheduled_workflow', array($this, 'execute_scheduled_workflow'), 10, 1);
        add_action('wp_ai_workflows_execute_webhook_workflow', array($this, 'execute_webhook_workflow'), 10, 2);
        add_action('gform_after_submission', array($this, 'handle_gravity_forms_submission'), 10, 2);
    }

    public static function get_workflows($request) {
        WP_AI_Workflows_Utilities::debug_log("Attempting to fetch workflows", "debug", [
            'user_id' => get_current_user_id(),
            'user_roles' => wp_get_current_user()->roles
        ]);
        
        $workflows = get_option('wp_ai_workflows', array());
        $workflow_count = count($workflows);
        $workflow_limit = self::get_workflow_limit();
        
        WP_AI_Workflows_Utilities::debug_log("Fetched workflows", "debug", [
            'count' => $workflow_count,
            'limit' => $workflow_limit
        ]);
        
        return new WP_REST_Response([
            'workflows' => $workflows,
            'count' => $workflow_count,
            'limit' => $workflow_limit
        ], 200);
    }

    public static function create_workflow($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_json_params()]);
        
        $workflows = get_option('wp_ai_workflows', array());
        
        if (count($workflows) >= self::get_workflow_limit()) {
            return new WP_Error('workflow_limit_reached', 'You have reached the maximum number of workflows (3) in the Lite version. Upgrade to Pro for unlimited workflows.', array('status' => 403));
        }
        
        $new_workflow = $request->get_json_params();
        $new_workflow['id'] = uniqid();
        $new_workflow['createdBy'] = wp_get_current_user()->user_login;
        $new_workflow['createdAt'] = current_time('mysql');
        $new_workflow['status'] = 'active';
        $workflows[] = $new_workflow;
        update_option('wp_ai_workflows', $workflows);
        return new WP_REST_Response($new_workflow, 201);
    }

    public static function update_workflow($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        global $wpdb;
        $workflow_id = $request['id'];
        $updated_workflow = $request->get_json_params();
        
        $workflows = get_option('wp_ai_workflows', array());
        $workflow_index = array_search($workflow_id, array_column($workflows, 'id'));
        
        if ($workflow_index !== false) {
            // Explicitly handle the columns array
            if (isset($updated_workflow['nodes'])) {
                foreach ($updated_workflow['nodes'] as &$node) {
                    if ($node['type'] === 'output' && isset($node['data']['columns'])) {
                        // Ensure columns array is properly saved
                        $node['data']['columns'] = array_map(function($column) {
                            return [
                                'name' => $column['name'],
                                'type' => $column['type'],
                                'mapping' => $column['mapping'] ?? ''
                            ];
                        }, $node['data']['columns']);
                    }
                }
            }

            $workflows[$workflow_index] = array_merge($workflows[$workflow_index], $updated_workflow);
            $workflows[$workflow_index]['updatedAt'] = current_time('mysql');

            // Handle scheduling
            if (isset($updated_workflow['schedule'])) {
                $schedule = $updated_workflow['schedule'];
                $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
                
                WP_AI_Workflows_Utilities::debug_log("Updating workflow schedule", "debug", [
                    'workflow_id' => $workflow_id,
                    'schedule' => $schedule
                ]);

                // Clear any existing scheduled events for this workflow
                self::clear_scheduled_events($workflow_id);

                if ($schedule['enabled']) {
                    $next_run = self::calculate_next_run($schedule);
                    if ($next_run === false) {
                        WP_AI_Workflows_Utilities::debug_log("Invalid schedule data", "error", $schedule);
                        return new WP_Error('invalid_schedule', 'Invalid schedule data', array('status' => 400));
                    }
                
                    wp_schedule_single_event($next_run, 'wp_ai_workflows_execute_scheduled_workflow', array($workflow_id));
                    $wpdb->insert(
                        $table_name,
                        array(
                            'workflow_id' => $workflow_id,
                            'workflow_name' => $workflows[$workflow_index]['name'],
                            'status' => 'scheduled',
                            'scheduled_at' => gmdate('Y-m-d H:i:s', $next_run), // Store in UTC
                            'created_at' => gmdate('Y-m-d H:i:s'),
                            'updated_at' => gmdate('Y-m-d H:i:s')
                        ),
                        array('%s', '%s', '%s', '%s', '%s', '%s')
                    );
                    
                    WP_AI_Workflows_Utilities::debug_log("Workflow scheduled", "debug", [
                        'workflow_id' => $workflow_id,
                        'next_run_utc' => gmdate('Y-m-d H:i:s', $next_run),
                        'next_run_wp' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s')
                    ]);
    
                    if ($wpdb->last_error) {
                        WP_AI_Workflows_Utilities::debug_log("Database error while scheduling workflow", "error", [
                            'workflow_id' => $workflow_id,
                            'error' => $wpdb->last_error
                        ]);
                        return new WP_Error('db_error', 'Failed to schedule workflow', array('status' => 500));
                    }
                } else {
                    // Remove the scheduled workflow from the database
                    $wpdb->delete(
                        $table_name,
                        array('workflow_id' => $workflow_id, 'status' => 'scheduled'),
                        array('%s', '%s')
                    );
                    
                    WP_AI_Workflows_Utilities::debug_log("Cancelled scheduled workflow", "debug", [
                        'workflow_id' => $workflow_id,
                        'rows_affected' => $wpdb->rows_affected
                    ]);
                }
                $workflows[$workflow_index]['schedule'] = $schedule;
            }
    
            $workflows[$workflow_index] = array_merge($workflows[$workflow_index], $updated_workflow);
            $workflows[$workflow_index]['updatedAt'] = current_time('mysql');
            
            WP_AI_Workflows_Utilities::debug_log("Updating workflow option", "debug", [
                'workflow_id' => $workflow_id,
                'updated_workflow' => $workflows[$workflow_index]
            ]);
    
            $update_result = update_option('wp_ai_workflows', $workflows);
            
            if ($update_result === false) {
                WP_AI_Workflows_Utilities::debug_log("Failed to update workflow option", "error", [
                    'workflow_id' => $workflow_id
                ]);
                return new WP_Error('update_failed', 'Failed to update workflow', array('status' => 500));
            }
    
            WP_AI_Workflows_Utilities::debug_log("Workflow updated successfully", "debug", [
                'workflow_id' => $workflow_id
            ]);
    
            return new WP_REST_Response($workflows[$workflow_index], 200);
        }
        
        return new WP_REST_Response(array('message' => 'Workflow not found'), 404);
    }
    
    private static function get_workflow_limit() {
        $base = 6;
        $modifier = 1;
        return intval(($base / 2) + $modifier);
    }

    public static function delete_workflow($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        global $wpdb;
        $workflow_id = $request['id'];
        
        $workflows = get_option('wp_ai_workflows', array());
        $workflow_index = array_search($workflow_id, array_column($workflows, 'id'));
        
        if ($workflow_index !== false) {
            // Remove the workflow from the array
            array_splice($workflows, $workflow_index, 1);
            update_option('wp_ai_workflows', $workflows);
    
            // Clear any scheduled events for this workflow
            wp_clear_scheduled_hook('wp_ai_workflows_execute_scheduled', array($workflow_id));
    
            // Remove any executions for this workflow from the database
            $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
            $deleted = $wpdb->delete(
                $table_name,
                array('workflow_id' => $workflow_id),
                array('%s')
            );
    
            WP_AI_Workflows_Utilities::debug_log("Workflow deleted", "info", [
                'workflow_id' => $workflow_id,
                'executions_deleted' => $deleted
            ]);
    
            return new WP_REST_Response(null, 204);
        }
        
        return new WP_REST_Response(array('message' => 'Workflow not found'), 404);
    }
    
    public static function get_single_workflow($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        $id = $request['id'];
        
        if (empty($id)) {
            return new WP_REST_Response(array('message' => 'Workflow ID is required'), 400);
        }
        
        $workflows = get_option('wp_ai_workflows', array());
        
        foreach ($workflows as $workflow) {
            if ($workflow['id'] == $id) {
                // Ensure columns array is properly formatted for each output node
                if (isset($workflow['nodes'])) {
                    foreach ($workflow['nodes'] as &$node) {
                        if ($node['type'] === 'output' && isset($node['data']['columns'])) {
                            $node['data']['columns'] = array_map(function($column) {
                                return [
                                    'name' => $column['name'] ?? '',
                                    'type' => $column['type'] ?? '',
                                    'mapping' => $column['mapping'] ?? ''
                                ];
                            }, $node['data']['columns']);
                        }
                    }
                }
                return new WP_REST_Response($workflow, 200);
            }
        }
        
        return new WP_REST_Response(array('message' => 'Workflow not found'), 404);
    }
    
    public static function execute_workflow_endpoint($request) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);
        
        $workflow_id = $request['id'];
        $params = $request->get_json_params();
        $session_id = $request->get_header('X-Session-ID');
        
        $workflows = get_option('wp_ai_workflows', array());
        $target_workflow = null;
    
        foreach ($workflows as $workflow) {
            if ($workflow['id'] === $workflow_id) {
                $target_workflow = $workflow;
                break;
            }
        }
    
        if (!$target_workflow) {
            return new WP_Error('invalid_workflow', 'Invalid workflow ID', array('status' => 400));
        }
    
        $trigger_data = null;
        if (isset($params['formData'])) {
            $trigger_node = self::find_trigger_node($target_workflow['nodes']);
            if ($trigger_node && $trigger_node['data']['triggerType'] === 'gravityForms') {
                $trigger_data = self::format_gravity_form_data($params['formData'], $trigger_node['data']['selectedFields']);
            }
        } else {
            $trigger_node = self::find_trigger_node($target_workflow['nodes']);
            if ($trigger_node && $trigger_node['data']['triggerType'] === 'manual') {
                $trigger_data = $trigger_node['data']['content'];
            }
        }
    
        $result = self::execute_workflow($workflow_id, $trigger_data, null, $session_id);
    
        if (is_wp_error($result)) {
            return new WP_Error('workflow_execution_error', $result->get_error_message(), array('status' => 500));
        }
    
        $response_data = array(
            'nodes' => array_map(function($node_id, $node_data) {
                return array(
                    'id' => $node_id,
                    'data' => $node_data
                );
            }, array_keys($result), $result)
        );
    
        return new WP_REST_Response($response_data, 200);
    }
    
    public static function execute_workflow($workflow_id, $initial_data = null, $execution_id = null, $session_id = null) {
        WP_AI_Workflows_Utilities::debug_log("Starting workflow execution", "info", [
            "execution_id" => $execution_id,
            "workflow_id" => $workflow_id
        ]);
        
        global $wpdb;
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
        $shortcode_outputs_table = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';
        
        // If no execution_id is provided, create a new execution record
        if ($execution_id === null) {
            $wpdb->insert(
                $executions_table,
                array(
                    'workflow_id' => $workflow_id,
                    'workflow_name' => self::get_workflow_name($workflow_id),
                    'status' => 'processing',
                    'input_data' => wp_json_encode($initial_data),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                )
            );
            $execution_id = $wpdb->insert_id;
        }
    
        WP_AI_Workflows_Utilities::debug_log("Starting workflow execution", "debug", [
            "execution_id" => $execution_id,
            "workflow_id" => $workflow_id,
            "initial_data" => $initial_data
        ]);
    
        global $initial_webhook_data;
        $initial_webhook_data = $initial_data;
       
        $workflows = get_option('wp_ai_workflows', array());
        $workflow = null;
        foreach ($workflows as &$wf) {
            if ($wf['id'] === $workflow_id) {
                $workflow = &$wf;
                break;
            }
        }
    
        if (!$workflow) {
            WP_AI_Workflows_Utilities::debug_log("Workflow not found", "error", ["workflow_id" => $workflow_id]);
            return new WP_Error('workflow_not_found', 'Workflow not found');
        }
    
        if ($workflow['status'] !== 'active') {
            WP_AI_Workflows_Utilities::debug_log("Workflow is inactive", "info", ["workflow_id" => $workflow_id]);
            return new WP_Error('workflow_inactive', 'Workflow is inactive');
        }
    
        $nodes = $workflow['nodes'];
        $edges = $workflow['edges'];
        
        WP_AI_Workflows_Utilities::debug_log("Workflow structure", "debug", ["nodes" => $nodes, "edges" => $edges]);
    
        $sorted_nodes = self::topological_sort($nodes, $edges);
    
        $node_data = array();
        $condition_results = array();
        $nodes_to_skip = array();
    
        foreach ($sorted_nodes as $node) {
            $node_id = $node['id'];
            $node_type = $node['type'];
            
            if (in_array($node_id, $nodes_to_skip)) {
                WP_AI_Workflows_Utilities::debug_log("Skipping node", "debug", ["node_id" => $node_id, "node_type" => $node_type]);
                continue;
            }
    
            WP_AI_Workflows_Utilities::debug_log("Executing node", "debug", ["node_id" => $node_id, "node_type" => $node_type]);
    

            if ($node_type === 'trigger') {
                $result = WP_AI_Workflows_Node_Execution::execute_trigger_node($node, $initial_data, $execution_id);
            } else {
                $result = WP_AI_Workflows_Node_Execution::execute_node($node, $node_data, $edges, $execution_id);
            }
        
    
            if ($result !== null) {
                $node_data[$node_id] = $result;
                
                if ($node_type === 'condition') {
                    $condition_results[$node_id] = $result['content'];
                    $true_path = self::get_downstream_nodes($node_id, $edges, 'true');
                    $false_path = self::get_downstream_nodes($node_id, $edges, 'false');
    
                    WP_AI_Workflows_Utilities::debug_log("Condition node paths", "debug", [
                        "node_id" => $node_id,
                        "condition_result" => $result['content'],
                        "true_path" => $true_path,
                        "false_path" => $false_path
                    ]);
    
                    if ($result['content']) {
                        $nodes_to_skip = array_merge($nodes_to_skip, $false_path);
                    } else {
                        $nodes_to_skip = array_merge($nodes_to_skip, $true_path);
                    }
    
                    WP_AI_Workflows_Utilities::debug_log("Updated nodes to skip", "debug", ["nodes_to_skip" => $nodes_to_skip]);
                }
            }
    
            WP_AI_Workflows_Utilities::debug_log("Node execution result", "debug", ["node_id" => $node_id, "result" => $result]);
        }
    
        // Update the workflow with the results and last execution time
        foreach ($workflow['nodes'] as &$node) {
            if (isset($node_data[$node['id']])) {
                $node['data']['output'] = $node_data[$node['id']]['content'];
                $node['data']['executed'] = true;
            } else {
                $node['data']['executed'] = false;
            }
        }
        $workflow['lastExecuted'] = current_time('mysql');
    
        update_option('wp_ai_workflows', $workflows);
    
        $final_result = wp_json_encode($node_data);
        // Update the execution record with the results
        $wpdb->update(
            $executions_table,
            array(
                'status' => 'completed',
                'output_data' => $final_result,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $execution_id)
        );
        
        $session_id = isset($_COOKIE['wp_ai_workflows_session_id']) ? sanitize_text_field(wp_unslash($_COOKIE['wp_ai_workflows_session_id'])) : null;
    
        if ($session_id) {
            // Use the provided session ID when saving output
            $wpdb->insert(
                $shortcode_outputs_table,
                array(
                    'session_id' => $session_id,
                    'workflow_id' => $workflow_id,
                    'output_data' => $final_result
                ),
                array('%s', '%s', '%s')
            );
            error_log("Workflow execution debug - Session ID: $session_id, Workflow ID: $workflow_id, Insert result: " . var_export($result, true));
        }
    
        WP_AI_Workflows_Utilities::debug_log("Workflow execution completed", "debug", ["workflow_id" => $workflow_id, "results" => $node_data]);
        return $node_data;
    }

    private static function get_last_executed_node($execution_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        
        $execution = $wpdb->get_row($wpdb->prepare("SELECT output_data FROM $table_name WHERE id = %d", $execution_id));
        
        if ($execution && $execution->output_data) {
            $output_data = json_decode($execution->output_data, true);
            if (is_array($output_data)) {
                end($output_data);
                return key($output_data);
            }
        }
        
        return null;
    }
    
    public static function execute_scheduled_workflow($workflow_id) {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['workflow_id' => $workflow_id]);
        
        $result = self::execute_workflow($workflow_id);
        
        if (is_wp_error($result)) {
            WP_AI_Workflows_Utilities::debug_log("Scheduled workflow execution error: " . $result->get_error_message(), "error");
        } else {
            WP_AI_Workflows_Utilities::debug_log("Scheduled workflow executed successfully", "debug");
}
// Check if this is a recurring schedule
$workflows = get_option('wp_ai_workflows', array());
foreach ($workflows as $workflow) {
    if ($workflow['id'] === $workflow_id && isset($workflow['schedule']) && $workflow['schedule']['enabled']) {
        $next_run = self::calculate_next_run($workflow['schedule']);
        if ($next_run) {
            wp_schedule_single_event($next_run, 'wp_ai_workflows_execute_scheduled_workflow', array($workflow_id));
            WP_AI_Workflows_Utilities::debug_log("Rescheduled recurring workflow", "debug", [
                'workflow_id' => $workflow_id,
                'next_run' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s')
            ]);
        }
        break;
    }
}
}

public static function handle_webhook_trigger($request) {
    $node_id = $request->get_param('node_id');
    $key = $request->get_param('key');

    // Verify the webhook key
    $stored_key = get_option('wp_ai_workflows_webhook_' . $node_id);
    if ($key !== $stored_key) {
        return new WP_Error('invalid_webhook_key', 'Invalid webhook key', array('status' => 403));
    }

    // Process the webhook data
    $webhook_data = $request->get_json_params();
    if (empty($webhook_data)) {
        $webhook_data = $request->get_body_params();
    }

    // Store the webhook data for sampling
    set_transient('wp_ai_workflows_webhook_sample_' . $node_id, $webhook_data, 120); // Increased timeout to 2 minutes

    WP_AI_Workflows_Utilities::debug_log("Webhook received", "debug", ['node_id' => $node_id, 'data' => $webhook_data]);


    // Find and execute the corresponding workflow
    $workflows = get_option('wp_ai_workflows', array());
    foreach ($workflows as $workflow) {
        $trigger_node = self::find_trigger_node($workflow['nodes']);
        if ($trigger_node && $trigger_node['id'] === $node_id && $trigger_node['data']['triggerType'] === 'webhook') {
            // Schedule the workflow execution
            wp_schedule_single_event(time(), 'wp_ai_workflows_execute_webhook_workflow', array($workflow['id'], $webhook_data));
            break;
        }
    }

    return new WP_REST_Response(array('message' => 'Webhook received and workflow execution scheduled'), 200);
}


public static function execute_webhook_workflow($workflow_id, $trigger_data) {
WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['workflow_id' => $workflow_id, 'trigger_data' => $trigger_data]);

$result = self::execute_workflow($workflow_id, $trigger_data);
if (is_wp_error($result)) {
    WP_AI_Workflows_Utilities::debug_log("Webhook workflow execution error: " . $result->get_error_message(), "error");
} else {
    WP_AI_Workflows_Utilities::debug_log("Webhook workflow executed successfully", "debug");
}
}

public static function generate_webhook_url($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    $node_id = $request->get_param('nodeId');
    $webhook_key = wp_generate_password(12, false);
    update_option('wp_ai_workflows_webhook_' . $node_id, $webhook_key);

    $webhook_url = rest_url('wp-ai-workflows/v1/webhook/' . $node_id);
    $webhook_url = add_query_arg('key', $webhook_key, $webhook_url);

    return new WP_REST_Response(array('webhookUrl' => $webhook_url), 200);
    }

    public static function save_output($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    $params = $request->get_json_params();
    $node_id = $params['nodeId'];
    $output_data = $params['outputData'];

    $output_id = uniqid('output_');

    $saved_outputs = get_option('wp_ai_workflows_outputs', array());
    $saved_outputs[$output_id] = array(
        'node_id' => $node_id,
        'data' => $output_data,
        'timestamp' => current_time('mysql')
    );
    update_option('wp_ai_workflows_outputs', $saved_outputs);

    return new WP_REST_Response(array('id' => $output_id), 200);
}

public static function get_outputs($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $table = $wpdb->prefix . $request->get_param('table');

    WP_AI_Workflows_Utilities::debug_log("Attempting to fetch outputs", "debug", ['table' => $table]);

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) {
        WP_AI_Workflows_Utilities::debug_log("Table does not exist", "error", ['table' => $table]);
        return new WP_Error('invalid_table', 'The specified table does not exist', array('status' => 400));
    }

    $outputs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", 100));
    WP_AI_Workflows_Utilities::debug_log("Outputs fetched", "debug", ['count' => count($outputs)]);
    return new WP_REST_Response($outputs, 200);
    }

    public static function get_latest_output($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $node_id = $request->get_param('node_id');

    $table_name = $wpdb->prefix . 'wp_ai_workflows_outputs';

    $latest_output = $wpdb->get_var($wpdb->prepare(
        "SELECT output_data FROM {$table_name} WHERE node_id = %s ORDER BY created_at DESC LIMIT 1",
        $node_id
    ));

    if ($latest_output) {
        return new WP_REST_Response(array('content' => $latest_output), 200);
    }

    $saved_outputs = get_option('wp_ai_workflows_outputs', array());
    if (isset($saved_outputs[$node_id])) {
        $output = $saved_outputs[$node_id]['data'];
        return new WP_REST_Response(array('content' => $output), 200);
    }

    return new WP_REST_Response(array('content' => ''), 200);
}


// Helper methods

public static function clear_scheduled_events($workflow_id) {
wp_clear_scheduled_hook('wp_ai_workflows_execute_scheduled', array($workflow_id));
}

public static function calculate_next_run($schedule) {
WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['schedule' => $schedule]);

$now = time(); // Use UTC time
$interval = $schedule['interval'];
$unit = $schedule['unit'];
$end_date = isset($schedule['endDate']) ? strtotime($schedule['endDate']) : false;

switch ($unit) {
    case 'minute':
        $next_run = $now + ($interval * MINUTE_IN_SECONDS);
        break;
    case 'hour':
        $next_run = $now + ($interval * HOUR_IN_SECONDS);
        break;
    case 'day':
        $next_run = $now + ($interval * DAY_IN_SECONDS);
        break;
    case 'week':
        $next_run = $now + ($interval * WEEK_IN_SECONDS);
        break;
    case 'month':
        $next_run = strtotime("+{$interval} months", $now);
        break;
    default:
        return false;
}

if ($end_date && $next_run > $end_date) {
    return false;
}

return $next_run;
}

public static function get_workflow_name($workflow_id) {
WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['workflow_id' => $workflow_id]);

$workflows = get_option('wp_ai_workflows', array());
foreach ($workflows as $workflow) {
    if ($workflow['id'] === $workflow_id) {
        return $workflow['name'];
    }
}
return 'Unnamed Workflow';
}

public static function get_executions($request) {
    $cache_key = 'wp_ai_workflows_executions_' . md5(serialize($request->get_params()));
    $cached_result = get_transient($cache_key);

    if ($cached_result !== false) {
        return new WP_REST_Response($cached_result, 200);
    }
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';

    $page = $request->get_param('page') ?: 1;
    $per_page = $request->get_param('pageSize') ?: 10;
    $search = $request->get_param('search') ?: '';

    $offset = ($page - 1) * $per_page;

    $where = '';
    if (!empty($search)) {
        $where = $wpdb->prepare("WHERE workflow_name LIKE %s", '%' . $wpdb->esc_like($search) . '%');
    }

    $total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} {$where}"));
    $executions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_name} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));

    $wp_timezone = wp_timezone();
    $timezone_offset = $wp_timezone->getOffset(new DateTime()) / 3600; // Convert seconds to hours

    foreach ($executions as &$execution) {
        $execution->input_data = json_decode($execution->input_data);
        $execution->output_data = json_decode($execution->output_data);
        
        if (!is_array($execution->output_data)) {
            $execution->output_data = [$execution->output_data];
        }
        
        $latest_status = end($execution->output_data);
        $execution->latest_status = is_object($latest_status) && isset($latest_status->message) ? $latest_status->message : '';

        unset($execution->output_data);

        if ($execution->status === 'scheduled') {
            $execution->next_execution = $execution->scheduled_at;
        } else {
            $workflow = self::get_workflow_by_id($execution->workflow_id);
            if ($workflow && isset($workflow['schedule']) && $workflow['schedule']['enabled']) {
                $next_run = self::calculate_next_run($workflow['schedule']);
                $execution->next_execution = $next_run ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s') : null;
            } else {
                $execution->next_execution = null;
            }
        }

    
        $execution->created_at = wp_date('Y-m-d H:i:s', strtotime($execution->created_at), $wp_timezone);
        $execution->updated_at = wp_date('Y-m-d H:i:s', strtotime($execution->updated_at), $wp_timezone);
        $execution->scheduled_at = $execution->scheduled_at ? wp_date('Y-m-d H:i:s', strtotime($execution->scheduled_at), $wp_timezone) : null;
    }

    $result = array(
        'executions' => $executions,
        'total' => $total,
        'timezone_offset' => $timezone_offset
    );

    set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);

    return new WP_REST_Response($result, 200);
}

public static function get_workflow_by_id($workflow_id) {
$workflows = get_option('wp_ai_workflows', array());
foreach ($workflows as $workflow) {
    if ($workflow['id'] === $workflow_id) {
        return $workflow;
    }
}
return null;
}

public static function get_execution($request) {
WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

global $wpdb;
$table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
$id = $request->get_param('id');

$execution = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $id));

if (!$execution) {
    return new WP_Error('not_found', 'Execution not found', array('status' => 404));
}

return new WP_REST_Response($execution, 200);
}

public static function stop_and_delete_execution($request) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['request' => $request->get_params()]);

    global $wpdb;
    $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
    $id = $request->get_param('id');

    // First, get the execution details
    $execution = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));

    if (!$execution) {
        return new WP_Error('not_found', 'Execution not found', array('status' => 404));
    }

    // If the execution is still processing, we need to stop it
    if ($execution->status === 'processing') {
    // Clear any scheduled events for this execution
    wp_clear_scheduled_hook('wp_ai_workflows_execute_scheduled', array($execution->workflow_id));

    $wpdb->update(
        $table_name,
        array('status' => 'terminated', 'updated_at' => current_time('mysql')),
        array('id' => $id)
    );
    }

    $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));

    if ($result === false) {
        return new WP_Error('delete_failed', 'Failed to delete execution', array('status' => 500));
    }

    return new WP_REST_Response(null, 204);
}

public static function get_downstream_nodes($node_id, $edges, $handle = null) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['node_id' => $node_id, 'handle' => $handle]);

    $downstream = [];
    $queue = [['id' => $node_id, 'handle' => $handle]];
    $visited = [];

    WP_AI_Workflows_Utilities::debug_log("Starting get_downstream_nodes", "debug", ["start_node" => $node_id, "handle" => $handle]);

    while (!empty($queue)) {
        $current = array_shift($queue);
        $current_id = $current['id'];
        $current_handle = $current['handle'];

        if (in_array($current_id, $visited)) {
        continue;
        }
    $visited[] = $current_id;

    WP_AI_Workflows_Utilities::debug_log("Processing node", "debug", ["current_node" => $current_id, "handle" => $current_handle]);

    foreach ($edges as $edge) {
        if ($edge['source'] === $current_id && 
            ($current_handle === null || $edge['sourceHandle'] === $current_handle || $edge['sourceHandle'] === null)) {
            $target = $edge['target'];
            if (!in_array($target, $downstream)) {
                $downstream[] = $target;
                $queue[] = ['id' => $target, 'handle' => null];
                WP_AI_Workflows_Utilities::debug_log("Found downstream node", "debug", ["source" => $current_id, "target" => $target, "handle" => $edge['sourceHandle']]);
            }
        }
        }
    }

    WP_AI_Workflows_Utilities::debug_log("Completed get_downstream_nodes", "debug", ["start_node" => $node_id, "handle" => $handle, "downstream_nodes" => $downstream]);
    return array_unique($downstream);
}

public static function topological_sort($nodes, $edges) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['nodes_count' => count($nodes), 'edges_count' => count($edges)]);

    $graph = array();
    $sorted = array();
    $visited = array();

    // Build the graph
    foreach ($nodes as $node) {
        $graph[$node['id']] = array();
    }
    foreach ($edges as $edge) {
        $graph[$edge['source']][] = $edge['target'];
    }

    // Depth-first search function
    $dfs = function($node) use (&$graph, &$visited, &$sorted, &$dfs) {
        $visited[$node] = true;
        if (isset($graph[$node])) {
            foreach ($graph[$node] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $dfs($neighbor);
                }
            }
        }
        array_unshift($sorted, $node);
    };

    // Perform DFS for each node
    foreach ($nodes as $node) {
        if (!isset($visited[$node['id']])) {
            $dfs($node['id']);
        }
    }

    // Map the sorted node IDs back to their full node objects
    $sorted_nodes = array_map(function($id) use ($nodes) {
        return array_values(array_filter($nodes, function($node) use ($id) {
            return $node['id'] === $id;
        }))[0];
    }, $sorted);

    return $sorted_nodes;
}

public static function find_trigger_node($nodes) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['nodes_count' => count($nodes)]);

    foreach ($nodes as $node) {
        if ($node['type'] === 'trigger') {
            return $node;
        }
    }
    return null;
}

public static function find_node_by_id($nodes, $id) {
    WP_AI_Workflows_Utilities::debug_function(__FUNCTION__, ['id' => $id]);

    foreach ($nodes as $node) {
        if ($node['id'] == $id) {
            return $node;
        }
    }
    return null;
}

}