<?php
class WP_AI_Workflows_Analytics_Collector {
    private $plugin_version;
    private $is_pro;
    private $analytics_endpoint = 'https://wpaiworkflows.com/wp-json/wp-ai-workflows-analytics/v1/collect';
    private $plugin_basename;

    public function __construct($version, $is_pro = false) {
        $this->plugin_version = $version;
        $this->is_pro = $is_pro;
        $this->plugin_basename = $is_pro ? WP_AI_WORKFLOWS_PRO_BASENAME : WP_AI_WORKFLOWS_LITE_BASENAME;
    }

    public function init() {
        if (!$this->is_opted_out()) {
            add_action('activated_plugin', array($this, 'track_activation'), 10, 2);
            add_action('deactivated_plugin', array($this, 'track_deactivation'), 10, 2);
            add_action('wp_ai_workflows_daily_analytics', array($this, 'send_analytics_data'));
            
            if (!wp_next_scheduled('wp_ai_workflows_daily_analytics')) {
                wp_schedule_event(time(), 'daily', 'wp_ai_workflows_daily_analytics');
            }
        }

        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_settings() {
        register_setting('wp_ai_workflows_settings', 'wp_ai_workflows_analytics_opt_out');
    }

    private function is_opted_out() {
        return get_option('wp_ai_workflows_analytics_opt_out', false);
    }

    public function track_activation($plugin, $network_wide) {
        if ($plugin === $this->plugin_basename && !$this->is_opted_out()) {
            $installation_id = $this->get_or_create_installation_id();
            $site_data = $this->get_site_data();
            
            // Get the usage data
            $usage_data = $this->collect_usage_data();
            
            $status_data = array(
                'status' => 'active',
                'activation_date' => current_time('mysql'),
                'site_url' => $site_data['site_url']
            );
    
            // Merge usage data with status data
            if ($usage_data) {
                $status_data = array_merge($status_data, array(
                    'metrics' => $usage_data['metrics'],
                    'settings' => $usage_data['settings']
                ));
            }
    
            $this->send_event('activation', $installation_id, $status_data);
            update_option('wp_ai_workflows_last_activation', current_time('mysql'));
        }
    }

    public function track_deactivation($plugin, $network_wide) {
        if ($plugin === $this->plugin_basename && !$this->is_opted_out()) {
            $installation_id = get_option('wp_ai_workflows_installation_id');
            if ($installation_id) {
                $site_data = $this->get_site_data();
                $status_data = array(
                    'status' => 'inactive',
                    'deactivation_date' => current_time('mysql'),
                    'site_url' => $site_data['site_url'],
                    'total_active_days' => $this->calculate_active_days()
                );
                $this->send_event('deactivation', $installation_id, $status_data);
            }
        }
    }

    private function get_or_create_installation_id() {
        $installation_id = get_option('wp_ai_workflows_installation_id');
        if (!$installation_id) {
            $installation_id = wp_generate_uuid4();
            update_option('wp_ai_workflows_installation_id', $installation_id);
            update_option('wp_ai_workflows_installed_at', current_time('mysql'));
        }
        return $installation_id;
    }

    private function get_site_data() {
        return array(
            'site_url' => get_site_url(),
            'site_hash' => md5(home_url()),
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'plugin_version' => $this->plugin_version
        );
    }

    private function calculate_active_days() {
        $last_activation = get_option('wp_ai_workflows_last_activation');
        if (!$last_activation) {
            return 0;
        }
        return round((strtotime('now') - strtotime($last_activation)) / DAY_IN_SECONDS);
    }

    public function send_analytics_data() {
        if ($this->is_opted_out()) {
            return;
        }

        $data = $this->collect_usage_data();
        if ($data) {
            $this->send_data($data);
        }
    }

    private function collect_usage_data() {
        global $wpdb;
        
        $installation_id = get_option('wp_ai_workflows_installation_id');
        if (!$installation_id) {
            return false;
        }
    
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        // Get workflows from wp_options table
        $workflows = get_option('wp_ai_workflows', array());
        
        // Calculate workflow counts
        $total_workflows = count($workflows);
        $active_workflows = count(array_filter($workflows, function($workflow) {
            return isset($workflow['status']) && $workflow['status'] === 'active';
        }));
        
        $metrics = array(
            'total_workflows' => $total_workflows,
            'active_workflows' => $active_workflows,
            'executions_30d' => $this->get_count($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wp_ai_workflows_executions WHERE created_at >= %s",
                $thirty_days_ago
            )),
            'successful_executions_30d' => $this->get_count($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wp_ai_workflows_executions WHERE status = 'completed' AND created_at >= %s",
                $thirty_days_ago
            ))
        );
    
        $metrics['success_rate'] = $metrics['executions_30d'] > 0 ? 
            round(($metrics['successful_executions_30d'] / $metrics['executions_30d']) * 100, 2) : 0;
    
        $site_data = $this->get_site_data();
        
        return array_merge(
            array('installation_id' => $installation_id),
            $site_data,
            array(
                'is_pro' => $this->is_pro,
                'metrics' => $metrics,
                'settings' => $this->get_sanitized_settings(),
                'timestamp' => current_time('mysql')
            )
        );
    }

    private function get_count($query) {
        global $wpdb;
        $count = $wpdb->get_var($query);
        return $count !== null ? (int)$count : 0;
    }

    private function get_sanitized_settings() {
        $settings = get_option('wp_ai_workflows_settings', array());
        return array(
            'selected_models' => isset($settings['selected_models']) ? $settings['selected_models'] : array()
        );
    }

    private function send_event($event_type, $installation_id, $additional_data = array()) {
        $data = array_merge(
            array(
                'installation_id' => $installation_id,
                'event' => $event_type,
                'is_pro' => $this->is_pro,
                'plugin_version' => $this->plugin_version,
                'timestamp' => current_time('mysql')
            ),
            $additional_data
        );

        $this->send_data($data);
    }

    private function send_data($data) {
        $response = wp_remote_post($this->analytics_endpoint, array(
            'body' => wp_json_encode($data),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 5,
            'blocking' => false,
            'sslverify' => true
        ));

        if (WP_DEBUG) {
            WP_AI_Workflows_Utilities::debug_log('Analytics data sent', 'debug', array(
                'endpoint' => $this->analytics_endpoint,
                'data' => $data,
                'response' => is_wp_error($response) ? $response->get_error_message() : 'success'
            ));
        }
    }

    public static function uninstall() {
        $installation_id = get_option('wp_ai_workflows_installation_id');
        if ($installation_id && !get_option('wp_ai_workflows_analytics_opt_out', false)) {
            wp_remote_post('https://wpaiworkflows.com/wp-json/wp-ai-workflows-analytics/v1/collect', array(
                'body' => wp_json_encode(array(
                    'installation_id' => $installation_id,
                    'event' => 'uninstall',
                    'status' => 'uninstalled',
                    'timestamp' => current_time('mysql')
                )),
                'headers' => array('Content-Type' => 'application/json'),
                'blocking' => false
            ));
        }

        delete_option('wp_ai_workflows_installation_id');
        delete_option('wp_ai_workflows_installed_at');
        delete_option('wp_ai_workflows_last_activation');
        delete_option('wp_ai_workflows_analytics_opt_out');
    }
}