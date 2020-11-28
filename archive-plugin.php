<?php

/**
 * Plugin Name: Archive Plugin
 * Plugin URI: https://github.com/DanielRuf/wp-archive-plugin
 * Description: Archive inactive plugins as encrypted/password protected zip files.
 * Version: 1.1.0
 * License: GPLv3 or later
 * Author: Daniel Ruf
 * Author URI: https://daniel-ruf.de
 */

// prevent direct access
if (!defined('ABSPATH')) {
    exit('Forbidden');
}

// prevent access outside of wp-admin
if (!function_exists('is_admin')) {
    exit('Forbidden');
}

// add additional plugin link
function wpap_add_plugin_link($plugin_actions, $plugin_file, $plugin_data)
{
    // prepare new actions
    $new_actions = array();
    $plugin_name = $plugin_data['Name'] ?? '';
    $archived_string = '(archived)';
    $is_archived = false;
    if (strpos($plugin_name, $archived_string) === (strlen($plugin_name) - strlen($archived_string))) {
        $is_archived = true;
    }
    // build URL query object
    $url = add_query_arg(
        [
            'page' => $is_archived ? 'wpap-unarchive-plugin' : 'wpap-archive-plugin', // page name for menu
            'action' => $is_archived ? 'wpap-unarchive-plugin' : 'wpap-archive-plugin', // internal action
            'plugin'   => $plugin_file, // plugin entry file
            'nonce'  => $is_archived ? wp_create_nonce('wpap-unarchive-plugin') : wp_create_nonce('wpap-archive-plugin'), // generate nonce
        ],
        admin_url('plugins.php') // use wp-admin/plugins.php
    );
    // only add the new link if the plugin is inactive and if the user can delete plugins
    if (!$is_archived && isset($plugin_actions['activate']) && current_user_can('delete_plugins')) {
        $new_actions['wpap-archive'] = sprintf(__('<a href="%s">Archive & Delete</a>', 'wpap-archive-plugin'), esc_url($url));
    } else if ($is_archived && isset($plugin_actions['activate']) && current_user_can('install_plugins')) {
        $plugin_actions = array();
        $new_actions['wpap-unarchive'] = sprintf(__('<a href="%s">Unarchive</a>', 'wpap-archive-plugin'), esc_url($url));
    }
    // append the new link
    return array_merge($plugin_actions, $new_actions);
}

function wpap_output_information($links_array, $plugin_file_name, $plugin_data, $status)
{
    $current_file = basename(__FILE__);
    $current_folder = basename(__DIR__);
    if ($plugin_file_name === "{$current_folder}/{$current_file}") {
        echo "The <code>SECURE_AUTH_SALT</code> secret is used as password.<br/><br/>";
    }
    return $links_array;
}

// finally call the function when the plugin links are rendered
add_filter('plugin_action_links', 'wpap_add_plugin_link', 10, 3);
// output additional information below the description
add_filter('plugin_row_meta', 'wpap_output_information', 10, 4);

// archive and delete the supplied plugin
function wpap_archive_plugin($plugin_file)
{
    // check if the current user can delete plugins
    if (current_user_can('delete_plugins')) {
        // cancel if the plugin is still active
        if (is_plugin_active($plugin_file)) {
            return false;
        };
        // get the plugin name
        $plugin = basename(plugin_dir_path($plugin_file));
        // get all plugins
        $all_plugins = get_plugins();
        // assume that the plugin is not installed
        $plugin_found = false;
        $plugin_data = null;
        // check if the plugin is installed
        foreach ($all_plugins as $current_plugin => $current_plugin_data) {
            if ($current_plugin === $plugin_file) {
                $plugin_found = true;
                $plugin_data = $current_plugin_data;
                break;
            }
        }
        // cancel if it is not found
        if (!$plugin_found) {
            return false;
        }
        // assume that this is in wp-plugins/plugins
        $is_subfolder = true;
        // if this is not the case the file is the whole plugin
        if ($plugin === '.') {
            $is_subfolder = false;
            $plugin = $plugin_file;
        }
        // the root path of the files to archive
        $root_path = WP_PLUGIN_DIR . '/' . $plugin;
        // set zip archive filename
        $zipcreated = WP_PLUGIN_DIR . '/' . $plugin . '_archived.zip';
        // create new zip archive instance
        $zip = new ZipArchive;
        // try to create zip archive
        if (file_exists($root_path) && $zip->open($zipcreated, ZipArchive::CREATE) === TRUE) {
            // zip recursively if it is a folder
            if ($is_subfolder) {
                // create recursive directory iterator
                /** @var SplFileInfo[] $files */
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root_path),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                // iterate over the files
                foreach ($files as $name => $file) {
                    // skip directories (they would be added automatically)
                    if (!$file->isDir()) {
                        // get real and relative path for current file
                        $file_path = $file->getRealPath();
                        $relative_path = substr($file_path, strlen($root_path) + 1);

                        // add current file to archive
                        $zip->addFile($file_path, $relative_path);
                        // encrypt with the SECURE_AUTH_SALT constant as password using AES 256
                        $zip->setEncryptionName($relative_path, ZipArchive::EM_AES_256, SECURE_AUTH_SALT);
                    }
                }
            } else {
                // add current file to archive
                $zip->addFile($root_path, $plugin_file);
                // encrypt with the SECURE_AUTH_SALT constant as password using AES 256
                $zip->setEncryptionName($plugin_file, ZipArchive::EM_AES_256, SECURE_AUTH_SALT);
            }
            // zip archive will be created only after closing object
            $result = $zip->close();
            if ($result) {
                // delete plugin if zip archive was successfully created
                $deleted = delete_plugins([$plugin_file]);
                if ($deleted) {
                    return wpap_create_archived_plugin_placeholder_file($root_path, $plugin_data);
                }
            }
        }
    }
    return false;
}

