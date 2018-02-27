<?php

/**
 * ZEIT ONLINE Maintenance Plugin
 * Deactivate comments and user logins for maintenance tasks where emerge of data should be kept at minimum.
 *
 * @link              https://github.com/ZeitOnline/zon-maintenance
 * @since             1.0.0
 * @package           ZON_Maintenance
 *
 * Plugin Name:       ZEIT ONLINE Maintenance
 * Plugin URI:        https://github.com/zeitonline/zon-maintenance
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress admin area.
 * Version:           1.0.0
 * Author:            Nico Brünjes
 * Author URI:        https://www.zeit.de
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       zmnt
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * ZEIT ONLINE Maintenance Plugin
 * Deactivate comments and user logins for maintenance tasks where emerge of data should be kept at minimum.
 *
 * @since      1.0.0
 * @package    ZON_Maintenance
 * @author     Nico Brünjes <nico.bruenjes@zeit.de>
 */
class Zon_Maintenance {

	const PREFIX = 'zmnt';
	const SETTINGS = 'zmnt_settings';

	/**
	 * Holds our instance later
	 *
	 * @since 1.0.0
	 * @var mixed
	 */
	static $instance = false;

	/**
	 * Needed to check if the plugin is correctly activated
	 * in multisite environment
	 *
	 * @since 1.0.0
	 * @access private
	 * @var bool
	 */
	private $networkactive;

	/**
	 * Standard message title
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string
	 */
	private $textbox_title;

	/**
	 * Standard message text
	 *
	 * @since 1.0.0
	 * @access private
	 * @var string
	 */
	private $textbox_text;

	/**
	 * Name is used for menu url slug and identification
	 *
	 * @since 1.0.0
	 * @var string
	 */
	static $plugin_name = 'zon_maintenance';

	/**
	 * Name of the textdomain for i18n (uses Prefix)
	 * @var string
	 */
	static $textdomain = self::PREFIX;

	/**
	 * Version of the plugin
	 * used for cache busting for css files
	 * and compatibility checks
	 * Use semver version numbering
	 *
	 * @since 1.0.0
	 * @see https://semver.org/
	 * @var string
	 */
	static $version = '1.0.0';


	/**
	 * construction time again
	 *
	 * @since  1.0.0
	 */
	private function __construct() {
		// are we network activated?
		$this->networkactive = ( is_multisite() && array_key_exists( plugin_basename( __FILE__ ), (array) get_site_option( 'active_sitewide_plugins' ) ) );
		// set default texts
		$this->textbox_title = __('Comments are temporarely closed', self::$textdomain);
		$this->textbox_text = __('This weblog is in maintenance mode at present. Comments are closed during this time. Please revisit later. Sorry for the inconvenience.', self::$textdomain);

		// load translation first
		add_action( 'plugins_loaded', array( $this, 'register_text_domain' ) );
		// add warning to admin bar
		add_action( 'admin_bar_menu', array( $this, 'maintenance_toolbar_link' ), 999 );
		// prevent not so super users from login
		add_filter( 'wp_authenticate_user', array( $this, 'prevent_from_login' ), 10, 2 );

		if( is_admin() ) {
			// add style sheet for admin area
			add_action( 'admin_enqueue_scripts', array( $this, 'load_custom_wp_admin_style' ) );
			// log out not super admin user
			add_action( 'admin_init', array( $this, 'log_out_user' ) );
			// initialise plugin admin area
			add_action( 'admin_init', array( $this, 'init_settings' ) );
			// add menu entry
			$hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
			add_action( $hook, array( $this, 'add_admin_menu' ) );
		} else {
			// load frontend stylesheet
			add_action( 'wp_enqueue_scripts', array( $this, 'load_custom_wp_style' ) );
			// filter the commentform to conditionally disable comments
			add_filter('comment_form_default_fields', array($this, 'comment_form_default_fields_filter'));
			// filter form default data
			add_filter('comment_form_defaults', array($this, 'comment_form_defaults_filter'));
			// hide the comment textarea
			add_filter('comment_form_field_comment', array($this, 'comment_form_field_comment_filter'));
			// display message
			add_action('comment_form_top', array($this, 'comment_form_top_action'));
		}
	}

