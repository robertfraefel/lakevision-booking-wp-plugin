<?php
/**
 * LVB_Updater – self-hosted plugin updates via the GitHub release API.
 *
 * Polls GitHub for the latest release/tag and presents itself to WordPress's
 * native update system the same way a WordPress.org-hosted plugin would.
 * Result: any site running this plugin sees the regular "Update available"
 * notice on the Plugins screen, and the one-click "Update now" button
 * downloads & swaps the plugin folder using GitHub's zipball.
 *
 * Two filters do the heavy lifting:
 *  - `pre_set_site_transient_update_plugins` — injects a fake update entry
 *    when GitHub has a higher version than the installed one.
 *  - `plugins_api` — populates the "View version X.Y.Z details" modal.
 *  - `upgrader_source_selection` — renames the extracted folder (GitHub
 *    zipballs unpack to `<owner>-<repo>-<sha>/`) to the expected
 *    `lakevision-booking/` so WP doesn't end up with a fresh plugin slug.
 *
 * Update sources, in order of preference:
 *  1. GitHub Releases (`/releases/latest`) — supports release notes via the
 *     `body` field, used in the changelog tab of the details modal.
 *  2. Git tags (`/tags`) — fallback when no Release object exists. Picks the
 *     highest tag by version_compare. No release notes.
 *
 * The lookup is cached for 6 hours in a site transient (`lvb_updater_data`)
 * to stay well under GitHub's unauthenticated rate limit of 60 req/h/IP.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Updater {

    const GITHUB_OWNER = 'robertfraefel';
    const GITHUB_REPO  = 'lakevision-booking-wp-plugin';
    const CACHE_KEY    = 'lvb_updater_data';
    const CACHE_TTL    = 6 * HOUR_IN_SECONDS;

    /**
     * Wire up the three filters.
     */
    public static function register() {
        add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_update' ] );
        add_filter( 'plugins_api',                            [ __CLASS__, 'plugin_info' ], 10, 3 );
        add_filter( 'upgrader_source_selection',              [ __CLASS__, 'fix_source_dir' ], 10, 4 );
    }

    /**
     * Inject an update entry into WordPress's update-plugins transient when
     * the GitHub-reported version exceeds the locally installed one.
     *
     * @param object $transient
     * @return object
     */
    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $latest = self::get_latest();
        if ( ! $latest || empty( $latest['version'] ) ) {
            return $transient;
        }
        if ( version_compare( $latest['version'], LVB_VERSION, '<=' ) ) {
            return $transient;
        }

        $slug = plugin_basename( LVB_PLUGIN_FILE );
        $transient->response[ $slug ] = (object) [
            'slug'         => dirname( $slug ),
            'plugin'       => $slug,
            'new_version'  => $latest['version'],
            'url'          => $latest['url'],
            'package'      => $latest['zip'],
            'tested'       => get_bloginfo( 'version' ),
            'requires'     => '5.0',
            'requires_php' => '7.4',
            'icons'        => [],
            'banners'      => [],
        ];

        return $transient;
    }

    /**
     * Populate the "View version details" modal on the Plugins screen.
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( empty( $args->slug ) || $args->slug !== dirname( plugin_basename( LVB_PLUGIN_FILE ) ) ) {
            return $result;
        }

        $latest = self::get_latest();
        if ( ! $latest ) {
            return $result;
        }

        return (object) [
            'name'          => 'LakeVision Booking',
            'slug'          => $args->slug,
            'version'       => $latest['version'],
            'author'        => '<a href="https://lakevision.ch">LakeVision</a>',
            'homepage'      => $latest['url'],
            'download_link' => $latest['zip'],
            'last_updated'  => $latest['date'],
            'tested'        => get_bloginfo( 'version' ),
            'requires'      => '5.0',
            'requires_php'  => '7.4',
            'sections'      => [
                'description' => 'Flexible booking system with Google Calendar integration, time-slot management and email notifications.',
                'changelog'   => $latest['body']
                    ? wpautop( wp_kses_post( $latest['body'] ) )
                    : '<p>Siehe <a href="' . esc_url( 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/commits/main' ) . '" target="_blank">Commit-History auf GitHub</a>.</p>',
            ],
        ];
    }

    /**
     * Rename the unpacked source directory so WP installs into the existing
     * plugin folder instead of creating a new slug.
     *
     * GitHub zipballs unpack to e.g. `robertfraefel-lakevision-booking-wp-plugin-a3b1c4d/`.
     * WP would treat that as a new plugin and leave the old folder behind.
     * We move the unpacked dir to `lakevision-booking/` before WP copies it.
     *
     * @param string $source
     * @param string $remote_source
     * @param object $upgrader
     * @param array  $hook_extra
     * @return string|WP_Error
     */
    public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
        global $wp_filesystem;

        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== plugin_basename( LVB_PLUGIN_FILE ) ) {
            return $source;
        }
        if ( ! $wp_filesystem ) {
            return $source;
        }

        $expected = trailingslashit( dirname( $remote_source ) ) . dirname( plugin_basename( LVB_PLUGIN_FILE ) );
        if ( untrailingslashit( $source ) === untrailingslashit( $expected ) ) {
            return $source;
        }

        if ( $wp_filesystem->move( $source, $expected ) ) {
            return trailingslashit( $expected );
        }

        return new WP_Error(
            'lvb_rename_failed',
            __( 'Could not rename the downloaded plugin folder. Please update manually.', 'lakevision-booking' )
        );
    }

    /**
     * Return cached release/tag info, hitting GitHub only when the transient
     * has expired or never been set.
     *
     * @return array|null  [ version, zip, body, url, date ] or null on failure.
     */
    private static function get_latest() {
        $cached = get_site_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $info = self::fetch_release();
        if ( ! $info ) {
            $info = self::fetch_tag();
        }
        if ( $info ) {
            set_site_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
        }
        return $info;
    }

    /**
     * Fetch the latest GitHub Release. Returns null if no Releases exist
     * or the API call fails.
     */
    private static function fetch_release() {
        $resp = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/releases/latest',
            [
                'timeout' => 8,
                'headers' => [ 'Accept' => 'application/vnd.github+json' ],
            ]
        );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return null;
        }
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
            return null;
        }
        return [
            'version' => ltrim( $data['tag_name'], 'v' ),
            'zip'     => $data['zipball_url'] ?? '',
            'body'    => $data['body'] ?? '',
            'url'     => $data['html_url'] ?? '',
            'date'    => $data['published_at'] ?? '',
        ];
    }

    /**
     * Fetch the highest-versioned tag from GitHub. Fallback when no
     * Releases have been published yet.
     */
    private static function fetch_tag() {
        $resp = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/tags',
            [
                'timeout' => 8,
                'headers' => [ 'Accept' => 'application/vnd.github+json' ],
            ]
        );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return null;
        }
        $tags = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $tags ) || empty( $tags ) ) {
            return null;
        }
        usort( $tags, function( $a, $b ) {
            return version_compare(
                ltrim( $b['name'] ?? '', 'v' ),
                ltrim( $a['name'] ?? '', 'v' )
            );
        } );
        $top = $tags[0];
        return [
            'version' => ltrim( $top['name'], 'v' ),
            'zip'     => 'https://api.github.com/repos/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . '/zipball/' . $top['name'],
            'body'    => '',
            'url'     => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
            'date'    => '',
        ];
    }
}
