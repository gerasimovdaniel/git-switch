<?php
/*
 * Plugin Name: Git Switch
 * Plugin URI: http://danielbachhuber.com
 * Description: Switch your theme between Git branches.
 * Author: Daniel Bachhuber
 * Version: 1.0.1
 * Author URI: http://danielbachhuber.com
 */

class Git_Switch {

	private static $instance;

	private $capability = 'switch_themes';

	const CACHE_KEY = 'git-switch-status';

	public static function get_instance() {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new Git_Switch;
			self::$instance->load();
		}
		return self::$instance;
	}

	/**
	 * Load the plugin
	 */
	private function load() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		add_action( 'wp_ajax_git-switch-branch', array( $this, 'handle_switch_branch_action' ) );
		add_action( 'admin_bar_menu', array( $this, 'action_admin_bar_menu' ), 999 );

		$this->handle_deploy();

		add_action( 'init', function() {
			$maybe_purge_cache = get_transient( 'force_purge_cache' );
			if ( ! $maybe_purge_cache ) {
				return;
			}

			delete_transient( 'force_purge_cache' );
			$this->purge_cache();
		});

		$clean_link = function() {
			?>
			<style>
			#wp-admin-bar-git-switch-details > a {
				height: auto !important;
			}
			#wp-admin-bar-git-switch-details > a:hover {
				color: #eee !important;
			}
			#wp-admin-bar-git-switch-branches-default {
                max-height: 80vh;
                overflow: scroll;
			}
			</style>
			<?php
		};
		add_action( 'admin_head', $clean_link );
		add_action( 'wp_head', $clean_link );

	}

	/**
	 * Purge cache for current site or entire multisite network.
	 */
	protected function purge_cache() {

		if ( is_multisite() ) {
			$sites = get_sites();
			foreach ( $sites as $site ) {
				switch_to_blog( $site->blog_id );

				$this->handle_cache_purging();

				restore_current_blog();
			}
		} else {
			$this->handle_cache_purging();
		}
	}

	/**
	 * Actually purge some cache.
	 */
	protected function handle_cache_purging() {

		// Purge The7 dynamic css.
		if ( function_exists( 'presscore_refresh_dynamic_css' ) ) {
			presscore_refresh_dynamic_css();
		}

		// Purge Elementor cache.
		if ( class_exists( 'Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Handle the action to switch a branch
	 */
	public function handle_switch_branch_action() {
		$nonce = filter_input( INPUT_GET, 'nonce' );
		$branch = filter_input( INPUT_GET, 'branch' );
		$repo = filter_input( INPUT_GET, 'repo' );

		if ( ! current_user_can( $this->capability ) || ! wp_verify_nonce( $nonce, "git-switch-branch-{$repo}-{$branch}" ) ) {
			wp_die( "You can't do this." );
		}

		if ( ! $status = $this->get_repo_data( $repo ) ) {
			wp_die( "Can't interact with Git." );
		}

		$this->execute_git_command( $repo, sprintf( 'git checkout -f %s; git submodule update --init', escapeshellarg( $branch ) ) );
		$this->opcache_reset();

		delete_transient( self::CACHE_KEY );
		do_action( 'git_switch_branch', $branch, $repo );

		$this->schedule_cache_purging();

		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/**
	 * Display helpful details in the admin bar
	 */
	public function action_admin_bar_menu( $wp_admin_bar ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach( $this->get_repos() as $repo ) {
			$status = $this->get_repo_data( $repo );
			if ( ! $status ) {
				continue;
			}

			$sanitize_repo = sanitize_key( $repo );
			$top_menu_id = 'git-switch-' . $sanitize_repo;
			$pull_menu_id = 'git-pull-' . $sanitize_repo;
			$switch_branch_menu_id = 'git-switch-branches-' . $sanitize_repo;
	
			$wp_admin_bar->add_menu( array(
				'id'     => $top_menu_id,
				'title'  => sprintf( '%s(%s)%s', basename( $repo ), $status['branch'], $status['dirty'] ),
				'href'   => '#'
			) );
	
			if ( ! empty( $status['remote'] ) ) {
				$wp_admin_bar->add_menu( array(
					'parent' => $top_menu_id,
					'id'     => $pull_menu_id,
					'title'  => 'Pull changes',
					'href'   => add_query_arg( [ 'repo' => $repo, 'git-pull' => GIT_SWITCH_DEPLOY_SECRET ] ),
				) );
	
				$wp_admin_bar->add_menu( array(
					'parent' => $top_menu_id,
					'id'     => $switch_branch_menu_id,
					'title'  => 'Switch branch:',
					'href'   => '#'
				) );
				foreach( $status['remote'] as $remote_branch ) {
	
					// Do not display HEAD branch.
					if ( strpos( $remote_branch, 'HEAD ' ) !== false ) {
						continue;
					}
	
					$query_args = array(
						'action'        => 'git-switch-branch',
						'repo'          => $repo,
						'branch'        => $remote_branch,
						'nonce'         => wp_create_nonce( "git-switch-branch-{$repo}-{$remote_branch}" ),
					);
					$branch_switch_url = add_query_arg( $query_args, admin_url( 'admin-ajax.php' ) );
	
					$title = esc_html( $remote_branch );
					if ( $remote_branch === $status['branch'] ) {
						$title = '* ' . $title;
					}
	
					$wp_admin_bar->add_menu( array(
						'parent' => $switch_branch_menu_id,
						'id'     => $switch_branch_menu_id . '-' . sanitize_key( $remote_branch ),
						'title'  => $title,
						'href'   => esc_url( $branch_switch_url ),
					) );
				}
			}
		}
	}

	/**
	 * Get the current Git status
	 */
	public function get_repo_data( $repo ) {
		$cache_status = array_filter( (array) get_transient( self::CACHE_KEY ) );

		if ( isset( $cache_status[ $repo ] ) ) {
			return $cache_status[ $repo ];
		}

		$status = $this->execute_git_command( $repo, 'git status' );
		if ( empty( $status ) or ( false !== strpos( $status[0], 'fatal' ) ) ) {
			return false;
		}

		$end = end( $status );
		$return = array(
			'dirty'  => '*',
			'branch' => 'detached',
			'status' => $status,
			'remote' => array(),
		);

		if ( preg_match( '/On branch (.+)$/', $status[0], $matches ) ) {
			$return['branch'] = trim( $matches[1] );
		}

		if ( empty( $end ) or ( false !== strpos( $end, 'nothing to commit' ) ) ) {
			$return['dirty'] = '';
		}

		$branches = $this->execute_git_command( $repo, 'git branch -r --sort=-committerdate' );
		if ( ! empty( $branches ) ) {
			$branches = array_map( function( $branch ) {
				return trim( str_replace( 'origin/', '', $branch ) );
			}, $branches );
			$return['remote'] = $branches;
		}

		$cache_status[ $repo ] = $return;

		set_transient( self::CACHE_KEY, $cache_status, MINUTE_IN_SECONDS * 3 );

		return $return;
	}

	/**
	 * Refresh Git
	 */
	public function refresh( $repo ) {
		$this->execute_git_command( $repo, 'git remote update; git fetch origin; git remote prune origin' );

		$status = $this->get_repo_data( $repo );
		if ( isset( $status['branch'] ) && 'detached' !== $status['branch'] ) {
			$this->execute_git_command( $repo, sprintf( 'git clean -fd; git reset --hard; git pull -f origin %s; git submodule update --init --recursive', escapeshellarg( $status['branch'] ) ) );
		}

		$git_cache = get_transient( self::CACHE_KEY );
		unset( $git_cache[ $repo ] );
		set_transient( self::CACHE_KEY, $git_cache, MINUTE_IN_SECONDS * 3 );

		$this->schedule_cache_purging();
	}

	/**
	 * Handle deploy.
	 */
	protected function handle_deploy() {

		if ( ! defined( 'GIT_SWITCH_DEPLOY_SECRET' ) ) {
			return;
		}

		$repo = filter_input( INPUT_GET, 'repo' );
		if ( ! $repo ) {
			return;
		}

		$autodeploy_key = filter_input( INPUT_GET, 'git-switch-auto-deploy' );
		if ( $autodeploy_key === GIT_SWITCH_DEPLOY_SECRET ) {
			$this->refresh( $repo );
			echo "Refreshed.";
			exit;
		}

		$git_pull_key = filter_input( INPUT_GET, 'git-pull' );
		if ( $git_pull_key === GIT_SWITCH_DEPLOY_SECRET ) {
			$this->refresh( $repo );
			wp_safe_redirect( remove_query_arg( [ 'git-pull', 'repo' ] ) );
			exit;
		}
	}

	/**
	 * Force cache purging on next page load.
	 */
	protected function schedule_cache_purging() {
		set_transient( 'force_purge_cache', true, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Reset the opcache
	 */
	protected function opcache_reset() {
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}
	}

	/**
	 * Get the absolute path to the repo
	 * 
	 * @param string $repo The repo
	 * 
	 * @return string The absolute path
	 */
	private function get_repo_abspath( $repo ) {
		return trailingslashit( WP_CONTENT_DIR ) . $repo;
	}

	/**
	 * Execute a Git command
	 * 
	 * @param string $repo The repo
	 * @param string $command The command
	 * 
	 * @return array The results of the command
	 */
	private function execute_git_command( $repo, $command ) {
		$this->setup_git_ssh_command( $repo );

		$path = $this->get_repo_abspath( $repo );
		exec( sprintf( 'cd %s; %s', escapeshellarg( $path ), $command ), $results );
		return $results;
	}

	/**
	 * Setup the GIT_SSH_COMMAND environment variable
	 */
	private function setup_git_ssh_command( $repo ) {
		if ( ! defined( 'GIT_SWITCH_REPOS' ) ) {
			return;
		}

		$repos = GIT_SWITCH_REPOS;
		if ( ! isset( $repos[ $repo ]['ssh_key_path'] ) ) {
			return;
		}

		$ssh_key_path = realpath( ABSPATH . $repos[ $repo ]['ssh_key_path'] );

		// Set the GIT_SSH_COMMAND environment variable
		putenv("GIT_SSH_COMMAND=ssh -i $ssh_key_path");
	}

	/**
	 * Get the list of repos.
	 *
	 * @return array The list of repos.
	 */
	private function get_repos() {
		if ( defined( 'GIT_SWITCH_REPOS' ) ) {
			return array_keys( GIT_SWITCH_REPOS );
		}

		$active_theme_dir = basename(get_template());

		return [ 'themes/' . $active_theme_dir ];
	}
}

/**
 * Release the kraken!
 */
function Git_Switch() {
	return Git_Switch::get_instance();
}
add_action( 'plugins_loaded', 'Git_Switch' );
