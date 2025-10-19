<?php
/**
 * Plugin Name: YT Duplicate Post Detector
 * Plugin URI: https://github.com/krasenslavov/yt-duplicate-post-detector
 * Description: Prevents publishing posts with similar titles using Levenshtein distance algorithm. Displays warnings for potential duplicates.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Krasen Slavov
 * Author URI: https://krasenslavov.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yt-duplicate-post-detector
 * Domain Path: /languages
 *
 * @package YT_Duplicate_Post_Detector
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'YT_DPD_VERSION', '1.0.0' );

/**
 * Plugin base name.
 */
define( 'YT_DPD_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Plugin directory path.
 */
define( 'YT_DPD_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL.
 */
define( 'YT_DPD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class for Duplicate Post Detector.
 *
 * @since 1.0.0
 */
class YT_Duplicate_Post_Detector {

	/**
	 * Single instance of the class.
	 *
	 * @var YT_Duplicate_Post_Detector|null
	 */
	private static $instance = null;

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Duplicate detection results.
	 *
	 * @var array
	 */
	private $duplicates = array();

	/**
	 * Get single instance of the class.
	 *
	 * @return YT_Duplicate_Post_Detector
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->options = get_option( 'yt_dpd_options', $this->get_default_options() );
		$this->init_hooks();
	}

	/**
	 * Get default plugin options.
	 *
	 * @return array
	 */
	private function get_default_options() {
		return array(
			'enabled'              => true,
			'similarity_threshold' => 85,
			'check_post_types'     => array( 'post' ),
			'check_statuses'       => array( 'publish', 'future', 'private' ),
			'prevent_publish'      => false,
			'case_sensitive'       => false,
			'show_notification'    => true,
		);
	}

	/**
	 * Initialize WordPress hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {
		// Load plugin text domain.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Admin hooks.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
			add_filter( 'plugin_action_links_' . YT_DPD_BASENAME, array( $this, 'add_action_links' ) );

			// Duplicate detection hooks.
			add_action( 'save_post', array( $this, 'check_duplicate_on_save' ), 10, 3 );
			add_action( 'admin_notices', array( $this, 'display_duplicate_notices' ) );

			// AJAX handlers.
			add_action( 'wp_ajax_yt_dpd_check_title', array( $this, 'ajax_check_title' ) );
		}
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'yt-duplicate-post-detector',
			false,
			dirname( YT_DPD_BASENAME ) . '/languages'
		);
	}

	/**
	 * Add plugin admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Duplicate Post Detector Settings', 'yt-duplicate-post-detector' ),
			__( 'Duplicate Detector', 'yt-duplicate-post-detector' ),
			'manage_options',
			'yt-duplicate-post-detector',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'yt_dpd_options_group',
			'yt_dpd_options',
			array( $this, 'sanitize_options' )
		);

		add_settings_section(
			'yt_dpd_main_section',
			__( 'Detection Settings', 'yt-duplicate-post-detector' ),
			array( $this, 'render_section_info' ),
			'yt-duplicate-post-detector'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Detection', 'yt-duplicate-post-detector' ),
			array( $this, 'render_enabled_field' ),
			'yt-duplicate-post-detector',
			'yt_dpd_main_section'
		);

		add_settings_field(
			'similarity_threshold',
			__( 'Similarity Threshold (%)', 'yt-duplicate-post-detector' ),
			array( $this, 'render_threshold_field' ),
			'yt-duplicate-post-detector',
			'yt_dpd_main_section'
		);

		add_settings_field(
			'prevent_publish',
			__( 'Prevent Publishing', 'yt-duplicate-post-detector' ),
			array( $this, 'render_prevent_field' ),
			'yt-duplicate-post-detector',
			'yt_dpd_main_section'
		);

		add_settings_field(
			'check_post_types',
			__( 'Post Types to Check', 'yt-duplicate-post-detector' ),
			array( $this, 'render_post_types_field' ),
			'yt-duplicate-post-detector',
			'yt_dpd_main_section'
		);

		add_settings_field(
			'case_sensitive',
			__( 'Case Sensitive', 'yt-duplicate-post-detector' ),
			array( $this, 'render_case_sensitive_field' ),
			'yt-duplicate-post-detector',
			'yt_dpd_main_section'
		);
	}

	/**
	 * Sanitize plugin options.
	 *
	 * @param array $input Raw input data.
	 * @return array Sanitized data.
	 */
	public function sanitize_options( $input ) {
		$sanitized = array();

		$sanitized['enabled'] = isset( $input['enabled'] ) ? (bool) $input['enabled'] : false;

		$sanitized['similarity_threshold'] = isset( $input['similarity_threshold'] )
			? absint( $input['similarity_threshold'] )
			: 85;

		// Ensure threshold is between 1 and 100.
		$sanitized['similarity_threshold'] = max( 1, min( 100, $sanitized['similarity_threshold'] ) );

		$sanitized['prevent_publish'] = isset( $input['prevent_publish'] ) ? (bool) $input['prevent_publish'] : false;

		$sanitized['case_sensitive'] = isset( $input['case_sensitive'] ) ? (bool) $input['case_sensitive'] : false;

		$sanitized['show_notification'] = isset( $input['show_notification'] ) ? (bool) $input['show_notification'] : true;

		// Sanitize post types array.
		if ( isset( $input['check_post_types'] ) && is_array( $input['check_post_types'] ) ) {
			$sanitized['check_post_types'] = array_map( 'sanitize_key', $input['check_post_types'] );
		} else {
			$sanitized['check_post_types'] = array( 'post' );
		}

		$sanitized['check_statuses'] = array( 'publish', 'future', 'private' );

		return $sanitized;
	}

	/**
	 * Render settings section information.
	 *
	 * @return void
	 */
	public function render_section_info() {
		echo '<p>' . esc_html__( 'Configure how the duplicate post detector should work.', 'yt-duplicate-post-detector' ) . '</p>';
	}

	/**
	 * Render enabled checkbox field.
	 *
	 * @return void
	 */
	public function render_enabled_field() {
		$value = isset( $this->options['enabled'] ) ? $this->options['enabled'] : true;
		?>
		<label>
			<input type="checkbox"
				name="yt_dpd_options[enabled]"
				value="1"
				<?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Enable duplicate post detection', 'yt-duplicate-post-detector' ); ?>
		</label>
		<?php
	}

	/**
	 * Render similarity threshold field.
	 *
	 * @return void
	 */
	public function render_threshold_field() {
		$value = isset( $this->options['similarity_threshold'] ) ? $this->options['similarity_threshold'] : 85;
		?>
		<input type="number"
			name="yt_dpd_options[similarity_threshold]"
			value="<?php echo esc_attr( $value ); ?>"
			min="1"
			max="100"
			step="1"
			class="small-text" />
		<p class="description">
			<?php esc_html_e( 'Titles with similarity above this percentage will be flagged (1-100). Default: 85%', 'yt-duplicate-post-detector' ); ?>
		</p>
		<?php
	}

	/**
	 * Render prevent publish checkbox field.
	 *
	 * @return void
	 */
	public function render_prevent_field() {
		$value = isset( $this->options['prevent_publish'] ) ? $this->options['prevent_publish'] : false;
		?>
		<label>
			<input type="checkbox"
				name="yt_dpd_options[prevent_publish]"
				value="1"
				<?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Prevent publishing posts with duplicate titles (saves as draft instead)', 'yt-duplicate-post-detector' ); ?>
		</label>
		<?php
	}

	/**
	 * Render post types checkboxes.
	 *
	 * @return void
	 */
	public function render_post_types_field() {
		$selected   = isset( $this->options['check_post_types'] ) ? $this->options['check_post_types'] : array( 'post' );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $post_type ) {
			$checked = in_array( $post_type->name, $selected, true );
			?>
			<label style="display: block; margin-bottom: 5px;">
				<input type="checkbox"
					name="yt_dpd_options[check_post_types][]"
					value="<?php echo esc_attr( $post_type->name ); ?>"
					<?php checked( $checked, true ); ?> />
				<?php echo esc_html( $post_type->label ); ?>
			</label>
			<?php
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Select which post types to check for duplicates.', 'yt-duplicate-post-detector' ); ?>
		</p>
		<?php
	}

	/**
	 * Render case sensitive checkbox field.
	 *
	 * @return void
	 */
	public function render_case_sensitive_field() {
		$value = isset( $this->options['case_sensitive'] ) ? $this->options['case_sensitive'] : false;
		?>
		<label>
			<input type="checkbox"
				name="yt_dpd_options[case_sensitive]"
				value="1"
				<?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Make title comparison case-sensitive', 'yt-duplicate-post-detector' ); ?>
		</label>
		<?php
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'yt-duplicate-post-detector' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'yt_dpd_options_group' );
				do_settings_sections( 'yt-duplicate-post-detector' );
				submit_button();
				?>
			</form>

			<div class="yt-dpd-info-box">
				<h2><?php esc_html_e( 'How It Works', 'yt-duplicate-post-detector' ); ?></h2>
				<p><?php esc_html_e( 'This plugin uses the Levenshtein distance algorithm to calculate similarity between post titles. The algorithm measures the minimum number of single-character edits needed to change one string into another.', 'yt-duplicate-post-detector' ); ?></p>
				<p><strong><?php esc_html_e( 'Similarity Calculation:', 'yt-duplicate-post-detector' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( '100% = Identical titles', 'yt-duplicate-post-detector' ); ?></li>
					<li><?php esc_html_e( '90-99% = Very similar (few character differences)', 'yt-duplicate-post-detector' ); ?></li>
					<li><?php esc_html_e( '80-89% = Similar (minor differences)', 'yt-duplicate-post-detector' ); ?></li>
					<li><?php esc_html_e( '70-79% = Somewhat similar', 'yt-duplicate-post-detector' ); ?></li>
					<li><?php esc_html_e( 'Below 70% = Different titles', 'yt-duplicate-post-detector' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Enqueue on settings page.
		if ( 'settings_page_yt-duplicate-post-detector' === $hook ) {
			wp_enqueue_style(
				'yt-dpd-admin',
				YT_DPD_URL . 'assets/css/yt-duplicate-post-detector.css',
				array(),
				YT_DPD_VERSION
			);
		}

		// Enqueue on post edit pages.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style(
				'yt-dpd-admin',
				YT_DPD_URL . 'assets/css/yt-duplicate-post-detector.css',
				array(),
				YT_DPD_VERSION
			);

			wp_enqueue_script(
				'yt-dpd-admin',
				YT_DPD_URL . 'assets/js/yt-duplicate-post-detector.js',
				array( 'jquery' ),
				YT_DPD_VERSION,
				true
			);

			wp_localize_script(
				'yt-dpd-admin',
				'ytDpdData',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'yt_dpd_nonce' ),
					'threshold' => $this->options['similarity_threshold'],
					'enabled'   => $this->options['enabled'],
					'postType'  => get_post_type(),
					'strings'   => array(
						'checking'        => __( 'Checking for duplicates...', 'yt-duplicate-post-detector' ),
						'noDuplicates'    => __( 'No similar titles found.', 'yt-duplicate-post-detector' ),
						'foundDuplicates' => __( 'Similar titles found:', 'yt-duplicate-post-detector' ),
					),
				)
			);
		}
	}

	/**
	 * Check for duplicate posts when saving.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function check_duplicate_on_save( $post_id, $post, $update ) {
		// Skip if detection is disabled.
		if ( ! $this->options['enabled'] ) {
			return;
		}

		// Skip autosave, revisions, and auto-drafts.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		// Check if this post type should be checked.
		if ( ! in_array( $post->post_type, $this->options['check_post_types'], true ) ) {
			return;
		}

		// Find duplicates.
		$duplicates = $this->find_duplicate_titles( $post->post_title, $post_id, $post->post_type );

		if ( ! empty( $duplicates ) ) {
			// Store duplicates for admin notice.
			set_transient( 'yt_dpd_duplicates_' . get_current_user_id(), $duplicates, 60 );

			// Prevent publishing if option is enabled.
			if ( $this->options['prevent_publish'] && 'publish' === $post->post_status ) {
				// Unhook this function to prevent infinite loop.
				remove_action( 'save_post', array( $this, 'check_duplicate_on_save' ), 10 );

				// Change post status to draft.
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);

				// Re-hook the function.
				add_action( 'save_post', array( $this, 'check_duplicate_on_save' ), 10, 3 );
			}
		}
	}

	/**
	 * Find posts with similar titles.
	 *
	 * @param string $title     Post title to check.
	 * @param int    $exclude_id Post ID to exclude from results.
	 * @param string $post_type Post type to check.
	 * @return array Array of duplicate posts with similarity scores.
	 */
	public function find_duplicate_titles( $title, $exclude_id = 0, $post_type = 'post' ) {
		$duplicates = array();

		// Get all posts of the same type.
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $this->options['check_statuses'],
			'posts_per_page' => -1,
			'post__not_in'   => array( $exclude_id ),
			'fields'         => 'ids',
		);

		$posts = get_posts( $args );

		foreach ( $posts as $post_id ) {
			$existing_title = get_the_title( $post_id );
			$similarity     = $this->calculate_similarity( $title, $existing_title );

			if ( $similarity >= $this->options['similarity_threshold'] ) {
				$duplicates[] = array(
					'id'         => $post_id,
					'title'      => $existing_title,
					'similarity' => $similarity,
					'edit_link'  => get_edit_post_link( $post_id ),
					'view_link'  => get_permalink( $post_id ),
				);
			}
		}

		// Sort by similarity (highest first).
		usort(
			$duplicates,
			function( $a, $b ) {
				return $b['similarity'] - $a['similarity'];
			}
		);

		return $duplicates;
	}

	/**
	 * Calculate similarity between two strings using Levenshtein distance.
	 *
	 * @param string $str1 First string.
	 * @param string $str2 Second string.
	 * @return float Similarity percentage (0-100).
	 */
	public function calculate_similarity( $str1, $str2 ) {
		// Apply case sensitivity setting.
		if ( ! $this->options['case_sensitive'] ) {
			$str1 = strtolower( $str1 );
			$str2 = strtolower( $str2 );
		}

		// Handle empty strings.
		if ( empty( $str1 ) || empty( $str2 ) ) {
			return 0;
		}

		// Handle identical strings.
		if ( $str1 === $str2 ) {
			return 100;
		}

		// Calculate Levenshtein distance.
		$distance = levenshtein( $str1, $str2 );

		// Get the length of the longer string.
		$max_length = max( strlen( $str1 ), strlen( $str2 ) );

		// Calculate similarity percentage.
		$similarity = ( 1 - ( $distance / $max_length ) ) * 100;

		return round( $similarity, 2 );
	}

	/**
	 * Display admin notices for duplicate posts.
	 *
	 * @return void
	 */
	public function display_duplicate_notices() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'post', 'page' ), true ) ) {
			return;
		}

		$duplicates = get_transient( 'yt_dpd_duplicates_' . get_current_user_id() );

		if ( empty( $duplicates ) ) {
			return;
		}

		// Delete transient after displaying.
		delete_transient( 'yt_dpd_duplicates_' . get_current_user_id() );

		$notice_class = $this->options['prevent_publish'] ? 'notice-error' : 'notice-warning';
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> yt-dpd-notice">
			<h3><?php esc_html_e( 'Duplicate Post Detected!', 'yt-duplicate-post-detector' ); ?></h3>
			<?php if ( $this->options['prevent_publish'] ) : ?>
				<p><strong><?php esc_html_e( 'This post has been saved as a draft because similar titles were found.', 'yt-duplicate-post-detector' ); ?></strong></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Warning: Similar post titles were found. You may want to review these before publishing.', 'yt-duplicate-post-detector' ); ?></p>
			<?php endif; ?>

			<table class="yt-dpd-duplicates-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Similarity', 'yt-duplicate-post-detector' ); ?></th>
						<th><?php esc_html_e( 'Existing Post Title', 'yt-duplicate-post-detector' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'yt-duplicate-post-detector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $duplicates as $duplicate ) : ?>
						<tr>
							<td>
								<span class="yt-dpd-similarity-badge" style="background-color: <?php echo esc_attr( $this->get_similarity_color( $duplicate['similarity'] ) ); ?>">
									<?php echo esc_html( $duplicate['similarity'] ); ?>%
								</span>
							</td>
							<td><strong><?php echo esc_html( $duplicate['title'] ); ?></strong></td>
							<td>
								<a href="<?php echo esc_url( $duplicate['edit_link'] ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'yt-duplicate-post-detector' ); ?>
								</a>
								<a href="<?php echo esc_url( $duplicate['view_link'] ); ?>" class="button button-small" target="_blank">
									<?php esc_html_e( 'View', 'yt-duplicate-post-detector' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Get color for similarity badge based on percentage.
	 *
	 * @param float $similarity Similarity percentage.
	 * @return string Hex color code.
	 */
	private function get_similarity_color( $similarity ) {
		if ( $similarity >= 95 ) {
			return '#e74c3c'; // Red - almost identical.
		} elseif ( $similarity >= 90 ) {
			return '#e67e22'; // Orange - very similar.
		} elseif ( $similarity >= 85 ) {
			return '#f39c12'; // Yellow-orange - similar.
		} else {
			return '#95a5a6'; // Gray - somewhat similar.
		}
	}

	/**
	 * AJAX handler for real-time title checking.
	 *
	 * @return void
	 */
	public function ajax_check_title() {
		check_ajax_referer( 'yt_dpd_nonce', 'nonce' );

		$title     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'post';

		if ( empty( $title ) ) {
			wp_send_json_success( array( 'duplicates' => array() ) );
		}

		$duplicates = $this->find_duplicate_titles( $title, $post_id, $post_type );

		wp_send_json_success( array( 'duplicates' => $duplicates ) );
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=yt-duplicate-post-detector' ) ),
			esc_html__( 'Settings', 'yt-duplicate-post-detector' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Plugin activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$default_options = array(
			'enabled'              => true,
			'similarity_threshold' => 85,
			'check_post_types'     => array( 'post' ),
			'check_statuses'       => array( 'publish', 'future', 'private' ),
			'prevent_publish'      => false,
			'case_sensitive'       => false,
			'show_notification'    => true,
		);

		if ( ! get_option( 'yt_dpd_options' ) ) {
			add_option( 'yt_dpd_options', $default_options );
		}
	}

	/**
	 * Plugin deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clean up transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yt_dpd_duplicates_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yt_dpd_duplicates_%'" );
	}
}

/**
 * Plugin uninstall hook.
 *
 * @return void
 */
function yt_dpd_uninstall() {
	delete_option( 'yt_dpd_options' );

	// Clean up transients.
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yt_dpd_duplicates_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yt_dpd_duplicates_%'" );

	wp_cache_flush();
}

// Register activation hook.
register_activation_hook( __FILE__, array( 'YT_Duplicate_Post_Detector', 'activate' ) );

// Register deactivation hook.
register_deactivation_hook( __FILE__, array( 'YT_Duplicate_Post_Detector', 'deactivate' ) );

// Register uninstall hook.
register_uninstall_hook( __FILE__, 'yt_dpd_uninstall' );

// Initialize the plugin.
add_action( 'plugins_loaded', array( 'YT_Duplicate_Post_Detector', 'get_instance' ) );
