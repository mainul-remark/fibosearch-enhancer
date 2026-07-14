<?php
/**
 * Multi-vendor AI client for query understanding.
 *
 * Races all enabled/configured vendors (Gemini, OpenRouter, Groq) in
 * parallel via curl_multi and returns the first vendor's valid, parseable
 * response. Never throws — returns null on any failure so callers can fail
 * open.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FSE_AI_Client {

    const GEMINI_MODEL = 'gemini-2.5-flash';

    /**
     * Fetch AI query data for a keyword. Returns null if no vendor is
     * configured, or if every configured vendor fails/times out.
     *
     * @param string[] $category_names Real store category names to ground
     *                                  the AI's category guess against, so
     *                                  it names an actual taxonomy term
     *                                  instead of hallucinating one.
     * @return array{corrected:string, synonyms:string[], category:?string}|null
     */
    public function fetch( string $keyword, array $category_names = [] ): ?array {
        $keyword = trim( $keyword );
        if ( '' === $keyword ) return null;

        $timeout = (float) fse_get_option( 'ai_timeout_seconds', '1.5' );
        if ( $timeout <= 0 ) $timeout = 1.5;

        $prompt  = $this->build_prompt( $keyword, $category_names );
        $multi   = curl_multi_init();
        $handles = [];

        if ( '1' === fse_get_option( 'ai_gemini_enabled', '0' ) ) {
            $key = (string) fse_get_option( 'ai_gemini_key', '' );
            if ( '' !== $key ) {
                $handles['gemini'] = $this->make_gemini_curl( $prompt, $key, $timeout );
                curl_multi_add_handle( $multi, $handles['gemini'] );
            }
        }

        if ( '1' === fse_get_option( 'ai_openrouter_enabled', '0' ) ) {
            $key = (string) fse_get_option( 'ai_openrouter_key', '' );
            if ( '' !== $key ) {
                $model = (string) fse_get_option( 'ai_openrouter_model', 'meta-llama/llama-3.1-8b-instruct:free' );
                $handles['openrouter'] = $this->make_openai_compat_curl(
                    'https://openrouter.ai/api/v1/chat/completions',
                    $key,
                    $model,
                    $prompt,
                    $timeout
                );
                curl_multi_add_handle( $multi, $handles['openrouter'] );
            }
        }

        if ( '1' === fse_get_option( 'ai_groq_enabled', '0' ) ) {
            $key = (string) fse_get_option( 'ai_groq_key', '' );
            if ( '' !== $key ) {
                $model = (string) fse_get_option( 'ai_groq_model', 'llama-3.3-70b-versatile' );
                $handles['groq'] = $this->make_openai_compat_curl(
                    'https://api.groq.com/openai/v1/chat/completions',
                    $key,
                    $model,
                    $prompt,
                    $timeout
                );
                curl_multi_add_handle( $multi, $handles['groq'] );
            }
        }

        if ( empty( $handles ) ) {
            curl_multi_close( $multi );
            return null;
        }

        $result = $this->race( $multi, $handles, $timeout, $keyword );

        foreach ( $handles as $ch ) {
            curl_multi_remove_handle( $multi, $ch );
            curl_close( $ch );
        }
        curl_multi_close( $multi );

        return $result;
    }

    private function build_prompt( string $keyword, array $category_names = [] ): string {
        $prompt =
            "You are a WooCommerce store search assistant. A shopper searched for: \"{$keyword}\"\n\n" .
            "Return ONLY strict JSON (no markdown, no code fences, no extra text) with this exact shape:\n" .
            '{"corrected": "typo-corrected version of the query", "synonyms": ["up to 5 related search terms"], "category": "best matching category name from the list below, or null if unclear"}' . "\n\n" .
            "Rules: \"corrected\" is a short search phrase, not a sentence. \"synonyms\" must be specific multi-word phrases a shopper might search instead — never a single generic word (e.g. never bare \"care\", \"mens\", \"skin\") since those match unrelated products.";

        $category_names = array_slice( array_values( array_filter( $category_names ) ), 0, 80 );

        if ( ! empty( $category_names ) ) {
            $prompt .= " \"category\" MUST be either null, or copied EXACTLY (same spelling/casing) from this list of the store's real categories — never invent a category name that isn't in this list:\n" .
                implode( ', ', $category_names );
        } else {
            $prompt .= ' "category" is your single best guess at a product category name for this query, or null if you\'re not confident.';
        }

        return $prompt;
    }

    private function ca_bundle(): string {
        return ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    }

    private function make_gemini_curl( string $prompt, string $api_key, float $timeout ) {
        $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::GEMINI_MODEL . ':generateContent';
        $body = wp_json_encode( [
            'contents'         => [
                [ 'parts' => [ [ 'text' => $prompt ] ] ],
            ],
            'generationConfig' => [
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
            ],
        ] );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => (int) ( $timeout * 1000 ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => $this->ca_bundle(),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $api_key,
                'Content-Length: ' . strlen( $body ),
            ],
        ] );

        return $ch;
    }

    private function make_openai_compat_curl( string $endpoint, string $api_key, string $model, string $prompt, float $timeout ) {
        $body = wp_json_encode( [
            'model'       => $model,
            'temperature' => 0.2,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You are a WooCommerce store search assistant. Return ONLY a strict JSON object — no other text, no markdown, no code fences.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
        ] );

        $ch = curl_init( $endpoint );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => (int) ( $timeout * 1000 ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => $this->ca_bundle(),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'Content-Length: ' . strlen( $body ),
            ],
        ] );

        return $ch;
    }

    /**
     * Poll the multi-handle until the first vendor returns a valid,
     * parseable response, or the overall timeout elapses.
     */
    private function race( $multi, array $handles, float $timeout, string $keyword ): ?array {
        $start   = microtime( true );
        $running = null;
        $done    = [];

        do {
            curl_multi_exec( $multi, $running );

            while ( $info = curl_multi_info_read( $multi ) ) {
                $ch     = $info['handle'];
                $vendor = array_search( $ch, $handles, true );
                if ( false === $vendor || isset( $done[ $vendor ] ) ) continue;
                $done[ $vendor ] = true;

                $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                if ( 200 === $http_code ) {
                    $body   = curl_multi_getcontent( $ch );
                    $parsed = $this->parse_response( $vendor, (string) $body, $keyword );
                    if ( null !== $parsed ) {
                        return $parsed;
                    }
                }
            }

            if ( $running ) {
                curl_multi_select( $multi, 0.1 );
            }
        } while ( $running > 0 && ( microtime( true ) - $start ) < $timeout );

        return null;
    }

    /**
     * Extract the model's text output and decode it as the expected JSON
     * shape. Falls back to regex-extracting the first {...} block if the
     * model wrapped the JSON in prose or markdown fences.
     */
    private function parse_response( string $vendor, string $body, string $keyword ): ?array {
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) return null;

        if ( 'gemini' === $vendor ) {
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } else {
            $text = $data['choices'][0]['message']['content'] ?? '';
        }

        $text = trim( (string) $text );
        if ( '' === $text ) return null;

        $parsed = json_decode( $text, true );
        if ( ! is_array( $parsed ) && preg_match( '/\{.*\}/s', $text, $matches ) ) {
            $parsed = json_decode( $matches[0], true );
        }

        if ( ! is_array( $parsed ) ) return null;

        return $this->normalize_result( $parsed, $keyword );
    }

    /**
     * Sanitize and shape the AI's raw JSON into the contract callers rely
     * on. Missing/invalid fields fall back to safe defaults rather than
     * failing the whole result.
     */
    private function normalize_result( array $data, string $keyword ): array {
        $corrected = ( isset( $data['corrected'] ) && is_string( $data['corrected'] ) && '' !== trim( $data['corrected'] ) )
            ? sanitize_text_field( $data['corrected'] )
            : $keyword;

        $synonyms = [];
        if ( isset( $data['synonyms'] ) && is_array( $data['synonyms'] ) ) {
            foreach ( $data['synonyms'] as $synonym ) {
                if ( ! is_string( $synonym ) || '' === trim( $synonym ) ) continue;

                $synonym = sanitize_text_field( $synonym );

                // Drop overly generic single-word synonyms (e.g. "care",
                // "mens") — they LIKE-match almost any product description
                // and pull in irrelevant results. Keep multi-word phrases
                // and longer single words, which are specific enough to be
                // useful search terms on their own.
                if ( false === strpos( trim( $synonym ), ' ' ) && mb_strlen( $synonym ) < 6 ) continue;

                $synonyms[] = $synonym;
            }
        }
        $synonyms = array_slice( array_values( array_unique( $synonyms ) ), 0, 5 );

        $category = null;
        if ( isset( $data['category'] ) && is_string( $data['category'] ) && '' !== trim( $data['category'] ) ) {
            $category = sanitize_text_field( $data['category'] );
        }

        return [
            'corrected' => $corrected,
            'synonyms'  => $synonyms,
            'category'  => $category,
        ];
    }
}
