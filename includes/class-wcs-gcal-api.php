<?php
/**
 * Obal pre Google Calendar REST API v3.
 * Všetky HTTP požiadavky idú cez WordPress WP_HTTP (wp_remote_*).
 */
class WCS_GCal_API {

    const GCAL_BASE = 'https://www.googleapis.com/calendar/v3';

    public function __construct( private WCS_GCal_Auth $auth ) {}

    // -----------------------------------------------------------------------
    // Verejné metódy
    // -----------------------------------------------------------------------

    /**
     * Vráti zoznam kalendárov dostupných pre prihláseného používateľa.
     */
    public function get_calendars(): array|WP_Error {
        return $this->request( 'GET', '/users/me/calendarList' );
    }

    /**
     * Vytvorí nový event v kalendári.
     *
     * @param string $calendar_id  ID kalendára (napr. „primary" alebo e-mail)
     * @param array  $event        Event data podľa Google Calendar API schema
     * @return array|WP_Error      Vrátený event objekt alebo chyba
     */
    public function create_event( string $calendar_id, array $event ): array|WP_Error {
        return $this->request(
            'POST',
            '/calendars/' . rawurlencode( $calendar_id ) . '/events',
            $event
        );
    }

    /**
     * Aktualizuje existujúci event (PUT = úplná náhrada).
     */
    public function update_event( string $calendar_id, string $event_id, array $event ): array|WP_Error {
        return $this->request(
            'PUT',
            '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
            $event
        );
    }

    /**
     * Vymaže event z kalendára.
     * Vráti true pri úspechu (HTTP 204) alebo WP_Error.
     */
    public function delete_event( string $calendar_id, string $event_id ): true|WP_Error {
        $result = $this->request(
            'DELETE',
            '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    /**
     * Načíta jeden event z kalendára.
     */
    public function get_event( string $calendar_id, string $event_id ): array|WP_Error {
        return $this->request(
            'GET',
            '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id )
        );
    }

    // -----------------------------------------------------------------------
    // Interná HTTP vrstva
    // -----------------------------------------------------------------------

    /**
     * Vykoná autentifikovanú HTTP požiadavku na Google Calendar API.
     *
     * @param string $method   GET | POST | PUT | DELETE
     * @param string $endpoint Cesta za base URL (napr. /users/me/calendarList)
     * @param array  $body     Telo požiadavky (bude JSON-enkódované)
     * @return array|WP_Error  Decoded JSON response alebo chyba
     */
    private function request( string $method, string $endpoint, array $body = [] ): array|WP_Error {
        $access_token = $this->auth->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( self::GCAL_BASE . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );

        // HTTP 204 No Content (napr. pri DELETE) – úspech bez tela
        if ( $http_code === 204 ) {
            return [];
        }

        $data = json_decode( $raw_body, true );

        if ( $http_code >= 400 ) {
            $message = $data['error']['message'] ?? sprintf( 'Google API error (HTTP %d)', $http_code );
            return new WP_Error(
                'gcal_api_error',
                $message,
                [ 'status' => $http_code, 'body' => $raw_body ]
            );
        }

        return $data ?? [];
    }
}
