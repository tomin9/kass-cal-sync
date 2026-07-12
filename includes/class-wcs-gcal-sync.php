<?php
/**
 * Jadro synchronizácie.
 *
 * Mapuje meta polia z Events Schedule WP Plugin (Curly Themes) na
 * Google Calendar event objekt a reaguje na WordPress save/delete hooky.
 *
 * Meta polia pluginu (post type „class"):
 *  _wcs_timestamp    – Unix timestamp začiatku (int)
 *  _wcs_duration     – Trvanie v minútach (int)
 *  _wcs_ending       – Unix timestamp konca viacdňového podujatia (int)
 *  _wcs_multiday     – Viacdňové podujatie (bool 0/1)
 *  _wcs_status       – 0=Live, 1=Canceled, 2=Canceled Dates
 *  _wcs_interval     – Opakovanie: 0=nie, 1=týžd., 2=denne, 3=2 týž., 4=mes., 5=roč.
 *  _wcs_repeat_days  – Dni opakovania (serializované pole, 0=ned … 6=sob)
 *  _wcs_repeat_until – Dátum konca opakovania (date string)
 *
 * Taxonómie (použité pre location/description):
 *  wcs-room       – miesto konania
 *  wcs-instructor – lektor / inštruktor
 *  wcs-type       – typ podujatia
 */
class WCS_GCal_Sync {

    /** Post meta kľúč, kde ukladáme Google Calendar event ID. */
    const META_GCAL_EVENT_ID = '_wcs_gcal_event_id';

    /** Cron hook pre spracovanie jednej dávky hromadnej synchronizácie. */
    const CRON_HOOK = 'wcs_gcal_process_sync_batch';

    /** Option so stavom bežiacej hromadnej synchronizácie. */
    const OPTION_SYNC_JOB = 'wcs_gcal_sync_job';

    /** Option s výsledkom poslednej dokončenej hromadnej synchronizácie. */
    const OPTION_LAST_RESULT = 'wcs_gcal_last_sync_result';

    /** Maximálny počet podujatí spracovaných v jednej dávke. */
    const BATCH_SIZE = 25;

    /** Maximálny čas (v sekundách) jedného behu dávky. */
    const BATCH_TIME_LIMIT = 20;

    public function __construct( private WCS_GCal_API $api ) {}

    // -----------------------------------------------------------------------
    // WordPress hooky
    // -----------------------------------------------------------------------

    public function register_hooks(): void {
        // Spustí sa po uložení post type „class"
        add_action( 'save_post_class', [ $this, 'on_save_post' ], 20, 3 );

        // Presun do koša
        add_action( 'wp_trash_post', [ $this, 'on_trash_post' ] );

        // Trvalé vymazanie
        add_action( 'before_delete_post', [ $this, 'on_delete_post' ], 10, 2 );

        // Dávkové spracovanie hromadnej synchronizácie na pozadí
        add_action( self::CRON_HOOK, [ $this, 'process_batch' ] );
    }

    /**
     * Vytvorí alebo aktualizuje event v Google Calendar po uložení podujatia.
     */
    public function on_save_post( int $post_id, WP_Post $post, bool $update ): void {
        // Preskočiť autosave a revízie
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! in_array( $post->post_status, [ 'publish', 'private', 'draft' ], true ) ) {
            return;
        }

        $calendar_id = (string) get_option( 'wcs_gcal_calendar_id', '' );
        if ( ! $calendar_id ) {
            return;
        }

        $event_data = $this->prepare_event_data( $post );
        if ( ! $event_data ) {
            return;
        }

        $gcal_event_id = (string) get_post_meta( $post_id, self::META_GCAL_EVENT_ID, true );

