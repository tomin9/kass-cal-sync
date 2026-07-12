<?php
/**
 * Trieda pre Google OAuth 2.0 autentifikáciu.
 * Ukladá credentials a tokeny do wp_options.
 */
class WCS_GCal_Auth {

    // ---------- Názvy wp_options ----------
    const OPTION_CLIENT_ID     = 'wcs_gcal_client_id';
    const OPTION_CLIENT_SECRET = 'wcs_gcal_client_secret';
    const OPTION_TOKEN         = 'wcs_gcal_token';

    // ---------- Google OAuth endpointy ----------
    const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GOOGLE_SCOPE     = 'https://www.googleapis.com/auth/calendar';

    // -----------------------------------------------------------------------
    // Verejné metódy
    // -----------------------------------------------------------------------

    /**
     * Vráti URL na presmerovanie používateľa na súhlas Google.
     */
    public function get_auth_url(): string {
        $params = [
            'client_id'     => $this->get_client_id(),
            'redirect_uri'  => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::GOOGLE_SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',         // vždy vráti refresh_token
            'state'         => wp_create_nonce( 'wcs_gcal_oauth' ),
        ];
        return self::GOOGLE_AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * Vymení authorization code za access + refresh token.
     */
    public function handle_callback( string $code ): true|WP_Error {
        $response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'code'          => $code,
                'client_id'     => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'redirect_uri'  => $this->get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error(
                'oauth_error',
                $body['error_description'] ?? $body['error']
            );
        }

        $body['created'] = time();
        update_option( self::OPTION_TOKEN, $body );
        return true;
    }

    /**
     * Vráti platný access token (automaticky obnoví ak vypršal).
     */
    public function get_access_token(): string|WP_Error {
        $token = get_option( self::OPTION_TOKEN );

        if ( ! $token || empty( $token['access_token'] ) ) {
            return new WP_Error( 'not_connected', 'Google Calendar nie je prepojený.' );
        }

        // Obnov token ak má vypršať do 5 minút
        $expires_at = (int) $token['created'] + (int) $token['expires_in'] - 300;
        if ( time() > $expires_at ) {
            if ( empty( $token['refresh_token'] ) ) {
                return new WP_Error( 'no_refresh_token', 'Refresh token chýba. Prepojte Google Calendar znova.' );
            }
            $result = $this->refresh_token( $token['refresh_token'] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $token = get_option( self::OPTION_TOKEN );
        }

        return $token['access_token'];
    }

    /**
     * Vráti true ak je plugin prepojený s Google.
     */
    public function is_connected(): bool {
        $token = get_option( self::OPTION_TOKEN );
        return ! empty( $token['access_token'] );
    }

    /**
     * Odpojí plugin od Google (vymaže uložený token).
     */
    public function disconnect(): void {
        delete_option( self::OPTION_TOKEN );
    }

    public function get_client_id(): string {
        return (string) get_option( self::OPTION_CLIENT_ID, '' );
    }

    public function get_client_secret(): string {
        return (string) get_option( self::OPTION_CLIENT_SECRET, '' );
    }

    // -----------------------------------------------------------------------
    // Pomocné metódy
    // -----------------------------------------------------------------------

    /**
     * Callback URL musí byť totožná s tou, ktorú ste zadali v Google Cloud Console.
     */
    public function get_redirect_uri(): string {
        return admin_url( 'admin.php?page=wcs-gcal-sync' );
    }

    /**
     * Obnoví access token pomocou refresh tokenu.
     */
    private function refresh_token( string $refresh_token ): true|WP_Error {
        $response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'refresh_token' => $refresh_token,
                'client_id'     => $this->get_client_id(),
                'client_secret' => $this->get_client_secret(),
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'refresh_error', $body['error_description'] ?? $body['error'] );
        }

        // Zachovaj pôvodný refresh_token (Google ho pri obnove nevracia vždy znova)
        $existing                  = get_option( self::OPTION_TOKEN, [] );
        $body['refresh_token']     = $existing['refresh_token'] ?? $refresh_token;
        $body['created']           = time();

        update_option( self::OPTION_TOKEN, $body );
        return true;
    }
}
