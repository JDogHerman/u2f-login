<?php
/*
 * Plugin Name: U2F Login
 * Plugin URI: http://www.extendwings.com
 * Description: Make WordPress login secure with U2F (Universal Second Factor) protocol
 * Version: 0.1.0-dev
 * Author: Daisuke Takahashi(Extend Wings)
 * Author URI: http://www.extendwings.com
 * License: AGPLv3 or later
 * Text Domain: u2f
 * Domain Path: /languages/
*/

if( ! function_exists('add_action') ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

if( version_compare( PHP_VERSION, '5.5', '<') ) {
	require_once( ABSPATH . 'wp-admin/includes/plugin.php');
	deactivate_plugins( __FILE__ );
}

if( version_compare( get_bloginfo('version'), '4.0', '<') ) {
	require_once( ABSPATH . 'wp-admin/includes/plugin.php');
	deactivate_plugins( __FILE__ );
}

add_action('init', array('U2F', 'init') );

class U2F {
	static $instance;

	private $u2f;

	const VERSION = '0.1.0-dev';

	static function init() {
		if( ! self::$instance ) {
			if( did_action('plugins_loaded') )
				self::plugin_textdomain();
			else
				add_action('plugins_loaded', array(__CLASS__, 'plugin_textdomain') );

			self::$instance = new U2F;
		}
		return self::$instance;
	}

	private function __construct() {
		require_once( plugin_dir_path( __FILE__ ) . 'lib/php-u2flib-server/autoload.php');
		$this->u2f = new u2flib_server\U2F( set_url_scheme('//' . $_SERVER['HTTP_HOST'] ) );

	//	add_filter('authenticate', array( &$this, 'authenticate'), 25, 3);
		add_action('admin_menu', array( &$this, 'users_menu') );
		add_action('admin_print_scripts-users_page_security-key', array( &$this, 'admin_print_scripts') );
		add_action('admin_enqueue_scripts', array( &$this, 'admin_enqueue_assets') );
		if( is_admin() ) {
			add_action( 'wp_ajax_u2f_register', array( &$this, 'register') );
		}

		add_filter('plugin_row_meta', array( &$this, 'plugin_row_meta'), 10, 2);
	}

	public function authenticate( $user, $username, $password ) {
		if(is_a($user, 'WP_User') ) {
			return $user;
		}

		if( !empty( $_POST['u2f_token'] )) {
			$u2f_token = $_POST['u2f_token'];
			/*
			Validate token!!
			*/
		}

		return false;
	}

	public function users_menu() {
		add_users_page(__('Security Key', 'u2f'), __('Your Security Key', 'u2f'), 'read', 'security-key', array( &$this, 'render_users_menu') );
	}

	public function render_users_menu() {
		if( ! class_exists('WP_List_Table') ) {
			require_once( ABSPATH.'wp-admin/includes/class-wp-list-table.php');
		}

		$data = get_user_meta( get_current_user_id(), 'u2f_registered_key');

		require_once( plugin_dir_path( __FILE__ ) . 'class.list-table.php');
		$list_table = new U2F_List_Table( $data );
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h2><?php _e('Security Key', 'u2f'); ?></h2>
			<h3><?php _e('Security Keys associated with your account', 'u2f'); ?></h3>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
				<?php $list_table->display() ?>
			</form>

			<h3><?php _e('Add another Security Key', 'u2f'); ?></h3>
			<div class="button button-primary button-large" id="u2f-register">
				<?php _e('Register', 'u2f'); ?>
			</div>
		</div><!-- wrap -->
		<?php
	}

	public function admin_print_scripts() {
		echo '<script src="chrome-extension://pfboblefjcgdjicmnffhdgionmgcdmne/u2f-api.js"></script>' . PHP_EOL;
	}

	public function admin_enqueue_assets( $hook ) {
		if('users_page_security-key' == $hook ) {
			wp_enqueue_script('u2f-admin', plugin_dir_url( __FILE__ ) . 'admin.js', array('jquery'), self::VERSION, true);
			wp_enqueue_style('u2f-admin', plugin_dir_url( __FILE__ ) . 'admin.css', array(), self::VERSION);

			try {
				$data = $this->u2f->getRegisterData( array() );
				list($req,$sigs) = $data;


				set_transient('u2f_register_request', $req, HOUR_IN_SECONDS );
				$data = array(
					'request' => json_encode( $req ),
					'sigs'    => json_encode( $sigs ),
					'ajax_url' => admin_url( 'admin-ajax.php')
				);
				wp_localize_script('u2f-admin', 'u2f_data', $data );
			} catch( Exception $e ) {
				// wp_die()?
			}

		}
	}
	
	public function register() {
		try {
			$reg = $this->u2f->doRegister( get_transient('u2f_register_request'), (object) $_POST['data'] );
			$reg = array(
				'name'       => 'New Security Key',
				'added'       => time(),
				'last_used'   => time(),
				'keyHandle'   => $reg->keyHandle,
				'publicKey'   => $reg->publicKey,
				'certificate' => $reg->certificate,
				'counter'     => $reg->counter,
			);
			add_user_meta( get_current_user_id(), 'u2f_registered_key', $reg );
		} catch( Exception $e ) {
			echo "alert('error: " . $e->getMessage() . "');";
		} finally {
			delete_transient('u2f_register_request');
			die();
		}
	}

	static function delete_security_key( $user_id, $keyHandle ) {
		global $wpdb;

		if( !is_numeric( $user_id ) || !$keyHandle ) {
			return false;
		}

		$user_id = absint( $user_id );
		if( !$user_id ) {
			return false;
		}

		$table = $wpdb->usermeta;

		$keyHandle = wp_unslash( $keyHandle );
		$keyHandle = maybe_serialize( $keyHandle );

		$query = $wpdb->prepare("SELECT umeta_id FROM $table WHERE meta_key = 'u2f_registered_key' AND user_id = %d", $user_id );

		if( $keyHandle )
			$query .= $wpdb->prepare(" AND meta_value LIKE %s", '%:"' . $keyHandle . '";s:%');

		$meta_ids = $wpdb->get_col( $query );
		if( !count( $meta_ids ) )
			return false;

		$query = "DELETE FROM $table WHERE umeta_id IN( " . implode( ',', $meta_ids ) . " )";

		$count = $wpdb->query($query);

		if( !$count )
			return false;

		wp_cache_delete( $user_id, 'user_meta');

		return true;
	}

	static function plugin_textdomain() {
		load_plugin_textdomain('u2f', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');
	}

	function plugin_row_meta( $links, $file ) {
		if( plugin_basename( __FILE__ ) === $file ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url('http://www.extendwings.com/donate/'),
				__('Donate', 'u2f')
			);
		}
		return $links;
	}
}
