<?php
/**
 * Database operations for WP AI Workflows plugin.
 */
class WP_AI_Workflows_Database {

    public function init() {
        // Any initialization code if needed
    }

    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
    
        $shortcode_outputs_table = $wpdb->prefix . 'wp_ai_workflows_shortcode_outputs';
        $outputs_table = $wpdb->prefix . 'wp_ai_workflows_outputs';
        $executions_table = $wpdb->prefix . 'wp_ai_workflows_executions';
    
        $sql_shortcode_outputs = "CREATE TABLE $shortcode_outputs_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(255) NOT NULL,
            workflow_id VARCHAR(255) NOT NULL,
            output_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $charset_collate;";
    
        $sql_outputs = "CREATE TABLE $outputs_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            node_id varchar(255) NOT NULL,
            output_data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
    
        $sql_executions = "CREATE TABLE $executions_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            workflow_id varchar(255) NOT NULL,
            workflow_name varchar(255) NOT NULL,
            status varchar(20) NOT NULL,
            input_data longtext,
            output_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            scheduled_at datetime,
            PRIMARY KEY  (id)
        ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_shortcode_outputs);
        dbDelta($sql_outputs);
        dbDelta($sql_executions);
    
        WP_AI_Workflows_Utilities::debug_log("Database tables created or updated", "info");
    }

    public static function cleanup_orphaned_executions() {
        WP_AI_Workflows_Utilities::debug_function(__FUNCTION__);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_ai_workflows_executions';
        $workflows = get_option('wp_ai_workflows', array());
        $existing_workflow_ids = array_column($workflows, 'id');

        $placeholders = implode(',', array_fill(0, count($existing_workflow_ids), '%s'));
        $orphaned = $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE workflow_id NOT IN ($placeholders)", $existing_workflow_ids
        ));

        WP_AI_Workflows_Utilities::debug_log("Cleaned up orphaned executions", "info", [
            'orphaned_executions_removed' => $orphaned
        ]);
    }

    private static function get_sql_type($type) {
        switch ($type) {
            case 'text':
                return 'TEXT';
            case 'number':
                return 'FLOAT';
            case 'datetime':
                return 'DATETIME';
            default:
                return 'TEXT';
        }
    }

}