<?php
/**
 * Logs search terms that returned zero results.
 *
 * The other modules widen matching based on guessed synonym/taxonomy/stemming
 * rules, but the only way to know if those guesses are actually right for
 * this store is to see what real customers search for and get nothing back.
 * Stored as a capped list in a single option (zero-result searches are rare
 * enough on a working setup that a dedicated table would be overkill).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_SearchLogger {

    const OPTION_KEY = 'fse_zero_result_log';
    const MAX_ENTRIES = 200;

    public function __construct() {
        if ( fse_get_option( 'zero_result_logging_enabled', '1' ) !== '1' ) return;

        add_action( 'dgwt/wcas/analytics/after_searching', [ $this, 'log_if_empty' ], 10, 2 );
    }

    /**
     * @param string $keyword The search phrase.
     * @param int    $hits    Total hits across all suggestion groups.
     */
    public function log_if_empty( $keyword, $hits ) {
        $keyword = trim( (string) $keyword );
        if ( $keyword === '' || (int) $hits > 0 ) return;

        $log = get_option( self::OPTION_KEY, [] );
        $log = is_array( $log ) ? $log : [];

        $key = strtolower( $keyword );
        if ( isset( $log[ $key ] ) ) {
            $log[ $key ]['count']++;
            $log[ $key ]['last_seen'] = time();
        } else {
            $log[ $key ] = [
                'term'      => $keyword,
                'count'     => 1,
                'last_seen' => time(),
            ];
        }

        // Cap by dropping the least-recently-seen entries once over the limit.
        if ( count( $log ) > self::MAX_ENTRIES ) {
            uasort( $log, fn( $a, $b ) => $a['last_seen'] <=> $b['last_seen'] );
            $log = array_slice( $log, -self::MAX_ENTRIES, null, true );
        }

        update_option( self::OPTION_KEY, $log, false );
    }

    /**
     * Zero-result search terms, most frequent first.
     *
     * @return array<int, array{term: string, count: int, last_seen: int}>
     */
    public static function get_entries(): array {
        $log = get_option( self::OPTION_KEY, [] );
        $log = is_array( $log ) ? array_values( $log ) : [];

        usort( $log, fn( $a, $b ) => $b['count'] <=> $a['count'] );

        return $log;
    }

    public static function clear(): void {
        delete_option( self::OPTION_KEY );
    }
}
