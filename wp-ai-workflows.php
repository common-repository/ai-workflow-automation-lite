<?php
/*
 * Plugin Name: AI Workflow Automation Lite
 * Plugin URI: https://wpaiworkflowautomation.com
 * Description: A WordPress plugin for building AI-powered workflows with a visual interface.
 * Version: 1.0.6
 * Requires at least: 6.0.0
 * Requires PHP: 7.2
 * Author: Massive Shift
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-workflows
 * Domain Path: /languages
 
 ---------------------------------------------------------------------------
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program.  If not, see http://www.gnu.org/licenses.

*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('WP_AI_WORKFLOWS_LITE_VERSION', '1.0.6');
define('WP_AI_WORKFLOWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_AI_WORKFLOWS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_AI_WORKFLOWS_PLUGIN_FILE', __FILE__);
define('WP_AI_WORKFLOWS_DEBUG', true);
define('WP_AI_WORKFLOWS_LITE_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/class-wp-ai-workflows-utilities.php';
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/class-wp-ai-workflows-database.php';
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/class-wp-ai-workflows-workflow.php';
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/class-wp-ai-workflows-node-execution.php';
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/class-wp-ai-workflows-rest-api.php';
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/class-wp-ai-workflows-shortcode.php';
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'includes/class-wp-ai-workflows-analytics-collector.php';
require_once WP_AI_WORKFLOWS_PLUGIN_DIR . 'admin/class-wp-ai-workflows-admin.php';

/**
 * The code that runs during plugin activation.
 */
function activate_wp_ai_workflows() {
    WP_AI_Workflows_Database::create_tables();
    WP_AI_Workflows_Utilities::generate_and_encrypt_api_key();
    wp_ai_workflows_schedule_cleanup_cron();
    if (get_option('wp_ai_workflows_analytics_opt_out') === false) {
        update_option('wp_ai_workflows_analytics_opt_out', false);
    }
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_wp_ai_workflows() {
    wp_clear_scheduled_hook('wp_ai_workflows_cleanup');
    wp_clear_scheduled_hook('wp_ai_workflows_daily_analytics');
    WP_AI_Workflows_Utilities::debug_log("WP AI Workflows Lite plugin deactivated and cleanup tasks unscheduled");
}

register_activation_hook(__FILE__, 'activate_wp_ai_workflows');
register_deactivation_hook(__FILE__, 'deactivate_wp_ai_workflows');

/**
 * Begins execution of the plugin.
 */
function run_wp_ai_workflows() {
    // Initialize classes
    $analytics_collector = new WP_AI_Workflows_Analytics_Collector(
        WP_AI_WORKFLOWS_LITE_VERSION,
        false
    );
    $analytics_collector->init();
    $utilities = new WP_AI_Workflows_Utilities();
    $database = new WP_AI_Workflows_Database();
    $workflow = new WP_AI_Workflows_Workflow();
    $node_execution = new WP_AI_Workflows_Node_Execution();
    $rest_api = new WP_AI_Workflows_REST_API();
    $shortcode = new WP_AI_Workflows_Shortcode();
    $admin = new WP_AI_Workflows_Admin();

    // Run the plugin components
    $utilities->init();
    $database->init();
    $workflow->init();
    $node_execution->init();
    $rest_api->init();
    $shortcode->init();
    $admin->init();
}

/**
 * Schedule the cleanup cron job.
 */
function wp_ai_workflows_schedule_cleanup_cron() {
    if (!wp_next_scheduled('wp_ai_workflows_cleanup')) {
        wp_schedule_event(time(), 'daily', 'wp_ai_workflows_cleanup');
    }
}

add_action('wp', 'wp_ai_workflows_schedule_cleanup_cron');

/**
 * Handle plugin updates.
 */
function wp_ai_workflows_update_check() {
    $current_version = get_option('wp_ai_workflows_lite_version', '0');
    if (version_compare($current_version, WP_AI_WORKFLOWS_LITE_VERSION, '<')) {
        if ($current_version === '0') {
            activate_wp_ai_workflows();
        }
        update_option('wp_ai_workflows_lite_version', WP_AI_WORKFLOWS_LITE_VERSION);
    }
}
add_action('plugins_loaded', 'wp_ai_workflows_update_check');

// Run the plugin
run_wp_ai_workflows();