	/**
	 * If an instance exists, this returns it.  If not, it creates one and
	 * retuns it.
	 *
	 * @since  1.0.0
	 * @return ZON_Get_Frame_From_API
	 */
	public static function getInstance() {
		if ( !self::$instance )
			self::$instance = new self;
		return self::$instance;
	}

	/**
	 * Wordpress automatically called activation hook
	 *
	 * @since  1.0.0
	 	 */
	public static function activate() {
		// Todo: add compability check
	}

	/**
	 * Wordpress automatically called deactivation hook
	 *
	 * @since  1.0.0
	 */
	public static function deactivate() {
		$deleted = self::getInstance()->delete_all_transients();
	}

	/**
	 * Query all transients from the database and hand them to delete_transient
	 * use to immediatly delete all cached frames on request or as garbage collection
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public function delete_all_transients() {
		global $wpdb;
		$return_check = true;
		$table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
		$needle = is_multisite() ? 'meta_key' : 'option_name';
		$name_chunk = is_multisite() ? '_site_transient_' : '_transient_';
		$query = "
			SELECT `$needle`
			FROM `$table`
			WHERE `$needle`
			LIKE '%transient_" . self::PREFIX . "%'";
		$results = $wpdb->get_results( $query );
		foreach( $results as $result ) {
			$transient = str_replace( $name_chunk, '', $result->$needle );
			if ( ! $this->delete_correct_transient( $transient ) ) {
				$return_check = false;
			}
		}
		return $return_check;
	}

	/**
	 * Covers get_option for use with multisite wordpress
	 *
	 * @since  1.0.0
	 * @return mixed    The value set for the option.
	 */
	public function get_options() {
		$default = array( 'maint_on' => 0, 'log_out_user' => 0 );

		if ( is_multisite() ) {
			return get_site_option( self::SETTINGS, $default );
		}

		return get_option( self::SETTINGS, $default );
	}

	/**
	 * Covers update_option for use with multisite wordpress
	 *
	 * @since 1.0.0
	 * @return bool    False if value was not updated and true if value was updated.
	 */
	public function update_options( $options ) {
		if ( is_multisite() ) {
			return update_site_option( self::SETTINGS, $options );
		}

		return update_option( self::SETTINGS, $options );
	}

	/**
	 * Set site transient if multisite environment
	 *
	 * @since 1.0.0
	 * @param string $transient  name of the transient
	 * @param mixed  $value      content to set as transient
	 * @param int    $expiration time in seconds for maximum cache time
	 * @return bool
	 */
	public function set_correct_transient( $transient, $value, $expiration ) {
		if ( is_multisite() ) {
			return set_site_transient( $transient, $value, $expiration );
		} else {
			return set_transient( $transient, $value, $expiration );
		}
	}

	/**
	 * Get site transient if multisite environment
	 *
	 * @since 1.0.0
	 * @param  string $transient name of the transient
	 * @return mixed             content stored in the transient or false if no adequate transient found
	 */
	public function get_correct_transient( $transient ) {
		if ( is_multisite() ) {
			return get_site_transient( $transient );
		} else {
			return get_transient( $transient );
		}
	}

	/**
	 * Use site transient if multisite environment
	 * @param  string $transient name of the transient to delete
	 *
	 * @return bool
	 */
	public function delete_correct_transient( $transient ) {
		if ( is_multisite() ) {
			return delete_site_transient( $transient );
		} else {
			return delete_transient( $transient );
		}
	}