        if ( $gcal_event_id ) {
            // Pokus o aktualizáciu
            $result = $this->api->update_event( $calendar_id, $gcal_event_id, $event_data );

            // Ak bol event medzičasom vymazaný v Google, vytvoríme ho znovu
            if ( is_wp_error( $result ) ) {
                $status = $result->get_error_data()['status'] ?? 0;
                if ( $status === 404 ) {
                    $result = $this->api->create_event( $calendar_id, $event_data );
                    if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
                        update_post_meta( $post_id, self::META_GCAL_EVENT_ID, $result['id'] );
                    }
                }
            }
        } else {
            // Prvá synchronizácia – vytvoríme event
            $result = $this->api->create_event( $calendar_id, $event_data );
            if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
                update_post_meta( $post_id, self::META_GCAL_EVENT_ID, $result['id'] );
            }
        }

        if ( is_wp_error( $result ) ) {
            error_log(
                sprintf(
                    '[WCS GCal Sync] Chyba pri synchronizácii post #%d: %s',
                    $post_id,
                    $result->get_error_message()
                )
            );
        }
    }

    /**
     * Zruší (cancelled) event v Google Calendar keď ide príspevok do koša.
     * Nevymazáva ho úplne – zachová históriu v kalendári.
     */
    public function on_trash_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'class' ) {
            return;
        }

        $calendar_id   = (string) get_option( 'wcs_gcal_calendar_id', '' );
        $gcal_event_id = (string) get_post_meta( $post_id, self::META_GCAL_EVENT_ID, true );

        if ( $calendar_id && $gcal_event_id ) {
            // Nastav status na cancelled namiesto mazania
            $event_data = $this->prepare_event_data( $post );
            if ( $event_data ) {
                $event_data['status'] = 'cancelled';
                $this->api->update_event( $calendar_id, $gcal_event_id, $event_data );
            }
        }
    }

    /**
     * Natrvalo vymaže event z Google Calendar keď je príspevok trvalo vymazaný.
     */
    public function on_delete_post( int $post_id, WP_Post $post ): void {
        if ( $post->post_type !== 'class' ) {
            return;
        }

        $calendar_id   = (string) get_option( 'wcs_gcal_calendar_id', '' );
        $gcal_event_id = (string) get_post_meta( $post_id, self::META_GCAL_EVENT_ID, true );

        if ( $calendar_id && $gcal_event_id ) {
            $this->api->delete_event( $calendar_id, $gcal_event_id );
        }
    }

    // -----------------------------------------------------------------------
    // Mapovanie dát
    // -----------------------------------------------------------------------

    /**
     * Zostaví Google Calendar event array z WP_Post objektu.
     * Vráti null ak podujatiu chýba timestamp.
     *
     * @return array<string,mixed>|null
     */
    public function prepare_event_data( WP_Post $post ): ?array {
        $post_id = $post->ID;

        $timestamp = (int) get_post_meta( $post_id, '_wcs_timestamp', true );
        if ( ! $timestamp ) {
            return null;
        }

        $duration  = (int) get_post_meta( $post_id, '_wcs_duration', true );
        $multiday  = (bool) get_post_meta( $post_id, '_wcs_multiday', true );
        $ending    = (int) get_post_meta( $post_id, '_wcs_ending', true );
        $status_id = (int) get_post_meta( $post_id, '_wcs_status', true );
        $interval  = (int) get_post_meta( $post_id, '_wcs_interval', true );

        // Časové pásmo zo WordPress nastavení
        $tz_string = get_option( 'timezone_string' );
        if ( ! $tz_string ) {
            // Fallback na UTC offset
            $offset    = (float) get_option( 'gmt_offset', 0 );
            $tz_string = $this->offset_to_timezone( $offset );
        }

        try {
            $timezone = new DateTimeZone( $tz_string );
        } catch ( Exception $e ) {
            $timezone = new DateTimeZone( 'UTC' );
        }

        // Vypočítaj čas konca
        if ( $multiday && $ending > 0 ) {
            $end_timestamp = $ending;
        } elseif ( $duration > 0 ) {
            $end_timestamp = $timestamp + $duration * 60;
        } else {
            $end_timestamp = $timestamp + 3600; // predvolene 1 hodina
        }

        // Preskočiť podujatia s trvaním dlhším ako 365 dní
        if ( ( $end_timestamp - $timestamp ) > 365 * DAY_IN_SECONDS ) {
            return null;
        }

        // Events Schedule WP Plugin ukladá timestamp ako lokálny čas bez timezone offset
        // (napr. 9:00 lokálne je uložené ako Unix timestamp pre 9:00 UTC).
        // Preto timestamp NEkonvertujeme z UTC, ale čítame ho priamo ako lokálny čas
        // pomocou gmdate() – to nám dá správny časový reťazec bez posunu.
        $start_dt = new DateTime( gmdate( 'Y-m-d H:i:s', $timestamp ), $timezone );
        $end_dt   = new DateTime( gmdate( 'Y-m-d H:i:s', $end_timestamp ), $timezone );

        // ---- Miesto konania z taxonómie wcs-room ----
        $location = $this->get_term_names( $post_id, 'wcs-room' );

        // ---- Zostav description iba z obsahu príspevku ----
        $description = $post->post_content ? wp_strip_all_tags( $post->post_content ) : '';

        // ---- Status ----
        // _wcs_status: 0 = Live, 1 = Canceled, 2 = Canceled Dates
        $gcal_status = ( $status_id === 0 ) ? 'confirmed' : 'cancelled';

        // ---- Permalink ----
        $permalink = get_permalink( $post_id ) ?: home_url();

        $event = [
            'summary'     => $post->post_title,
            'description' => $description,
            'location'    => $location,
            'status'      => $gcal_status,
            'source'      => [
                'title' => get_bloginfo( 'name' ),
                'url'   => $permalink,
            ],
            'start' => [
                'dateTime' => $start_dt->format( 'c' ),
                'timeZone' => $tz_string,
            ],
            'end' => [
                'dateTime' => $end_dt->format( 'c' ),
                'timeZone' => $tz_string,
            ],
        ];

        // ---- Opakovanie (recurrence) ----
        if ( $interval > 0 ) {
            $rrule = $this->build_rrule( $interval, $post_id );
            if ( $rrule ) {
                $event['recurrence'] = [ $rrule ];
            }
        }

        return $event;
    }

    // -----------------------------------------------------------------------
    // Hromadná synchronizácia na pozadí (dávky cez WP-Cron)
    // -----------------------------------------------------------------------

    /**
     * Synchronizuje jedno podujatie do Google Calendar (create alebo update).
     */
    public function sync_post( WP_Post $post ): true|WP_Error {
        $calendar_id = (string) get_option( 'wcs_gcal_calendar_id', '' );
        if ( ! $calendar_id ) {
            return new WP_Error( 'no_calendar', 'Kalendár nie je vybraný.' );
        }

        $event_data = $this->prepare_event_data( $post );
        if ( ! $event_data ) {
            return new WP_Error( 'no_timestamp', 'Podujatiu chýba dátum začiatku.' );
        }

        $gcal_event_id = (string) get_post_meta( $post->ID, self::META_GCAL_EVENT_ID, true );

        if ( $gcal_event_id ) {
            // Overíme, či event v Google Calendar skutočne existuje
            $existing = $this->api->get_event( $calendar_id, $gcal_event_id );
            if ( is_wp_error( $existing ) && ( $existing->get_error_data()['status'] ?? 0 ) === 404 ) {
                // Event bol vymazaný v Google Calendar – zabudneme na staré ID
                delete_post_meta( $post->ID, self::META_GCAL_EVENT_ID );
                $gcal_event_id = '';
            }
        }

        if ( $gcal_event_id ) {
            // Event existuje – aktualizujeme ho
            $result = $this->api->update_event( $calendar_id, $gcal_event_id, $event_data );
        } else {
            // Event neexistuje (ani nikdy nebol synced) – vytvoríme ho
            $result = $this->api->create_event( $calendar_id, $event_data );
            if ( ! is_wp_error( $result ) && ! empty( $result['id'] ) ) {
                update_post_meta( $post->ID, self::META_GCAL_EVENT_ID, $result['id'] );
            }
        }

        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Spustí hromadnú synchronizáciu na pozadí: uloží frontu ID podujatí
     * a naplánuje spracovanie prvej dávky cez WP-Cron.
     *
     * @return array{total: int} Počet podujatí vo fronte.
     */
    public function start_background_sync(): array {
        $ids = get_posts( [
            'post_type'      => 'class',
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'posts_per_page' => -1,
            'meta_key'       => '_wcs_timestamp',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ] );

        $job = [
            'post_ids' => array_map( 'intval', $ids ),
            'total'    => count( $ids ),
            'success'  => 0,
            'errors'   => 0,
            'started'  => time(),
        ];

        update_option( self::OPTION_SYNC_JOB, $job, false );
        delete_option( self::OPTION_LAST_RESULT );

        $this->schedule_next_batch( 0 );
        spawn_cron();

        return [ 'total' => $job['total'] ];
    }

    /**
     * Zruší bežiacu hromadnú synchronizáciu.
     */
    public function cancel_background_sync(): void {
        delete_option( self::OPTION_SYNC_JOB );
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Vráti stav bežiacej hromadnej synchronizácie alebo null.
     *
     * @return array{post_ids: int[], total: int, success: int, errors: int, started: int}|null
     */
    public function get_job(): ?array {
        $job = get_option( self::OPTION_SYNC_JOB );
        return is_array( $job ) && ! empty( $job['total'] ) ? $job : null;
    }

    /**
     * Spracuje jednu dávku fronty (volané cez WP-Cron). Po dosiahnutí limitu
     * dávky alebo času naplánuje ďalší beh; po vyprázdnení fronty uloží výsledok.
     */
    public function process_batch(): void {
        $job = $this->get_job();
        if ( ! $job || empty( $job['post_ids'] ) ) {
            return;
        }

        // Ochrana pred súbežným behom dvoch dávok
        if ( get_transient( 'wcs_gcal_sync_lock' ) ) {
            $this->schedule_next_batch( 15 );
            return;
        }
        set_transient( 'wcs_gcal_sync_lock', 1, 90 );

        $deadline  = time() + self::BATCH_TIME_LIMIT;
        $processed = 0;

        while ( ! empty( $job['post_ids'] ) && $processed < self::BATCH_SIZE && time() < $deadline ) {
            // Ak bola synchronizácia medzičasom zrušená, neobnovuj frontu
            wp_cache_delete( self::OPTION_SYNC_JOB, 'options' );
            if ( false === get_option( self::OPTION_SYNC_JOB ) ) {
                delete_transient( 'wcs_gcal_sync_lock' );
                return;
            }

            $post_id = (int) array_shift( $job['post_ids'] );
            $post    = get_post( $post_id );

            if ( $post && $post->post_type === 'class' ) {
                $result = $this->sync_post( $post );
                if ( is_wp_error( $result ) ) {
                    $job['errors']++;
                    error_log( sprintf(
                        '[WCS GCal Sync] Chyba pri hromadnej synchronizácii post #%d: %s',
                        $post_id,
                        $result->get_error_message()
                    ) );
                } else {
                    $job['success']++;
                }
            } else {
                $job['errors']++;
            }

            $processed++;
            update_option( self::OPTION_SYNC_JOB, $job, false );
        }

        delete_transient( 'wcs_gcal_sync_lock' );

        if ( empty( $job['post_ids'] ) ) {
            update_option( self::OPTION_LAST_RESULT, [
                'success'  => $job['success'],
                'errors'   => $job['errors'],
                'finished' => time(),
            ], false );
            delete_option( self::OPTION_SYNC_JOB );
        } else {
            $this->schedule_next_batch( 1 );
        }
    }

    private function schedule_next_batch( int $delay ): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
        }
    }

    // -----------------------------------------------------------------------
    // Pomocné metódy
    // -----------------------------------------------------------------------

    /**
     * Vráti reťazec názvov termov z taxonómie (napr. "Yoga, Pilates").
     */
    private function get_term_names( int $post_id, string $taxonomy ): string {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return '';
        }
        return implode( ', ', wp_list_pluck( $terms, 'name' ) );
    }

    /**
     * Zostaví RRULE string pre Google Calendar recurrence.
     *
     * _wcs_interval hodnoty:
     *  1 = týždenne
     *  2 = denne (s volitelnými dňami v _wcs_repeat_days)
     *  3 = každé 2 týždne
     *  4 = mesačne
     *  5 = ročne
     */
    private function build_rrule( int $interval, int $post_id ): string {
        $freq_map = [
            1 => 'WEEKLY',
            2 => 'WEEKLY',  // denne = konkrétne dni týždňa → WEEKLY + BYDAY
            3 => 'WEEKLY',  // každé 2 týždne
            4 => 'MONTHLY',
            5 => 'YEARLY',
        ];

        $freq = $freq_map[ $interval ] ?? null;
        if ( ! $freq ) {
            return '';
        }

        $rule = 'RRULE:FREQ=' . $freq;

        if ( $interval === 3 ) {
            $rule .= ';INTERVAL=2';
        }

        // Dni opakovania (pri interval=2 – "denne" = vybrané dni v týždni)
        if ( $interval === 2 ) {
            $raw_days    = get_post_meta( $post_id, '_wcs_repeat_days', true );
            $repeat_days = maybe_unserialize( $raw_days );

            if ( is_array( $repeat_days ) && ! empty( $repeat_days ) ) {
                $day_map = [ 0 => 'SU', 1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA' ];
                $byday   = array_filter( array_map( fn( $d ) => $day_map[ $d ] ?? null, $repeat_days ) );
                if ( ! empty( $byday ) ) {
                    $rule .= ';BYDAY=' . implode( ',', $byday );
                }
            }
        }

        // Dátum konca opakovania
        $repeat_until = get_post_meta( $post_id, '_wcs_repeat_until', true );
        if ( $repeat_until ) {
            try {
                $until = new DateTime( $repeat_until, new DateTimeZone( 'UTC' ) );
                $until->setTime( 23, 59, 59 );
                $rule .= ';UNTIL=' . $until->format( 'Ymd\THis\Z' );
            } catch ( Exception $e ) {
                // Neplatný dátum – ignoruj
            }
        }

        return $rule;
    }

    /**
     * Prevedie GMT offset (napr. 1, 1.5, -5) na timezone string.
     */
    private function offset_to_timezone( float $offset ): string {
        $seconds = (int) ( $offset * 3600 );
        try {
            return ( new DateTimeZone( timezone_name_from_abbr( '', $seconds, false ) ?: 'UTC' ) )->getName();
        } catch ( Exception $e ) {
            return 'UTC';
        }
    }
}
