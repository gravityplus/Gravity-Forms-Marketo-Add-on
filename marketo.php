<?php
/*
Plugin Name: Gravity Forms Marketo Add-On
Plugin URI: http://www.seodenver.com
Description: Integrates Gravity Forms with Marketo allowing form submissions to be automatically sent to your Marketo account
Version: 1.3.7
Author: Katz Web Services, Inc.
Author URI: http://www.katzwebservices.com

------------------------------------------------------------------------
Copyright 2013 Katz Web Services, Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/


add_action('init',  array('GFMarketo', 'init'));
register_activation_hook( __FILE__, array("GFMarketo", "add_permissions"));

class GFMarketo {

    private static $name = "Gravity Forms Marketo Add-On";
    private static $path = "gravity-forms-marketo/marketo.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravity-forms-marketo";
    private static $version = "1.3.7";
    private static $min_gravityforms_version = "1.3.9";
    private static $is_debug = NULL;
    private static $settings = array(
                "endpoint" => '',
                "user_id" => '',
                "encryption_key" => false,
                "subdomain" => '',
                "sync_type" => 'munchkin',
                "add_munchkin_js" => true,
                "fill_munchkin" => true,
                "debug" => false,
            );

    //Plugin starting point. Will load appropriate files
    public static function init(){
        global $pagenow;

        load_plugin_textdomain('gravity-forms-marketo', FALSE, '/gravity-forms-marketo/languages' );

        if($pagenow === 'plugins.php') {
            add_action("admin_notices", array('GFMarketo', 'is_gravity_forms_installed'), 10);
        }

        if(self::is_gravity_forms_installed(false, false) === 0){
            add_action('after_plugin_row_' . self::$path, array('GFMarketo', 'plugin_row') );
           return;
        }

        add_filter('plugin_action_links', array('GFMarketo', 'settings_link'), 10, 2 );

        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravity-forms-marketo', FALSE, '/gravity-forms-marketo/languages' );

            //creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_marketo")){
                RGForms::add_settings_page("Marketo", array("GFMarketo", "settings_page"), self::get_base_url() . "/images/marketo_wordpress_icon_32.png");
            }
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFMarketo", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFMarketo', 'create_menu'));

        if(self::is_marketo_page()){

            //enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));

            wp_enqueue_script("gforms_gravityforms", GFCommon::get_base_url() . "/js/gravityforms.js", null, GFCommon::$version);

            wp_enqueue_style("gforms_css", GFCommon::get_base_url() . "/css/forms.css", null, GFCommon::$version);

            //loading data lib
            require_once(self::get_base_path() . "/data.php");

            self::setup_tooltips();

            //runs the setup when version changes
            self::setup();

         }
         else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/data.php");

            add_action('wp_ajax_rg_update_feed_active', array('GFMarketo', 'update_feed_active'));
            add_action('wp_ajax_gf_select_marketo_form', array('GFMarketo', 'select_marketo_form'));

        }
        else{
             //handling post submission.
            add_action("gform_post_submission", array('GFMarketo', 'export'), 10, 2);
        }

        add_action('gform_entry_info', array('GFMarketo', 'entry_info_link_to_marketo'), 1, 2);

        add_filter('gform_save_field_value', array('GFMarketo', 'save_field_value'), 10, 4);

        add_filter('gform_entry_post_save', array('GFMarketo', 'gform_entry_post_save'), 1, 2);

        add_filter('gform_replace_merge_tags', array('GFMarketo', 'replace_merge_tag'), 1, 7);

        add_action("gform_custom_merge_tags", array('GFMarketo', "_deprecated_add_merge_tags"), 1, 4);

        add_action("gform_admin_pre_render", array('GFMarketo', "add_merge_tags"));

        add_filter('gform_pre_render', array('GFMarketo', 'merge_tag_gform_pre_render_filter'), 1, 4);

        add_action('gform_enqueue_scripts', array('GFMarketo', 'add_munchkin_js'), 2);
        add_action('wp_footer', array('GFMarketo', 'add_munchkin_js'));

    }

    /**
     * Get the Munchkin ID from the Endpoint URL setting
     * @return string Munchkin ID
     */
    static function get_munchkin_id() {
        return preg_replace('/https?:\/\/(.*?)\..+/ism', '$1', self::get_setting('endpoint'));
    }

    /**
     * Add the Munchkin tracking code to the site's footer or when Gravity Forms is output
     *
     * @param array|null   $form Gravity Forms form array, if triggered by `gform_enqueue_scripts`
     * @param boolean $ajax Gravity Forms is ajax boolean, if triggered by `gform_enqueue_scripts`
     */
    static function add_munchkin_js($form = array(), $ajax = false) {

        if(!self::get_setting('add_munchkin_js')) { return; }

        if(function_exists('marketo_tracker')) {
            if(current_user_can('manage_options')) { echo "\n".'<!-- Gravity Forms Marketo Addon did not load the munchkin cookie because the Marketo Tracker plugin is activated. -->'."\n\n";  }
            return;
        }

        if(did_action( 'gf_marketo_add_munchkin_js' )) { return; }

        //loading data class
        require_once(self::get_base_path() . "/data.php");

        $feeds = GFMarketoData::get_feeds();

        if(!empty($form) && !empty($form['id'])) {
            foreach($feeds as $feed) {
                if(floatval($feed['id']) !== floatval($form['id'])) { continue; }
            }
        }
    ?>
<script>
    document.write(unescape("%3Cscript src='https://ssl-munchkin.marketo.net/js/munchkin.js' type='text/javascript'%3E%3C/script%3E"));
</script>
<script>Munchkin.init('<?php echo self::get_munchkin_id(); ?>');</script>
        <?php

        do_action('gf_marketo_add_munchkin_js');
    }

    /**
     * Process the {munchkin} merge tag before the field is saved into GF database
     */
    static function save_field_value($value, $lead, $field, $form) {
        return self::replace_merge_tag($value);
    }

    /**
     * Replace {munchkin} with the cookie value, if cookie exists
     */
    static function replace_merge_tag($text, $form = array(), $entry = array(), $url_encode = false, $esc_html = true, $nl2br = false, $format = false) {

        $custom_merge_tag = '{munchkin}';
        $cookie = self::get_munchkin_cookie();

        if(strpos($text, $custom_merge_tag) === false || empty($cookie)) {
            return $text;
        }

        $text = str_replace($custom_merge_tag, $cookie, $text);

        return $text;
    }

    /**
     * Add Marketo {munchkin} merge tag
     *
     * The new way of adding merge tags since GF 1.7
     *
     * @param array $form Current GF form array
     * @deprecated
     */
    static function _deprecated_add_merge_tags($merge_tags, $form_id, $fields, $element_id) {

        if(version_compare(GFCommon::$version, '1.7', "<")) {
            $merge_tags[] = array('label' => __('Munchkin Cookie Data', 'gravity-forms-marketo'), 'tag' => '{munchkin}');
        }

        return $merge_tags;
    }

    /**
     * Add Marketo {munchkin} merge tag
     *
     * The new way of adding merge tags since GF 1.7
     *
     * @param array $form Current GF form array
     */
    function add_merge_tags($form){
    ?>
    <script>
        gform.addFilter("gform_merge_tags", "marketo_add_merge_tags");
        function marketo_add_merge_tags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option){
            mergeTags["custom"].tags.push({ tag: '{munchkin}', label: '<?php echo str_replace("'", "\'", __('Munchkin Cookie Data')); ?>' });

            return mergeTags;
        }
    </script>
    <?php
        //return the form object from the php hook
        return $form;
    }


    function merge_tag_gform_pre_render_filter($form){
        foreach($form['fields'] as &$field) {
            $field['defaultValue'] = self::replace_merge_tag($field['defaultValue']);
        }
        return $form;
    }

    static function get_munchkin_cookie() {
        return isset($_COOKIE['_mkto_trk']) ? $_COOKIE['_mkto_trk'] : NULL;
    }

    /**
     * If a form has a parameter named "munchkin", fill in the data with the cookie data.
     *
     * Looks for a field with the inputName "munchkin", then fills it with $_COOKIE['_mkto_trk'];
     *
     * @param  array $lead GF Lead
     * @param  array $form GF Form
     * @return array       modified $lead
     */
    function gform_entry_post_save($lead, $form) {

        if(!self::get_setting('fill_munchkin')) { return $lead; }

        // get all HTML fields on the current page
        foreach($form['fields'] as &$field) {
            if(trim(rtrim(rgar($field, 'inputName'))) === 'munchkin') {
                $lead[$field['id']] = self::get_munchkin_cookie();
            }
        }

        // Replace the variables in the content, since the stupid `gform_replace_merge_tags` won't do it.
        foreach($lead as &$input) {
            $input = self::replace_merge_tag($input, $form, $lead, false, false);
        }

        return $lead;
    }

    public static function is_gravity_forms_installed($asd = '', $echo = true) {
        global $pagenow, $page; $message = '';

        $installed = 0;
        $name = self::$name;
        if(!class_exists('RGForms')) {
            if(file_exists(WP_PLUGIN_DIR.'/gravityforms/gravityforms.php')) {
                $installed = 1;
                $message .= __(sprintf('%sGravity Forms is installed but not active. %sActivate Gravity Forms%s to use the %s plugin.%s', '<p>', '<strong><a href="'.wp_nonce_url(admin_url('plugins.php?action=activate&plugin=gravityforms/gravityforms.php'), 'activate-plugin_gravityforms/gravityforms.php').'">', '</a></strong>', $name,'</p>'), 'gravity-forms-marketo');
            } else {
                $message .= <<<EOD
<p><a href="http://katz.si/gravityforms?con=banner" title="Gravity Forms Contact Form Plugin for WordPress"><img src="http://gravityforms.s3.amazonaws.com/banners/728x90.gif" alt="Gravity Forms Plugin for WordPress" width="728" height="90" style="border:none;" /></a></p>
        <h3><a href="http://katz.si/gravityforms" target="_blank">Gravity Forms</a> is required for the $name</h3>
        <p>You do not have the Gravity Forms plugin installed. <a href="http://katz.si/gravityforms">Get Gravity Forms</a> today.</p>
EOD;
            }

            if(!empty($message) && $echo) {
                echo '<div id="message" class="updated">'.$message.'</div>';
            }
        } else {
            return true;
        }
        return $installed;
    }

    public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("%sGravity Forms%s is required. %sPurchase it today!%s"), "<a href='http://katz.si/gravityforms'>", "</a>", "<a href='http://katz.si/gravityforms'>", "</a>");
            self::display_plugin_message($message, true);
        }
    }

    public static function display_plugin_message($message, $is_error = false){
        $style = '';
        if($is_error)
            $style = 'style="background-color: #ffebe8;"';

        echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFMarketoData::get_feed($id);
        GFMarketoData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //--------------   Automatic upgrade ---------------------------------------------------

    function settings_link( $links, $file ) {
        static $this_plugin;
        if( ! $this_plugin ) $this_plugin = plugin_basename(__FILE__);
        if ( $file == $this_plugin ) {
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_marketo' ) . '" title="' . __('Select the Gravity Form you would like to integrate with Marketo. Contacts generated by this form will be automatically added to your Marketo account.', 'gravity-forms-marketo') . '">' . __('Feeds', 'gravity-forms-marketo') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_settings&addon=Marketo' ) . '" title="' . __('Configure your Marketo settings.', 'gravity-forms-marketo') . '">' . __('Settings', 'gravity-forms-marketo') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
        }
        return $links;
    }


    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_marketo_page(){
        global $plugin_page; $current_page = '';
        $marketo_pages = array("gf_marketo");

        if(isset($_GET['page'])) {
            $current_page = trim(strtolower($_GET["page"]));
        }

        return (in_array($plugin_page, $marketo_pages) || in_array($current_page, $marketo_pages));
    }


    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_site_option("gf_marketo_version") != self::$version)
            GFMarketoData::update_table();

        update_site_option("gf_marketo_version", self::$version);
    }

    static function setup_tooltips() {
        //loading Gravity Forms tooltips
        require_once(GFCommon::get_base_path() . "/tooltips.php");
        add_action("admin_print_scripts", 'print_tooltip_scripts');
        add_filter('gform_tooltips', array('GFMarketo', 'tooltips'));
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $marketo_tooltips = array(
            "marketo_contact_list" => "<h6>" . __("Marketo List", "gravity-forms-marketo") . "</h6>" . __("Select the Marketo list you would like to add your contacts to.", "gravity-forms-marketo"),
            "marketo_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-marketo") . "</h6>" . __("Select the Gravity Form you would like to integrate with Marketo. Contacts generated by this form will be automatically added to your Marketo account.", "gravity-forms-marketo"),
            "marketo_map_fields" => "<h6>" . __("Map Fields", "gravity-forms-marketo") . "</h6>" . __("Associate your Marketo attributes to the appropriate Gravity Form fields by selecting.", "gravity-forms-marketo"),
            "marketo_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-marketo") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to Marketo when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-marketo"),
            "marketo_tag" => "<h6>" . __("Entry Tags", "gravity-forms-marketo") . "</h6>" . __("Add these tags to every entry (in addition to any conditionally added tags below).", "gravity-forms-marketo"),
            "marketo_tag_optin_condition" => "<h6>" . __("Conditionally Added Tags", "gravity-forms-marketo") . "</h6>" . __("Tags will be added to the entry when the conditions specified are met. Does not override the 'Entry Tags' setting above (which are applied to all entries).", "gravity-forms-marketo"),

        );
        return array_merge($tooltips, $marketo_tooltips);
    }

    //Creates Marketo left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_marketo");
        if(!empty($permission))
            $menus[] = array("name" => "gf_marketo", "label" => __("Marketo", "gravity-forms-marketo"), "callback" =>  array("GFMarketo", "marketo_page"), "permission" => $permission);

        return $menus;
    }

    public static function is_debug() {
        if(is_null(self::$is_debug)) {
            self::$is_debug = self::get_setting('debug') && current_user_can('manage_options') && !is_admin();
        }
        return self::$is_debug;
    }

    static public function get_setting($key) {
        $settings = self::get_settings();
        return isset($settings[$key]) ? (empty($settings[$key]) ? false : $settings[$key]) : false;
    }

    static public function get_settings() {
        $settings = get_site_option("gf_marketo_settings");
        if(!empty($settings)) {
            self::$settings = $settings;
        } else {
            $settings = self::$settings;
        }
        return $settings;
    }

    public static function settings_page(){

        if(isset($_POST["uninstall"])){
            check_admin_referer("uninstall", "gf_marketo_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms Marketo Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-marketo")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_marketo_submit"])){
            check_admin_referer("update", "gf_marketo_update");
            $settings = array(
                "endpoint" => stripslashes($_POST["gf_marketo_endpoint"]),
                "user_id" => stripslashes($_POST["gf_marketo_user_id"]),
                "encryption_key" => stripslashes($_POST["gf_marketo_encryption_key"]),
                "subdomain" => stripslashes($_POST["gf_marketo_subdomain"]),
                "sync_type" => stripslashes($_POST["gf_marketo_sync_type"]),
                "add_munchkin_js" => isset($_POST["gf_marketo_add_munchkin_js"]),
                "fill_munchkin" => isset($_POST["gf_marketo_fill_munchkin"]),
                "debug" => isset($_POST["gf_marketo_debug"]),
            );
            update_site_option("gf_marketo_settings", $settings);
        }
        else{
            $settings = self::get_settings();
        }

?>
    <img alt="<?php _e("Marketo Logo", "gravity-forms-marketo") ?>" src="<?php echo self::get_base_url()?>/images/marketo-logo.jpg" style="margin:0 0 15px 0; display:block;" width="161" height="70" />
<?php
        if(!get_site_option( 'gf_marketo_settings' )) {
            include_once(plugin_dir_path(__FILE__).'register.php');
            flush();
        }

        $soap = self::check_soap();
        if(!$soap) {
?>
        <h2><?php _e('SOAP Required', 'gravity-forms-marketo'); ?></h2>
        <p style="font-size:1.2em"><?php _e('This plugin requires your server to have SOAP installed and enabled. Contact your web host to have them enable SOAP for your account.'); ?></p>
<?php
            return;
        }

        $valid = self::test_api(true);

        $get_timezone = wp_get_timezone_string();
?>
        <style type="text/css">ol li, li.ol-decimal { list-style: decimal outside; }</style>
        <form method="post" action="<?php echo add_query_arg(array('settings-updated' => true), remove_query_arg(array('refresh', 'retrieveListNames', '_wpnonce'))); ?>">

            <?php wp_nonce_field("update", "gf_marketo_update") ?>

            <h2><?php _e("Marketo Account Information", "gravity-forms-marketo") ?></h2>
            <?php
            if($get_timezone === 'UTC') {
                echo '<div class="updated inline">';
                printf(wpautop(__('Your website timezone is UTC. This may be because your timezone is not compatible with this plugin. Please check your %sWordPress Timezone settings%s and confirm the settings are accurate. If possible, use the location setting instead of the UTC offset setting.', 'gravity-forms-marketo')), '<a href="">', '</a>');
                echo '</div>';
            }
            ?>
            <table class="form-table" style="clear:none; width:auto;">
                <tr>
                    <th scope="row"><label for="gf_marketo_endpoint"><?php _e("Marketo SOAP Endpoint URL", "gravity-forms-marketo"); ?></label> </th>
                    <td><input type="text" id="gf_marketo_endpoint" class="text code" size="50" name="gf_marketo_endpoint" placeholder="https://example.mktoapi.com/soap/mktows/2_1" value="<?php esc_attr_e(@$settings["endpoint"]); ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_marketo_user_id"><?php _e("Marketo User ID <span class='howto'>Access Key</span>", "gravity-forms-marketo"); ?></label> </th>
                    <td><input type="text" id="gf_marketo_user_id" class="text code" size="50"  name="gf_marketo_user_id" placeholder="exampleusername_1234123456123A1B2CD34" value="<?php esc_attr_e(@$settings["user_id"]); ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_marketo_encryption_key"><?php _e("Marketo Encryption Key <span class='howto'>Secret Key</span>", "gravity-forms-marketo"); ?></label> </th>
                    <td><input type="text" id="gf_marketo_encryption_key" class="text code" size="50"  name="gf_marketo_encryption_key" placeholder="19406578945682347776244128566478864311865A48" value="<?php esc_attr_e(@$settings["encryption_key"]); ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e("Lead Identifier", "gravity-forms-marketo"); ?></label> </th>
                    <td>
                        <label for="gf_marketo_sync_type_munchkin"><input type="radio" id="gf_marketo_sync_type_munchkin" class="radio" name="gf_marketo_sync_type" value="munchkin" <?php checked(@$settings["sync_type"] !== 'email', true); ?>" /> <?php _e('Munchkin Cookie', 'gravity-forms-marketo'); ?></label>
                        <label for="gf_marketo_sync_type_email" style="padding-left:1em;"><input type="radio" id="gf_marketo_sync_type_email" class="radio" name="gf_marketo_sync_type"  value="email" <?php checked(@$settings["sync_type"] === 'email', true); ?> /> <?php _e('Email Address', 'gravity-forms-marketo'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_marketo_subdomain"><?php _e("Marketo Subdomain", "gravity-forms-marketo"); ?></label> </th>
                    <td><input type="text" id="gf_marketo_subdomain" class="text code" size="50"  name="gf_marketo_subdomain" placeholder="app-ab12" value="<?php esc_attr_e(@$settings["subdomain"]); ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_marketo_add_munchkin_js"><?php _e("Add Munchkin Javascript", "gravity-forms-marketo"); ?></label> </th>
                    <td><input type="checkbox" id="gf_marketo_add_munchkin_js" class="checkbox" name="gf_marketo_add_munchkin_js" <?php checked(!empty($settings["add_munchkin_js"])); ?> /> <span class="howto"><?php _e('Add the Munchkin tracking javascript to each page of the website?', 'gravity-forms-marketo'); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_marketo_fill_munchkin"><?php _e("Fill Munchkin Data", "gravity-forms-marketo"); ?></label></th>
                    <td>
                        <input type="checkbox" id="gf_marketo_fill_munchkin" class="checkbox" name="gf_marketo_fill_munchkin" <?php checked(!empty($settings["fill_munchkin"])); ?> /> <label for="gf_marketo_fill_munchkin" class="description block"><?php _e('Allow populating fields with Munchkin tracking data.', 'gravity-forms-marketo'); ?></label>
                        <div class="howto"><?php printf(__('To implement, either: %sSet the input Default Value to %s{munchkin}%s, or%sSet the "Input" > "Advanced" > "Allow Field to be Populated Dynamically" > "Parameter Name" to %smunchkin%s.%sIt is recommended to make fields with Munchkin data "Admin Only"%s.' , 'gravity-forms-marketo'), '<ol class="ol-decimal"><li>', '<code>', '</code>', '</li><li>', '<code>', '</code>', '</li></ol> <em>', '</em>'); ?></div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_marketo_debug"><?php _e("Debug Form Submissions for Administrators", "gravity-forms-marketo"); ?></label> </th>
                    <td><input type="checkbox" id="gf_marketo_debug" class="checkbox" name="gf_marketo_debug" <?php checked(!empty($settings["debug"])); ?> /> <span class="howto"><?php _e('Helpful debugging data is shown to logged-in users who have capability to manage plugin options.', 'gravity-forms-marketo'); ?></span></td>
                </tr>
                <tr>
                    <td colspan="2" >
                    <input type="submit" name="gf_marketo_submit" class="button-primary button button-large" value="<?php _e("Save Settings", "gravity-forms-marketo") ?>" />
                    </td>
                </tr>

            </table>
        <form action="" method="post">
            <?php
            flush();
            wp_nonce_field("uninstall", "gf_marketo_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_marketo_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall Marketo Add-On", "gravity-forms-marketo") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL Marketo Feeds.", "gravity-forms-marketo") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall Marketo Add-On", "gravity-forms-marketo") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL Marketo Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-marketo") . '\');"/>';
                    echo apply_filters("gform_marketo_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    public static function marketo_page(){
        $view = isset($_GET["view"]) ? $_GET["view"] : '';
        if($view == "edit")
            self::edit_page($_GET["id"]);
        else
            self::list_page();
    }

    //Displays the Marketo feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("The Marketo Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-marketo"));
        }

        if(isset($_POST["action"]) && $_POST["action"] == "delete"){
            check_admin_referer("list_action", "gf_marketo_list");

            $id = absint($_POST["action_argument"]);
            GFMarketoData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-marketo") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_marketo_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFMarketoData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-marketo") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("Marketo Feeds", "gravity-forms-marketo") ?>" src="<?php echo self::get_base_url()?>/images/marketo-logo.jpg" style="margin:15px 7px 0 0; display:block;" width="161" height="70" />
            <h2><?php _e("Marketo Feeds", "gravity-forms-marketo"); ?>
            <a class="button add-new-h2" href="admin.php?page=gf_marketo&amp;view=edit&amp;id=0"><?php _e("Add New", "gravity-forms-marketo") ?></a>
            </h2>

            <div class="updated" id="message" style="margin-top:20px;">
                <p><?php printf(__('Do you like this free plugin? %sPlease review it on WordPress.org%s!', 'gravity-forms-marketo'), '<a href="http://katz.si/gfratemarketo">', '</a>'); ?></p>
            </div>

            <div class="clear"></div>

            <ul class="subsubsub" style="margin-top:0;">
                <li><a href="<?php echo admin_url('admin.php?page=gf_settings&addon=Marketo'); ?>"><?php _e('Marketo Settings', 'gravity-forms-marketo'); ?></a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=gf_marketo'); ?>" class="current"><?php _e('Marketo Feeds', 'gravity-forms-marketo'); ?></a></li>
            </ul>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_marketo_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px; 0">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-marketo") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-marketo") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-marketo") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-marketo") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-marketo") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-marketo") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-marketo") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-marketo") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $settings = GFMarketoData::get_feeds();
                        if(is_array($settings) && !empty($settings)){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-marketo") : __("Inactive", "gravity-forms-marketo");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-marketo") : __("Inactive", "gravity-forms-marketo");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_marketo&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-marketo") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="Edit this setting" href="admin.php?page=gf_marketo&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-marketo") ?>"><?php _e("Edit", "gravity-forms-marketo") ?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Delete", "gravity-forms-marketo") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-marketo") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-marketo") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-marketo")?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Edit Form", "gravity-forms-marketo") ?>" href="<?php echo add_query_arg(array('page' => 'gf_edit_forms', 'id' => $setting['form_id']), admin_url('admin.php')); ?>"><?php _e("Edit Form", "gravity-forms-marketo")?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Preview Form", "gravity-forms-marketo") ?>" href="<?php echo add_query_arg(array('gf_page' => 'preview', 'id' => $setting['form_id']), site_url()); ?>"><?php _e("Preview Form", "gravity-forms-marketo")?></a>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else {
                            $valid = self::test_api();
                            if(!empty($valid)){
                                ?>
                                <tr>
                                    <td colspan="4" style="padding:20px;">
                                        <?php _e(sprintf("You don't have any Marketo feeds configured. Let's go %screate one%s!", '<a href="'.admin_url('admin.php?page=gf_marketo&view=edit&id=0').'">', "</a>"), "gravity-forms-marketo"); ?>
                                    </td>
                                </tr>
                                <?php
                            } else{
                                ?>
                                <tr>
                                    <td colspan="4" style="padding:20px;">
                                        <?php _e(sprintf("To get started, please configure your %sMarketo Settings%s.", '<a href="admin.php?page=gf_settings&addon=Marketo">', "</a>"), "gravity-forms-marketo"); ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script>
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-marketo") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-marketo") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-marketo") ?>').attr('alt', '<?php _e("Active", "gravity-forms-marketo") ?>');
                }

                var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-marketo" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
    }

    /**
     * Setup the Marketo API client using the plugin settings
     * @return boolean| [description]
     */
    public static function get_api($return_exceptions = false){

        // Setup the client
        $accessKey = self::get_setting('user_id');
        $secretKey = self::get_setting('encryption_key');
        $soapEndPoint = self::get_setting('endpoint');
        $debug = self::get_setting('debug');

        if(!self::check_soap()) { return false; }

        if(!empty($soapEndPoint)) {
            $parsed = @parse_url($soapEndPoint);
            if(isset($parsed['host'])) {
                $soapHost = $parsed['host'];
            }
        }

        if(empty($accessKey) || empty($secretKey) || empty($soapEndPoint)) {
            return false;
        }

        if(!class_exists("MarketoClient"))
            require_once(plugin_dir_path(__FILE__)."api/Marketo_SOAP_PHP_Client.php");

        try {
            $client = new MarketoClient($accessKey, $secretKey, $soapEndPoint, $debug);
            $client->setTimeZone(wp_get_timezone_string());
            if(defined('DOING_AJAX') || !current_user_can('manage_options') || is_admin()) {
                $client->setDebug(false);
            }
            if(current_user_can( 'manage_options' ) && isset($_GET['debug'])) {
                $client->setDebug(true);
            }
        } catch(Exception $e) {
            if($return_exceptions) { return $e; }
            return false;
        }

        return $client;
    }

    static function check_soap() {
        return(extension_loaded('soap') || class_exists("SOAPClient"));
    }

    private static function test_api($echo = false) {
        $works = true; $message = ''; $class = '';
        $endpoint = self::get_setting('endpoint');
        $user_id = self::get_setting('user_id');
        $encryption_key = self::get_setting('encryption_key');

        if(empty($endpoint) && empty($encryption_key)) {
            $works = false;
       } elseif(empty($encryption_key)) {
            $message = wpautop(__('Your Encryption Key is required, please <label for="gf_marketo_encryption_key"><a>enter your Encryption Key below</a></label>.', 'gravity-forms-marketo'));
            $works = false;
        } elseif(empty($user_id)) {
            $message = wpautop(sprintf(__('Your User ID is required, please %senter your User ID below%s.', 'gravity-forms-marketo'), '<label for="gf_marketo_user_id"><a>', '</a></label>'));
            $works = false;
        } else {
            $api = self::get_api(true);

            try {
                $campaigns = $api->getCampaignsForSource();
            } catch(Exception $e) {
                $message = $api->getMessage();
                $works = false;
            }

            if(!empty($api) && is_a($api, 'Exception')) {
                $message = wpautop(str_replace('[', __('Error key: [', 'gravity-forms-marketo'), str_replace(']', ']<br />Error message: ', $api->getMessage())));
                $works = false;
            } else if(is_wp_error($campaigns)) {
                $message = sprintf(__('There was an error: %s', 'gravity-forms-marketo'), $campaigns->get_error_message());
                $works = false;
            } else {
                $message = __('Your configuration appears to be working.', 'gravity-forms-marketo');
                $works = true;
            }

        }

        if($message && $echo && !defined('DOING_AJAX')) {
            $class = empty($class) ? ($works ? "updated inline" : "error inline") : $class;

            echo sprintf('<div id="message" class="%s" style="display:block!important">%s</div>', $class, wpautop($message));
        }

        return $works;
    }

    function r($content, $die = false) {
        echo '<pre>'.print_r($content, true).'</pre>';
        if($die) { die(); }
    }

    private static function edit_page(){
        if(isset($_REQUEST['cache'])) {
            delete_site_transient('gf_marketo_default_fields');
            delete_site_transient('gf_marketo_custom_fields');
        }
        ?>
        <style type="text/css">
            label span.howto { cursor: default; }
            .marketo_col_heading, .marketo_tag_optin_condition_fields { padding-bottom: .5em; border-bottom: 1px solid #ccc; }
            .marketo_col_heading { font-weight:bold; width:50%; }
            .marketo_tag_optin_condition_fields { margin-bottom: .5em; }
            #marketo_field_list table, #marketo_tag_optin table { width: 500px; border-collapse: collapse; margin-top: 1em; }
            .marketo_field_cell {padding: 6px 17px 0 0; margin-right:15px; vertical-align: text-top; font-weight: normal;}
            ul.marketo_checkboxes { max-height: 120px; overflow-y: auto;}
            ul.marketo_map_field_groupId_checkboxes { max-height: 300px; }
            .gfield_required{color:red;}
            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px; padding-right: 20px;}
            #marketo_field_list .left_header { margin-top: 1em; }
            .margin_vertical_10{margin: 20px 0;}
            #gf_marketo_list { margin-left:220px; padding-top: 1px }
            #marketo_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
        </style>
        <script>
            var form = Array();
        </script>
        <div class="wrap">
            <img alt="<?php _e("Marketo Feeds", "gravity-forms-marketo") ?>" src="<?php echo self::get_base_url()?>/images/marketo-logo.jpg" style="display:block; margin:15px 7px 0 0;" width="161" height="70"/>
            <h2><?php _e("Marketo Feeds", "gravity-forms-marketo"); ?></h2>
            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=gf_settings&addon=Marketo'); ?>"><?php _e('Marketo Settings', 'gravity-forms-marketo'); ?></a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=gf_marketo'); ?>"><?php _e('Marketo Feeds', 'gravity-forms-marketo'); ?></a></li>
            </ul>
        <div class="clear"></div>
        <?php
        //getting Marketo API

        $api = self::get_api();

        //ensures valid credentials were entered in the settings page
        if(($api === false) || is_string($api)) {
            ?>
            <div class="error" id="message" style="margin-top:20px;"><?php echo wpautop(sprintf(__("We are unable to login to Marketo with the provided username and API key. Please make sure they are valid in the %sSettings Page%s", "gravity-forms-marketo"), "<a href='?page=gf_settings&addon=Marketo'>", "</a>")); ?></div>
            <?php
            return;
        }

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["marketo_setting_id"]) ? $_POST["marketo_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFMarketoData::get_feed($id);


        //getting merge vars
        $merge_vars = array();

        //updating meta information
        if(isset($_POST["gf_marketo_submit"])){
            $objectType = $list_names = array();

            list($list_id, $list_name) = explode("|:|", stripslashes($_POST["gf_marketo_list"]));
            $config["meta"]["contact_list_id"] = $list_id;
            $config["meta"]["contact_list_name"] = $list_name;
            $config["form_id"] = absint($_POST["gf_marketo_form"]);

            $is_valid = true;

            $merge_vars = self::get_fields();

            $field_map = array();
            foreach($merge_vars as $key => $var){
                $field_name = "marketo_map_field_" . $var['tag'];
                if(isset($_POST[$field_name])) {
                    if(is_array($_POST[$field_name])) {
                        foreach($_POST[$field_name] as $k => $v) {
                            $_POST[$field_name][$k] = stripslashes($v);
                        }
                        $mapped_field = $_POST[$field_name];
                    } else {
                        $mapped_field = stripslashes($_POST[$field_name]);
                    }
                }
                if(!empty($mapped_field)){
                    $field_map[$var['tag']] = $mapped_field;
                }
                else{
                    unset($field_map[$var['tag']]);
                    if(!empty($var['req'])) {
                        $is_valid = false;
                    }
                }
                unset($_POST["{$field_name}"]);
            }

            $field_map['CustomFields'] = array();

            if(isset($_POST['marketo_custom_field'])) {
                foreach((array)$_POST['marketo_custom_field'] as $key => $value) {
                    if(!empty($key)) {
                        $field_map['CustomFields'][esc_attr($key)] = stripslashes($value);
                    }
                }
            }

            $config["meta"]["field_map"] = $field_map;
            $config["meta"]["optin_enabled"] = !empty($_POST["marketo_optin_enable"]) ? true : false;
            $config["meta"]["optin_field_id"] = $config["meta"]["optin_enabled"] ? isset($_POST["marketo_optin_field_id"]) ? @$_POST["marketo_optin_field_id"] : '' : "";
            $config["meta"]["optin_operator"] = $config["meta"]["optin_enabled"] ? isset($_POST["marketo_optin_operator"]) ? @$_POST["marketo_optin_operator"] : '' : "";
            $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? @$_POST["marketo_optin_value"] : "";

            $config["meta"]["tag_optin_enabled"] = !empty($_POST["marketo_tag_optin_enable"]) ? true : false;
            $config["meta"]["tag_optin_field_id"] = !empty($config["meta"]["tag_optin_enabled"]) ? isset($_POST["marketo_tag_optin_field_id"]) ? @$_POST["marketo_tag_optin_field_id"] : '' : "";
            $config["meta"]["tag_optin_operator"] = !empty($config["meta"]["tag_optin_enabled"]) ? isset($_POST["marketo_tag_optin_operator"]) ? @$_POST["marketo_tag_optin_operator"] : '' : "";
            $config["meta"]["tag_optin_tags"] = !empty($config["meta"]["tag_optin_enabled"]) ? @$_POST["tag_optin_tags"] : "";
            $config["meta"]["tag_optin_value"] = !empty($config["meta"]["tag_optin_enabled"]) ? @$_POST["marketo_tag_optin_value"] : "";

            if($is_valid){
                $id = GFMarketoData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div id="message" class="updated fade" style="margin-top:10px;"><p><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-marketo"), "<a href='?page=gf_marketo'>", "</a>") ?></p>
                    <input type="hidden" name="marketo_setting_id" value="<?php echo $id ?>"/>
                </div>
                <?php
            }
            else{
                ?>
                <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-marketo") ?></div>
                <?php
            }

        }

        self::setup_tooltips();

?>
        <form method="post" action="<?php echo remove_query_arg('refresh'); ?>">
            <input type="hidden" name="marketo_setting_id" value="<?php echo $id ?>"/>

            <div class="margin_vertical_10">
                <label for="gf_marketo_list" class="left_header"><?php _e("Marketo Campaign", "gravity-forms-marketo"); ?> <?php gform_tooltip("marketo_contact_list") ?></label>
                <?php

                //getting all contact lists
                $campaigns = $api->getCampaignsForSource();

                if (empty($campaigns)){
                    if(is_array($campaigns)) {
                        echo '<img src="'.plugins_url('images/campaigntrigger.png', __FILE__).'" alt="Campaign is Requested Trigger" width="562" height="138" />';
                        echo _e('<span class="howto" style="max-width:500px; margin-left:230px"><strong>No lists were loaded.</strong> Campaigns are only available to the API if they are active campaigns and have a "Campaign is Requested" trigger configured where the Source is "Web Service&nbsp;API".</span>', 'gravity-forms-marketo');
                    } else {
                        echo __("Could not load Marketo contact lists.", "gravity-forms-marketo");
                    }
                    ?><script>
                    jQuery(document).ready(function() {
                        jQuery("#marketo_field_group, #marketo_form_container").slideUp();
                    });
                    </script><?php
                }
                else{ ?>
                    <select id="gf_marketo_list" name="gf_marketo_list" onchange="SelectList(jQuery(this).val());">
                        <option value=""><?php _e("Select a Marketo Campaign", "gravity-forms-marketo"); ?></option>
                    <?php
                    foreach ($campaigns as $campaignname => $campaignid){
                        $selected = $campaignid == $config["meta"]["contact_list_id"] ? "selected='selected'" : "";
                        ?>
                        <option value="<?php echo esc_html($campaignid) . "|:|" . esc_html($campaignname) ?>" <?php echo $selected ?>><?php echo esc_html($campaignname) ?></option>
                        <?php
                    }
                    ?>
                  </select>
                <?php
                }
                ?>
            </div>

            <div id="marketo_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["contact_list_id"]) ? "style='display:none;'" : "" ?>>

                <h2><?php _e('1. Select the form to tap into.', "gravity-forms-marketo"); ?></h2>
                <?php
                $forms = RGFormsModel::get_forms();

                if(isset($config["form_id"])) {
                    foreach($forms as $form) {
                        if($form->id == $config["form_id"]) {
                            echo '<h3 style="margin:0; padding:0 0 1em 1.75em; font-weight:normal;">'.sprintf(__('(Currently linked with %s)', "gravity-forms-marketo"), $form->title).'</h3>';
                        }
                    }
                }

                ?>
                <label for="gf_marketo_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-marketo"); ?> <?php gform_tooltip("marketo_gravity_form") ?></label>

                <select id="gf_marketo_form" name="gf_marketo_form" onchange="SelectForm(jQuery('#gf_marketo_list').val(), jQuery(this).val());">
                <option value=""><?php _e("Select a form", "gravity-forms-marketo"); ?> </option>
                <?php

                foreach($forms as $form){
                    $selected = absint($form->id) == $config["form_id"] ? "selected='selected'" : "";
                    ?>
                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                    <?php
                }
                ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFMarketo::get_base_url() ?>/images/loading.gif" id="marketo_wait" style="display: none;"/>
            </div>

            <div class="clear"></div>
            <div id="marketo_field_group" valign="top" <?php echo empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                <div id="marketo_field_container" valign="top" class="margin_vertical_10" >
                    <h2><?php _e('2. Map form fields to Marketo fields.', "gravity-forms-marketo"); ?></h2>
                    <h3 class="description"><?php _e('About field mapping:', "gravity-forms-marketo"); ?></h2>
                    <label for="marketo_fields" class="left_header"><?php _e("Standard Fields", "gravity-forms-marketo"); ?> <?php gform_tooltip("marketo_map_fields") ?></label>
                    <div id="marketo_field_list">
                    <?php

                    if(!empty($config["form_id"])){

                        //getting list of all Marketo merge variables for the selected contact list
                        if(empty($merge_vars))
                            $merge_vars = self::get_fields($config['meta']['contact_list_name']);

                        //getting field map UI
                        echo self::get_field_mapping($config, $config["form_id"], $merge_vars);

                        //getting list of selection fields to be used by the optin
                        $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                        $selection_fields = GFCommon::get_selection_fields($form_meta, $config["meta"]["optin_field_id"]);
                        $tag_selection_fields = true;
                    } else {
                        $selection_fields = $tag_selection_fields = false;
                    }

                    ?>
                    </div>
                    <div class="clear"></div>
                </div>


                <div id="marketo_optin_container" valign="top" class="margin_vertical_10">
                    <label for="marketo_optin" class="left_header"><?php _e("Opt-In Condition", "gravity-forms-marketo"); ?> <?php gform_tooltip("marketo_optin_condition") ?></label>
                    <div id="marketo_optin">
                        <table>
                            <tr>
                                <td>
                                    <input type="checkbox" id="marketo_optin_enable" name="marketo_optin_enable" value="1" onclick="if(this.checked){jQuery('#marketo_optin_condition_field_container').show('slow'); SetOptinCondition();} else{jQuery('#marketo_optin_condition_field_container').hide('slow');}" <?php echo !empty($config["meta"]["optin_enabled"]) ? "checked='checked'" : ""?>/>
                                    <label for="marketo_optin_enable"><?php _e("Enable", "gravity-forms-marketo"); ?></label>
                                </td>
                            </tr>
                            <tr class="gfield_list_row" data-fieldid="optin">
                                <td>
                                    <div id="marketo_optin_condition_field_container" <?php echo empty($config["meta"]["optin_enabled"]) ? "style='display:none'" : ""?>>
                                        <div class="marketo_optin_condition_fields" <?php echo empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("Export to Marketo if ", "gravity-forms-marketo") ?>

                                            <select id="marketo_optin_field_id" name="marketo_optin_field_id" class='optin_select' onchange='SetOptinCondition();'><?php echo $selection_fields ?></select>
                                            <select id="marketo_optin_operator" name="marketo_optin_operator" />
                                                <option value="is" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "is") ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-marketo") ?></option>
                                                <option value="isnot" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "isnot") ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-marketo") ?></option>
                                            </select>
                                            <select id="marketo_optin_value" name="marketo_optin_value" class='optin_select optin_value'>
                                            </select>

                                        </div>
                                        <div class="marketo_optin_condition_message" <?php echo !empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("To create an Opt-In condition, your form must have a drop down, checkbox or multiple choice field.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <script>
                        jQuery(document).ready(function($){
                            $('.marketo_custom_field_name').on('focus ready change', function() {
                                $('td select', $(this).parents('tr')).attr('name', 'marketo_custom_field['+$(this).val()+']').attr('id', 'marketo_custom_field_'+$(this).val().replace(/\W/, '-'));
                            });
                        });
                        <?php
                        if(!empty($config["form_id"])){
                            ?>
                            //creating Javascript form object
                            form = <?php echo GFCommon::json_encode($form_meta)?> ;

                            function SetOptinCondition() {
                                var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
                                var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
                                jQuery("#marketo_optin_value").html(GetFieldValues(jQuery('#marketo_optin_field_id').val(), "", 50));
                            }
                            //initializing drop downs
                            jQuery(document).ready(function(){

                                SetOptinCondition();

                            });
                        <?php
                        } else {
                        ?>
                        function SetOptinCondition() {
                            SetOptin('','', 'optin');
                        }
                        <?php
                        }
                        ?>
                    </script>
                </div>

                <div id="marketo_submit_container" class="margin_vertical_10">
                    <input type="submit" name="gf_marketo_submit" value="<?php echo empty($id) ? __("Save Feed", "gravity-forms-marketo") : __("Update Feed", "gravity-forms-marketo"); ?>" class="button-primary"/>
                </div>
            </div>
        </form>
        </div>

        <script>
    jQuery(document).ready(function($) {

    <?php if(isset($_REQUEST['id'])) { ?>
        $('#marketo_field_list').live('load', function() {
            $('.marketo_field_cell select').each(function() {
                var $select = $(this);
                if($().prop) {
                    var label = $.trim($('label[for='+$(this).prop('name')+']').text());
                } else {
                    var label = $.trim($('label[for='+$(this).attr('name')+']').text());
                }
                label = label.replace(' *', '');

                if($select.val() === '') {
                    $('option', $select).each(function() {

                        if($(this).text() === label) {
                            if($().prop) {
                                $(this).prop('selected', true);
                            } else {
                                $(this).attr('selected', true);
                            }
                        }
                    });
                }
            });
        });
    <?php } ?>
    });
        </script>
        <script>


            jQuery(document).ready(function($) {
                <?php if(empty($config["form_id"])){ ?>
                SelectForm($('#gf_marketo_form').val());
                <?php } ?>
            });

            function SelectList(listId){
                if(listId){
                    jQuery("#marketo_form_container").slideDown();
                    jQuery("#gf_marketo_form").val("");
                }
                else{
                    jQuery("#marketo_form_container").slideUp();
                    EndSelectForm("");
                }
            }

            function SelectForm(listId, formId){

                // If no form is selected, just hide everything.
                if(!formId){
                    jQuery("#marketo_field_group").slideUp();
                    return;
                }

                jQuery("#marketo_wait").show();
                jQuery("#marketo_field_group").slideUp();

                var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_marketo_form" );
                mysack.setVar( "gf_select_marketo_form", "<?php echo wp_create_nonce("gf_select_marketo_form") ?>" );
                mysack.setVar( "list_id", listId);
                mysack.setVar( "form_id", formId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#marketo_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-marketo") ?>' )};
                mysack.runAJAX();
                return true;
            }

            function SetOptin(selectedField, selectedValue, tag){

                //load form fields
                jQuery(".optin_select[id*=field_id]").each(function() {

                    var optinConditionField = jQuery(this).val();
                    var $table = jQuery(this).parents('tr.gfield_list_row');
                    var fieldID = $table.data('fieldid');
                    var values = '';
                    jQuery(this).addClass('processed');

                    // If the conditional is set up
                    if(optinConditionField){
                        jQuery(".marketo_optin_condition_message", $table).hide();
                        jQuery(".marketo_optin_condition_fields", $table).show();

                        if(tag == fieldID) {
                            // Gather the form fields that qualify for conditional
                            jQuery(this).html(GetSelectableFields(selectedField, 50));
                            values = GetFieldValues(optinConditionField, selectedValue, 50);
                            jQuery(".optin_value", $table).html(values);
                        }
                    } else{
                        jQuery(this).html(GetSelectableFields(selectedField, 50));
                        jQuery(".marketo_optin_condition_message", $table).show();
                        jQuery(".marketo_optin_condition_fields", $table).hide();
                    }

                });
            }

            function EndSelectForm(fieldList, form_meta){
                //setting global form object
                form = form_meta;

                if(fieldList){

                    SetOptin("","", false);

                    jQuery("#marketo_field_list").html(fieldList);
                    jQuery("#marketo_field_group").slideDown();
                }
                else{
                    jQuery("#marketo_field_group").slideUp();
                    jQuery("#marketo_field_list").html("");
                }
                jQuery('#marketo_field_list').trigger('load');
                jQuery("#marketo_wait").hide();
            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field || !field.choices)
                    return "";

                var isAnySelected = false;

                for(var i=0; i<field.choices.length; i++){
                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                    var isSelected = fieldValue == selectedValue;
                    var selected = isSelected ? "selected='selected'" : "";
                    if(isSelected)
                        isAnySelected = true;

                    str += "<option value='" + fieldValue.replace("'", "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                }

                if(!isAnySelected && selectedValue){
                    str += "<option value='" + selectedValue.replace("'", "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if(inputType == "checkbox" || inputType == "radio" || inputType == "select"){
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

        </script>

        <?php

    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_marketo");
        $wp_roles->add_cap("administrator", "gravityforms_marketo_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_marketo", "gravityforms_marketo_uninstall"));
    }

    public static function disable_marketo(){
        delete_site_option("gf_marketo_settings");
    }

    public static function select_marketo_form(){

        check_ajax_referer("gf_select_marketo_form", "gf_select_marketo_form");
        $form_id =  intval($_REQUEST["form_id"]);
        $setting_id =  0; //intval($_POST["marketo_setting_id"]);

        // Not only test API, but include necessary files.
        $api = self::get_api();
        if(empty($api)) {
            die("EndSelectForm();");
        }

        //getting list of all Marketo merge variables for the selected contact list
        $merge_vars = self::get_fields();

        //getting configuration
        $config = GFMarketoData::get_feed($setting_id);

        //getting field map UI
        $str = self::get_field_mapping($config, $form_id, $merge_vars);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);

        //$fields = $form["fields"];
        die("EndSelectForm('" . str_replace("'", "\'", str_replace(")", "\)", $str)) . "', " . GFCommon::json_encode($form) . ");");
    }

    private static function get_fields() {

        $fields = array(


            "Company",
            "Site",

            'BillingStreet',
            'BillingCity',
            'BillingState',
            'BillingCountry',
            'BillingPostalCode',
            "MainPhone",
            "Website",
            "Industry",
            "SICCode",
            "AnnualRevenue",
            "NumberOfEmployees",
            "CompanyNotes", // ?

            #"ContactCompany",

            #"InferredCompany",
            #"InferredCountry",

            "Salutation",
            "FirstName",
            "MiddleName",
            "LastName",

            "Email",

            "Phone",
                "MobilePhone",
            "Fax",

            "Address",
                "City",
                "State",
                "PostalCode",
                "Country",

            "DateofBirth",

            "Title",
            "Department",
            "Rating",

            "IsLead",
                "LeadPerson",
                "LeadRole",
                "LeadScore",
                "LeadSource",
                "LeadStatus",

            "AcquisitionDate",

            "PersonPrimaryLeadInterest",
            "PersonType",

            "MarketoSocialFacebookPhotoURL", // string
            "MarketoSocialFacebookProfileURL", // string
            "MarketoSocialFacebookReach", // integer
            "MarketoSocialFacebookReferredEnrollments",//integer
            "MarketoSocialFacebookReferredVisits", //integer

            "MarketoSocialTwitterDisplayName",
            "MarketoSocialTwitterPhotoURL",
            "MarketoSocialTwitterProfileURL",
            "MarketoSocialTwitterReach",
            "MarketoSocialTwitterReferredEnrollments",
            "MarketoSocialTwitterReferredVisits",

            "MarketoSocialLinkedInDisplayName",
            "MarketoSocialLinkedInPhotoURL",
            "MarketoSocialLinkedInProfileURL",
            "MarketoSocialLinkedInReach",
            "MarketoSocialLinkedInReferredEnrollments",
            "MarketoSocialLinkedInReferredVisits",

            "MarketoSocialSyndicationId",
            "MarketoSocialGender", // string
            "MarketoSocialLastReferredEnrollment", // datetime
            "MarketoSocialLastReferredVisit", // datetime
            "MarketoSocialLinkedInDisplayName", // string
            "MarketoSocialTotalReferredEnrollments", //integer
            "MarketoSocialTotalReferredVisits", // integer

            "OriginalReferrer",
            "OriginalSearchEngine",
            "OriginalSearchPhrase",
            "OriginalSourceInfo",
            "OriginalSourceType",

            "RegistrationSourceInfo",
            "RegistrationSourceType",

            "DoNotCall", // Boolean
            "DoNotCallReason", // String

            "Unsubscribed", // Boolean
            "UnsubscribedReason", // String
            "AnonymousIP",

        );

        foreach($fields as &$field) {

            $name = str_replace(array('Dateof', 'SICCode'), array('Date of', 'SIC Code'), preg_replace( '/([a-z0-9])([A-Z])/', "$1 $2", $field));
            switch($name) {
                case(preg_match('/Reach$|Total|Enrollments$|Visits$/ism', $name) ? true : false):
                    $name .= '<span class="howto">(Type: Integer)</span>';
                    break;
                case(preg_match('/URL$/ism', $name) ? true : false):
                    $name .= '<span class="howto">(Type: URL)</span>';
                    break;
                case(preg_match('/Phone|Fax/ism', $name) ? true : false):
                    $name .= '<span class="howto">(Type: Phone Number)</span>';
                    break;
            }
            $field = array(
                'name' => $name,
                'tag' => $field,
                'req' => ($field === 'Email')
            );
        }

        return apply_filters('gravity_forms_marketo_fields', $fields);
    }

    private static function get_field_mapping($config = array(), $form_id, $merge_vars){

        $str = $custom = $standard = '';


        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form_id);

        $str = "<table cellpadding='0' cellspacing='0'><thead><tr><th scope='col' class='marketo_col_heading'>" . __("List Fields", "gravity-forms-marketo") . "</th><th scope='col' class='marketo_col_heading'>" . __("Form Fields", "gravity-forms-marketo") . "</th></tr></thead><tbody>";

        foreach($merge_vars as $var){

            $selected_field = (isset($config["meta"]) && isset($config["meta"]["field_map"]) && isset($config["meta"]["field_map"][$var["tag"]])) ? $config["meta"]["field_map"][$var["tag"]] : '';

            $field_list = self::get_mapped_field_list($var["tag"], $selected_field, $form_fields);
            $name = stripslashes( $var["name"] );

            $required = $var["req"] === true ? "<span class='gfield_required' title='This field is required.'>*</span>" : "";
            $error_class = $var["req"] === true && empty($selected_field) && !empty($_POST["gf_marketo_submit"]) ? " feeds_validation_error" : "";
            $field_desc = '';
            $row = "<tr class='$error_class'><th scope='row' class='marketo_field_cell' id='marketo_map_field_{$var['tag']}_th'><label for='marketo_map_field_{$var['tag']}'>" . $name ." $required</label><small class='description' style='display:block'>{$field_desc}</small></th><td class='marketo_field_cell'>" . $field_list . "</td></tr>";

            $str .= $row;

        } // End foreach merge var.

        if(isset($config["meta"]) && isset($config["meta"]["field_map"]) && isset($config["meta"]["field_map"]['CustomFields'])) {
            foreach((array)$config['meta']['field_map']['CustomFields'] as $key => $value) {
                $field_list = self::get_mapped_field_list($key, $value, $form_fields, 'marketo_custom_field['.$key.']');
                $str .= "<tr class='$error_class'><th scope='row' class='marketo_field_cell' id='marketo_map_field_{$key}_th'><input class='marketo_custom_field_name' value='{$key}' /></th><td class='marketo_field_cell'>" . $field_list . "</td></tr>";
            }
        }

        $field_list = self::get_mapped_field_list('', '', $form_fields);
        $i = 1;
        while($i <= 5) {
            $str .= "<tr class='$error_class'><th scope='row' class='marketo_field_cell' id='marketo_map_field_custom_{$i}_th'><input class='marketo_custom_field_name' value='' placeholder='";
            $str .= __('Add a Custom Field', 'market-gravity-forms');
            $str .= "' /></th><td class='marketo_field_cell'>" . $field_list . "</td></tr>";
            $i++;
        }

        $str .= "</tbody></table>";

        return $str;
    }

    private function getNewTag($tag, $used = array()) {
        if(isset($used[$tag])) {
            $i = 1;
            while($i < 1000) {
                if(!isset($used[$tag.'_'.$i])) {
                    return $tag.'_'.$i;
                }
                $i++;
            }
        }
        return $tag;
    }

    public static function get_form_fields($form_id){
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        //Adding default fields
        array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravity-forms-marketo")));
        array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravity-forms-marketo")));
        array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravity-forms-marketo")));
        array_push($form["fields"],array("id" => "munchkin_cookie" , "label" => __("Marketo Munchkin Cookie Data", "gravity-forms-marketo")));

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"]) && $field['type'] !== 'checkbox' && $field['type'] !== 'select'){

                    //If this is an address field, add full name to the list
                    if(RGFormsModel::get_input_type($field) == "address")
                        $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Marketo-Formatted Address" , "gravity-forms-marketo") . ")");

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(empty($field["displayOnly"])){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function get_address($entry, $field_id){
        $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
        $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));

        $address = $street_value;
        $address .= !empty($address) && !empty($street2_value) ? "\n{$street2_value}" : $street2_value;

        return $address;
    }

    public static function get_mapped_field_list($variable_name, $selected_field, $fields, $field_name=''){
        if(empty($field_name)) {
            $field_name = "marketo_map_field_" . $variable_name;
        }
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = $field[1];
            $str .= "<option value='" . $field_id . "' ". selected(($field_id == $selected_field), true, false) . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    public static function get_mapped_field_checkbox($variable_name, $selected_field = array(), $fields, $base_name = 'marketo_map_field_'){
        $field_name_base = $base_name . $variable_name;
        $str = '<ul class="'.$field_name_base.'_checkboxes marketo_checkboxes">';
        foreach($fields as $field){
            $field_name = $field_name_base.$field["tag"];
            $str .= "<li><label>";
            $str .=  "<input name='{$field_name_base}[]' id='{$field_name}' type='checkbox' value='".$field['tag']."'";
            $str .= checked(is_array($selected_field) && in_array($field['tag'], $selected_field), true, false);
            $str .= " /> ".esc_html($field['name'])."</label></li>";
        }
        $str .= '</ul>';
        return $str;
    }

    public static function export($entry, $form){
        //Login to Marketo
        $api = self::get_api();
        if(empty($api)) { return; }

        //loading data class
        require_once(self::get_base_path() . "/data.php");

        //getting all active feeds
        $feeds = GFMarketoData::get_feed_by_form($form["id"], true);
        foreach($feeds as $feed){
            //Always export the user
            self::export_feed($entry, $form, $feed, $api);
        }
    }

    public static function export_feed($entry, $form, $feed, &$api){
        global $current_user;

        $email_field_id = $feed["meta"]["field_map"]["Email"];
        $email = $entry[$email_field_id];

        $merge_vars = array();

        // Handle the CustomFields
        foreach((array)$feed['meta']['field_map']['CustomFields'] as $key => $field_id) {
            $feed['meta']['field_map'][$key] = $field_id;
            unset($feed['meta']['field_map']['CustomFields'][$key]);
        }
        unset($feed['meta']['field_map']['CustomFields']);


        foreach($feed["meta"]["field_map"] as $var_tag => $field_id){

            // If something's mapped to nothing, or nothign is mapped to something, get outta here!
            if(empty($var_tag) || empty($field_id)) { continue; }

            $field = RGFormsModel::get_field($form, $field_id);
            $input_type = RGFormsModel::get_input_type($field);

            if($field_id == intval($field_id) && RGFormsModel::get_input_type($field) == "address") { //handling full address
                $merge_vars[$var_tag] = self::get_address($entry, $field_id);
            } else if($var_tag != "EMAIL") { //ignoring email field as it will be handled separatelly
                $merge_vars[$var_tag] = isset($entry[$field_id]) ? $entry[$field_id] : NULL;
            }
            $merge_vars[$var_tag] = self::clean_utf8($merge_vars[$var_tag]);

            if(preg_match('/^date|date$|LastReferred/ism', $var_tag)) {
                $merge_vars[$var_tag] = $api->getTime($merge_vars[$var_tag]);
            }

        }

        if(self::test_api()) {

            $cookie = self::get_munchkin_cookie();
            $syncType = (!$cookie || self::get_setting('sync_type') === 'email') ? 'EMAIL' : $cookie;
            if(!$syncType) { $syncType = 'EMAIL'; }

            $success = $api->syncLead($syncType, $merge_vars, self::get_munchkin_cookie());

            if(!is_wp_error( $success ) && !empty($success) && !empty($success->result) && is_object($success->result)) {
                $leadId = $success->result->leadId;
            } else {
                $leadId = false;
            }

            /**
             * Add the lead to the specified campaign
             */
            $campaign_id = $feed['meta']['contact_list_id'];
            $campaign_name = $feed['meta']['contact_list_name'];
            if(!empty($campaign_id)) {
                $campaign = $api->requestCampaign($campaign_id, $leadId);
            }

            if(self::is_debug()) {
                echo '<h3>'.__('Admin-only Form Debugging', 'gravity-forms-marketo').'</h3>';
                self::r(array(
                        'Form Entry Data' => $entry,
                        #'Form Meta Data' => $form,
                        'Marketo Sync Type' => $syncType,
                        'Marketo Feed Meta Data' => $feed,
                        'Munchkin Cookie' => self::get_munchkin_cookie(),
                        'Marketo Posted Merge Data' => $merge_vars,
                        'Posted Data ($_POST)' => $_POST,
                        'syncLead Result' => print_r($success, true),
                        'Added to Campaign?' => !empty($campaign) ? sprintf('Added to `%s` campaign (ID: %s)', $campaign_name, $campaign_id) : 'Adding to campaign failed.',
                ));
            }


            /**
             * Add lead notes with Marketo lead ID info
             */
            if(function_exists('gform_update_meta') && $leadId) {
                try {

                    // TODO - assign campaign to lead

                    @RGFormsModel::add_note($entry['id'], $current_user->ID, $current_user->display_name, stripslashes(sprintf(__('Added or Updated on Marketo. Contact ID: #%d. View entry at %s', 'gravity-forms-marketo'), $leadId, self::get_contact_url($leadId))));

                    @gform_update_meta($entry['id'], 'marketo_id', $leadId);

                } catch(Exception $e) {

                }
            }

       } elseif(current_user_can('administrator')) {
            echo '<div class="error" id="message">'.wpautop(sprintf(__("The form didn't create a contact because the Marketo Gravity Forms Add-on plugin isn't properly configured. %sCheck the configuration%s and try again.", 'gravity-forms-marketo'), '<a href="'.admin_url('admin.php?page=gf_settings&amp;addon=Marketo').'">', '</a>')).'</div>';
        }
    }

    function get_contact_url($contact_id) {
        $domain = @self::get_setting('subdomain');

        return add_query_arg(array('leadId' => $contact_id), 'https://'.self::get_setting('subdomain').'.marketo.com/leadDatabase/loadLeadDetail');
    }

    function entry_info_link_to_marketo($form_id, $lead) {
        $contact_id = gform_get_meta($lead['id'], 'marketo_id');
        if(!empty($contact_id)) {
            echo sprintf(__('<p>Marketo ID: <a href="%s">Contact #%s</a></p>', 'gravity-forms-marketo'), self::get_contact_url($contact_id), $contact_id);
        }
    }

    private function clean_utf8($string) {

        if(function_exists('mb_convert_encoding') && !seems_utf8($string)) {
            $string = mb_convert_encoding($string, "UTF-8", 'auto');
        }

        // First, replace UTF-8 characters.
        $string = str_replace(
            array("\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9c", "\xe2\x80\x9d", "\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x80\xa6"),
            array("'", "'", '"', '"', '-', '--', '...'),
        $string);

        // Next, replace their Windows-1252 equivalents.
        $string = str_replace(
            array(chr(145), chr(146), chr(147), chr(148), chr(150), chr(151), chr(133)),
            array("'", "'", '"', '"', '-', '--', '...'),
        $string);

        return $string;
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFMarketo::has_access("gravityforms_marketo_uninstall"))
            die(__("You don't have adequate permission to uninstall Marketo Add-On.", "gravity-forms-marketo"));

        //droping all tables
        GFMarketoData::drop_tables();

        //removing options
        delete_site_option("gf_marketo_settings");
        delete_site_option("gf_marketo_version");

        //Deactivating plugin
        $plugin = "gravity-forms-marketo/marketo.php";
        deactivate_plugins($plugin);
        update_site_option('recently_activated', array($plugin => time()) + (array)get_site_option('recently_activated'));
    }

    public static function is_optin($form, $settings){
        $config = $settings["meta"];
        $operator = $config["optin_operator"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);
        $field_value = RGFormsModel::get_field_value($field, array());
        $is_value_match = is_array($field_value) ? in_array($config["optin_value"], $field_value) : $field_value == $config["optin_value"];

        return  !$config["optin_enabled"] || empty($field) || ($operator == "is" && $is_value_match) || ($operator == "isnot" && !$is_value_match);
    }


    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")) {
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    private function simpleXMLToArray($xml,
                    $flattenValues=true,
                    $flattenAttributes = true,
                    $flattenChildren=true,
                    $valueKey='@value',
                    $attributesKey='@attributes',
                    $childrenKey='@children'){

        $return = array();
        if(!($xml instanceof SimpleXMLElement)){return $return;}
        $name = $xml->getName();
        $_value = trim((string)$xml);
        if(strlen($_value)==0){$_value = null;};

        if($_value!==null){
            if(!$flattenValues){$return[$valueKey] = $_value;}
            else{$return = $_value;}
        }

        $children = array();
        $first = true;
        foreach($xml->children() as $elementName => $child){
            $value = self::simpleXMLToArray($child, $flattenValues, $flattenAttributes, $flattenChildren, $valueKey, $attributesKey, $childrenKey);
            if(isset($children[$elementName])){
                if($first){
                    $temp = $children[$elementName];
                    unset($children[$elementName]);
                    $children[$elementName][] = $temp;
                    $first=false;
                }
                $children[$elementName][] = $value;
            }
            else{
                $children[$elementName] = $value;
            }
        }
        if(count($children)>0){
            if(!$flattenChildren){$return[$childrenKey] = $children;}
            else{$return = array_merge($return,$children);}
        }

        $attributes = array();
        foreach($xml->attributes() as $name=>$value){
            $attributes[$name] = trim($value);
        }
        if(count($attributes)>0){
            if(!$flattenAttributes){$return[$attributesKey] = $attributes;}
            else{$return = array_merge($return, $attributes);}
        }

        return $return;
    }

    private function convert_xml_to_object($response) {
        $response = @simplexml_load_string($response);  // Added @ 1.2.2
        if(is_object($response)) {
            return $response;
        } else {
            return false;
        }
    }

    private function convert_xml_to_array($response) {
        $response = self::convert_xml_to_object($response);
        $response = self::simpleXMLToArray($response);
        if(is_array($response)) {
            return $response;
        } else {
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    static protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    static protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }


}


if(!function_exists('wp_get_timezone_string')) {

/**
 * Returns the timezone string for a site, even if it's set to a UTC offset
 *
 * Adapted from http://www.php.net/manual/en/function.timezone-name-from-abbr.php#89155
 *
 * From http://www.skyverge.com/blog/down-the-rabbit-hole-wordpress-and-timezones/
 *
 * @return string valid PHP timezone string
 */
function wp_get_timezone_string() {

    // if site timezone string exists, return it
    if ( $timezone = get_option( 'timezone_string' ) )
        return $timezone;

    // get UTC offset, if it isn't set then return UTC
    if ( 0 === ( $utc_offset = get_option( 'gmt_offset', 0 ) ) )
        return 'UTC';

    // adjust UTC offset from hours to seconds
    $utc_offset *= 3600;

    // attempt to guess the timezone string from the UTC offset
    $timezone = timezone_name_from_abbr( '', $utc_offset );

    // last try, guess timezone string manually
    if ( false === $timezone ) {

        $is_dst = date( 'I' );

        foreach ( timezone_abbreviations_list() as $abbr ) {
            foreach ( $abbr as $city ) {
                if ( $city['dst'] == $is_dst && $city['offset'] == $utc_offset )
                    return $city['timezone_id'];
            }
        }
    }

    // fallback to UTC
    return 'UTC';
}
}