// unarchive the supplied plugin
function wpap_unarchive_plugin($plugin_file)
{
    $plugin = str_replace('_archived.php', '', $plugin_file);
    $php_string = '.php';
    $extract_to = WP_PLUGIN_DIR . '/' . $plugin;
    if (strpos($plugin, $php_string) === (strlen($plugin) - strlen($php_string))) {
        $extract_to = WP_PLUGIN_DIR;
    }
    $zipfile = WP_PLUGIN_DIR . '/' . $plugin . '_archived.zip';
    $zip = new ZipArchive;
    if (file_exists($zipfile) && $zip->open($zipfile) === TRUE) {
        $zip->setPassword(SECURE_AUTH_SALT);
        $zip->extractTo($extract_to);
        $zip->close();
        $success_archive = unlink(WP_PLUGIN_DIR . '/' . $plugin_file);
        $success_zip = unlink($zipfile);
        return ($success_archive && $success_zip);
    }
    return false;
}

// run the archive plugin on the page
function wpap_archive_plugin_init()
{
    // set some heading
    echo '<h1>Archive plugin</h1>';
    // check the provided parameters and verify the nonce
    if (
        isset($_GET['plugin']) &&
        isset($_GET['action']) &&
        isset($_GET['page']) &&
        $_GET['action'] === 'wpap-archive-plugin' &&
        $_GET['page'] === 'wpap-archive-plugin' &&
        wp_verify_nonce($_GET['nonce'], 'wpap-archive-plugin') &&
        is_admin()
    ) {
        // run the archive plugin logic
        $success = wpap_archive_plugin($_GET['plugin']);
        if ($success) {
            echo "Successfully archived {$_GET['plugin']}.";
        } else {
            echo 'Something failed, please check if the files exist.';
        }
    } else {
        echo 'This action is not allowed.';
    }
}

// run the unarchive plugin on the page
function wpap_unarchive_plugin_init()
{
    // set some heading
    echo '<h1>Unarchive plugin</h1>';
    // check the provided parameters and verify the nonce
    if (
        isset($_GET['plugin']) &&
        isset($_GET['action']) &&
        isset($_GET['page']) &&
        $_GET['action'] === 'wpap-unarchive-plugin' &&
        $_GET['page'] === 'wpap-unarchive-plugin' &&
        wp_verify_nonce($_GET['nonce'], 'wpap-unarchive-plugin') &&
        is_admin()
    ) {
        // run the unarchive plugin logic
        $success = wpap_unarchive_plugin($_GET['plugin']);
        if ($success) {
            echo "Successfully unarchived {$_GET['plugin']}";
        } else {
            echo 'Something failed, please check if the files exist.';
        }
    } else {
        echo 'This action is not allowed.';
    }
}

// add new page to the admin menu
function wpap_archive_plugin_setup_menu()
{
    // set page title, menu title, capability, menu slug and the function to call 
    add_submenu_page(null, 'Archive Plugin Page', 'Archive Plugin', 'delete_plugins', 'wpap-archive-plugin', 'wpap_archive_plugin_init');
    add_submenu_page(null, 'Archive Plugin Page', 'Unarchive Plugin', 'install_plugins', 'wpap-unarchive-plugin', 'wpap_unarchive_plugin_init');
}

// finally call the function to add the new page
add_action('admin_menu', 'wpap_archive_plugin_setup_menu');

function wpap_create_archived_plugin_placeholder_file($path, $plugin_data)
{
    $password = htmlentities(SECURE_AUTH_SALT);
    $template = <<<EOT
<?php

/**
 * Plugin Name: %s (archived)
 * Plugin URI: %s
 * Description: %s <em>This plugin is currently archived and the archive is protected with a password. The <code>SECURE_AUTH_SALT</code> secret is used as password.</em>
 * Version: %s
 * Author: %s
 * Author URI: %s
 */

// prevent direct access
if (!defined('ABSPATH')) {
    exit('Forbidden');
}

// prevent access outside of wp-admin
if (!function_exists('is_admin')) {
    exit('Forbidden');
}
EOT;
    $template_filled = sprintf(
        $template,
        $plugin_data['Name'] ?? '',
        $plugin_data['PluginURI'] ?? '',
        $plugin_data['Description'] ?? '',
        $plugin_data['Version'] ?? '',
        $plugin_data['Author'] ?? '',
        $plugin_data['AuthorURI'] ?? '',
        '%s'
    );
    $success = file_put_contents($path . '_archived.php', $template_filled);
    return $success;
}
