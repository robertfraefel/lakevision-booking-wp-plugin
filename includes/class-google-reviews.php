<?php
/**
 * LVB_Google_Reviews – fetches and caches Google Places reviews.
 *
 * Provides a [lvb_reviews] shortcode that renders real Google reviews
 * fetched via the Google Places API, cached as a WordPress transient.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetches Google Places reviews and renders them as a shortcode.
 *
 * @package LakeVision_Booking
 */
class LVB_Google_Reviews {

    const TRANSIENT = 'lvb_google_reviews';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /**
     * Fetch reviews from Google Places API (with transient cache).
     *
     * @return array Array of review objects, or empty array on failure/unconfigured.
     */
    public static function fetch() {
        $cached = get_transient( self::TRANSIENT );
        if ( $cached !== false ) {
            return $cached;
        }

        $api_key  = get_option( 'lvb_places_api_key', '' );
        $place_id = get_option( 'lvb_place_id', '' );

        if ( ! $api_key || ! $place_id ) {
            return [];
        }

        $url = add_query_arg( [
            'place_id' => $place_id,
            'fields'   => 'reviews',
            'key'      => $api_key,
            'language' => 'de',
        ], 'https://maps.googleapis.com/maps/api/place/details/json' );

        $response = wp_remote_get( $url, [ 'timeout' => 8 ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $reviews = $data['result']['reviews'] ?? [];

        // Only show reviews with rating >= 4
        $reviews = array_values(
            array_filter( $reviews, static fn( $r ) => ( $r['rating'] ?? 0 ) >= 4 )
        );

        set_transient( self::TRANSIENT, $reviews, self::CACHE_TTL );
        return $reviews;
    }

    /**
     * Render the [lvb_reviews] shortcode.
     *
     * Returns empty string when API is not configured or no reviews are available.
     *
     * @return string HTML output.
     */
    public static function render_shortcode() {
        $reviews = self::fetch();

        if ( empty( $reviews ) ) {
            return '';
        }

        ob_start();
        ?>
        <section id="testimonials" class="ws-card">
            <h2 class="ws-h2">Was Rider sagen</h2>
            <p class="ws-lead">Echte Stimmen. Echter Progress. Echter Untersee-Vibe.</p>
            <div class="ws-grid3">
                <?php foreach ( $reviews as $r ) :
                    $rating = (int) ( $r['rating'] ?? 5 );
                    $stars  = str_repeat( '&#9733;', $rating );
                    $text   = esc_html( $r['text'] ?? '' );
                    $author = esc_html( $r['author_name'] ?? '' );
                ?>
                <div class="ws-card ws-cardSoft">
                    <div class="ws-stars"><?php echo $stars; ?></div>
                    <p class="ws-sub ws-quote">&bdquo;<?php echo $text; ?>&ldquo;</p>
                    <div class="ws-meta">&mdash; <?php echo $author; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}