	/**
	 * Load pot files to translate texts
	 *
	 * @since  1.0.0
	 */
	public function register_text_domain() {
		$plugin_rel_path = dirname( plugin_basename( __FILE__ ) ) . '/languages';
		$is_loaded = load_plugin_textdomain( self::$textdomain, false, $plugin_rel_path );
	}

	/**
	 * Adds warning items to the admin bar
	 * Fires before the admin bar is rendered, only in admin area though
	 *
	 * @since  1.0.0
	 * @param  obj $wp_admin_bar 	hook delivered original admin bar
	 * @return $wp_admin_bar
	 */
	public function maintenance_toolbar_link( $wp_admin_bar ) {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1 ) {
			$args = array(
				'id' => 'customlink',
				'title' => 'Maintenance Mode aktiv!'
			);
			$wp_admin_bar->add_node($args);
		}
	}

	/**
	 * In case of maintenance do not allow users who are not
	 * super admin to login into the system
	 * Filters login credentials at login after user is instantiated
	 *
	 * @since  1.0.0
	 * @param  WP_User 	$user    	the user object
	 * @param  string 	$password 	password in plain text
	 * @return WP_User || WP_Error	user object if super admin, otherwise Error
	 */
	public function prevent_from_login( $user, $password ) {
		if ( ! is_super_admin( $user->ID ) ) {
			return new WP_Error(
				'maintenance_login_prevention',
				__( 'The site is in maintenance mode during which login is prevented. Please try again later.', self::$textdomain )
			);
		}
		return $user;
	}

	/**
	 * Load admin area stylesheet
	 *
	 * @since  1.0.0
	 */
	public function load_custom_wp_admin_style() {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1 ) {
			wp_register_style( 'maintenance_admin_css', plugin_dir_url(  __FILE__ ) .  'styles/maintenance_admin.css', false, self::$version );
			wp_enqueue_style( 'maintenance_admin_css' );
		}
	}

	/**
	 * Logs out non super admin user on their next pageload in the admin area
	 * Lacks a warning message at this point, will harm user (sorry for now)
	 * Fires after admin area is initialised
	 *
	 * @since  1.0.0
	 */
	public function log_out_user() {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1
			&& isset( $options['log_out_user'] ) && $options['log_out_user'] == 1
		) {
			if ( ! is_super_admin() ) {
				wp_logout();
			}
		}
	}

	/**
	 * Initialise settings and their callbacks for use on admin page
	 *
	 * @since  1.0.0
	 */
	public function init_settings() {
		// Set up the settings for this plugin
		register_setting( self::PREFIX . '_group', self::SETTINGS );

		add_settings_section(
			'zmnt_general_settings',								// section name
			__( 'General settings', self::$textdomain ), 			// section title
			array( $this, 'render_settings_section_helptext' ), 	// rendering callback
			self::$plugin_name 										// page slug
		);

		add_settings_field(
			'maint_on',												// settings name
			__( 'Main switch', self::$textdomain ),					// settings title
			array( $this, 'render_main_switch_checkbox' ),			// rendering callback
			self::$plugin_name,										// page slug
			'zmnt_general_settings'									// section
		);

		add_settings_field(
			'log_out_user',
			__( 'Logout users', self::$textdomain ),
			array( $this, 'render_log_out_checkbox' ),
			self::$plugin_name,
			'zmnt_general_settings'
		);

		add_settings_section(
			'zmnt_textbox_settings',
			__( 'Maintenance message', self::$textdomain ),
			array( $this, 'render_textbox_section_helptext' ),
			self::$plugin_name
		);

		add_settings_field(
			'title',
			__( 'Title', self::$textdomain ),
			array( $this, 'render_title_textinput' ),
			self::$plugin_name,
			'zmnt_textbox_settings'
		);

		add_settings_field(
			'text',
			__( 'Text', self::$textdomain ),
			array( $this, 'render_text_textarea' ),
			self::$plugin_name,
			'zmnt_textbox_settings'
		);
	}

	/**
	 * Render help text to general settings section
	 *
	 * @since  1.0.0
	 */
	public function render_settings_section_helptext() {
		echo "<p>Im <em>Maintenance Mode</em> bleibt die Website live, aber es werden keine Kommentare entgegen genommen und im Backend entsprechende Warnungen angezeigt.</p>";
	}

	/**
	 * Render checkox for main switch
	 *
	 * @since  1.0.0
	 */
	public function render_main_switch_checkbox() {
		$settings = self::SETTINGS;
		$options = $this->get_options();
		?>
		<label>
			<input type="checkbox" value="1" name="<?php echo $settings; ?>[maint_on]" <?php checked( 1 == $options['maint_on'] );?>> <?php _e( 'Activate maintenance', self::$textdomain ); ?>
		</label>
		<?php
	}

	/**
	 * Render a checkbox for user log out setting
	 *
	 * @since  1.0.0
	 */
	public function render_log_out_checkbox() {
		$settings = self::SETTINGS;
		$options = $this->get_options();
		?>
		<label>
		<input type="checkbox" value="1" name="<?php echo $settings; ?>[log_out_user]" <?php checked( 1 == $options['log_out_user'] );?>> <?php _e( 'Activate user log out', self::$textdomain ); ?>
		</label>
		<p class="description"><?php _e('All users that are not super admin are logged out of the backend at next page reload.', self::$textdomain ); ?></p>
		<?php
	}

	/**
	 * Render helptext for textbox section
	 *Statt des Kommentarformulars wird im <em>Maintenance Mode</em> ein Hinweisfeld angezeigt. Titel und Text des Feldes können hier eingetragen werden.
	 * @since  1.0.0
	 */
	public function render_textbox_section_helptext() {
		$text = esc_html( __( 'In maintenance mode instead of a comment form a message with adjustable title and text is displayed.', self::$textdomain ) );
		echo "<p>$text</p>";
	}

	/**
	 * Render text input field for textbox title
	 *
	 * @since  1.0.0
	 */
	public function render_title_textinput() {
		$settings = self::SETTINGS;
		$options = $this->get_options();
		$title = isset( $options['title'] ) ? $options['title'] : $this->textbox_title;
		$helptext = __( 'Title of the message box', self::$textdomain );
		echo <<<HTML
			<input size="40" type="text" name="{$settings}[title]" value="$title">
			<p class="description">{$helptext}.</p>
HTML;
	}

	/**
	 * Render textarea for textbox text
	 *
	 * @since 1.0.0
	 */
	public function render_text_textarea() {
		$settings = self::SETTINGS;
		$options = $this->get_options();
		$text = isset( $options['text'] ) ? $options['text'] : $this->textbox_text;
		$helptext = __( 'Text of the message box', self::$textdomain );
		echo <<<HTML
			<textarea name="{$settings}[text]" cols="40" rows="6">{$text}</textarea>
			<p class="description">{$helptext}.</p>
HTML;
	}

	/**
	 * Adding the settings&options page to the (network) menu
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu () {
		// in multisite only show when network activated
		if ( $this->networkactive ) {
			add_submenu_page(
				'settings.php', 									// parent_slug
				__('ZEIT ONLINE Maintenance', self::$textdomain), 	// page_title
				__('ZON Maintenance', self::$textdomain), 			// menu_title
				'manage_network_options', 							// capability (super admin)
				self::$plugin_name, 								// menu_slug
				array( $this, 'options_page' ) 						// callback
			);
		} else if ( ! is_multisite() ) {
			add_options_page(
				__('ZEIT ONLINE Maintenance', self::$textdomain), 	// page_title
				__('ZON Maintenance', self::$textdomain), 			// menu_title
				'manage_options', 									// capability (admin)
				self::$plugin_name, 								// menu_slug
				array( $this, 'options_page' ) 						// callback
			);
		}
	}

	/**
	 * Render administration page
	 *
	 * @since 1.0.0
	 */
	public function options_page() {
		if ( isset( $_POST[ 'submit' ] ) && isset( $_POST['_iwmp_nonce'] ) &&  wp_verify_nonce( $_POST['_iwmp_nonce'], 'iwmp_settings_nonce' ) ) {

			$options = $this->get_options();
			$options['maint_on'] = isset( $_POST[self::SETTINGS]['maint_on'] ) ? 1 : 0;
			$options['log_out_user'] = isset( $_POST[self::SETTINGS]['log_out_user'] ) ? 1 : 0;

			$updated = $this->update_options( $options );
			if ( $updated ) {
				add_settings_error(
					'zmnt_general_settings',
					'settings_updated',
					__('Settings saved.', self::$textdomain),
					'updated'
				);
			}
		}
		?>
		<div class="wrap">
			<h2>Einstellungen › <?php echo esc_html( get_admin_page_title() ); ?></h2>
			<?php settings_errors(); ?>
			<form method="POST" action="">
				<?php
				settings_fields( self::PREFIX . '_group' );
				do_settings_sections( self::$plugin_name );
				wp_nonce_field( 'iwmp_settings_nonce', '_iwmp_nonce' );
				?>
				<p class="submit">
				<?php submit_button(null, 'primary', 'submit', false); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Add additional styles for textbox UI
	 *
	 * @since 1.0.0
	 */
	public function load_custom_wp_style() {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1 ) {
			wp_register_style( 'maintenance_css', plugin_dir_url(  __FILE__ ) .  'styles/maintenance.css', false, self::$version );
			wp_enqueue_style( 'maintenance_css' );
		}
	}

	/**
	 * Render empty strings instead of comment area form fields
	 * Filters the text input section of comment_form()
	 *
	 * @since 1.0.0
	 * @param  array $fields 	default $fields delivered by hook
	 * @return array 			filtered fields
	 */
	public function comment_form_default_fields_filter( $fields ) {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1 ) {
			$fields = array(
				'author' => '',
				'email' => '',
				'url' => ''
			);
		}
		return $fields;
	}

	/**
	 * Render empty string instead of several default texts in a comment_form()
	 * Filters wordpress' text items in a comment_form()
	 *
	 * @since  1.0.0
	 * @param  array $defaults 	default form items
	 * @return array           	filtered items
	 */
	public function comment_form_defaults_filter( $defaults ) {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1 ) {
			$defaults = array(
				'logged_in_as' => '',
				'submit_button' => '',
				'title_reply' => '',
				'title_reply_to' => '',
				'comment_notes_before' => '',
				'must_log_in' => '',
				'$comment_field' => ''
			);
		}
		return $defaults;
	}

	/**
	 * Remove the comment textarea from the comment form
	 *
	 * @since  1.0.0
	 * @param  string $comment_field 	html for the comment textarea
	 * @return string                	the filtered comment field html
	 */
	public function comment_form_field_comment_filter($comment_field = '') {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1 ) {
			$comment_field = '';
		}
		return $comment_field;
	}

	/**
	 * Render information message on top of comment form
	 *
	 * @since  1.0.0
	 */
	public function comment_form_top_action() {
		$options = $this->get_options();
		if ( isset( $options['maint_on'] ) && $options['maint_on'] == 1 ) {
			$title = isset( $options['title'] ) ? esc_html( $options['title'] ) : $this->textbox_title;
			$text = isset( $options['text'] ) ? esc_html( $options['text'] ) : $this->textbox_text;
			$code = sprintf('<div class="%1$s"><h3 class="%1$s__title">%2$s</h3><p class="%1$s__text">%3$s</p></div>', 'maintenance', $title, $text);
			echo $code;
		}
	}

}

register_activation_hook(__FILE__, array('Zon_Maintenance', 'activate'));
register_deactivation_hook(__FILE__, array('Zon_Maintenance', 'deactivate'));

// Instantiate our class
$Zon_Maintenance = Zon_Maintenance::getInstance();
