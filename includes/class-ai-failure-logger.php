<?php
/**
 * Logs failed AI vendor calls (timeout, network error, non-200 response,
 * malformed/unparseable output).
 *
 * FSE_AI_Client is deliberately "fail open" — any AI failure just falls
 * back to the rest of the plugin's search logic, silently, so a shopper's
 * search is never blocked by a flaky API. But "silent" makes it invisible
 * whether the AI layer is actually working day-to-day, or quietly failing
 * on every request while the store still functions fine via the
 * deterministic modules. This makes those failures visible without
 * affecting request behavior.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_AIFailureLogger {

    const OPTION_KEY  = 'fse_ai_failure_log';
    const MAX_ENTRIES = 200;

    /**
     * Record a single vendor failure. No-ops if logging is disabled.
     */
    public static function log( string $vendor, string $reason, string $keyword, int $http_code = 0 ): void {
        if ( fse_get_option( 'ai_failure_logging_enabled', '1' ) !== '1' ) return;

        $log = get_option( self::OPTION_KEY, [] );
        $log = is_array( $log ) ? $log : [];

        $log[] = [
            'time'      => time(),
            'vendor'    => $vendor,
            'reason'    => $reason,
            'http_code' => $http_code,
            'keyword'   => $keyword,
        ];

        // Cap by dropping the oldest entries once over the limit.
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $log, false );
    }

    /**
     * Logged failures, most recent first.
     *
     * @return array<int, array{time:int, vendor:string, reason:string, http_code:int, keyword:string}>
     */
    public static function get_entries(): array {
        $log = get_option( self::OPTION_KEY, [] );
        $log = is_array( $log ) ? array_reverse( $log ) : [];

        return $log;
    }

    public static function clear(): void {
        delete_option( self::OPTION_KEY );
    }
}
