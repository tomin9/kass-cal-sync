<?php
/**
 * Admin stránka pluginu.
 *
 * Nastavenia dostupné na:  WP Admin → Nastavenia → WCS GCal Sync
 *
 * Kroky inštalácie zobrazené v UI:
 *  1. Zadaj Google API credentials (Client ID + Secret)
 *  2. Prepoj konto cez OAuth
 *  3. Vyber cieľový kalendár
 *  4. Spusti manuálnu synchronizáciu (voliteľné)
 */
class WCS_GCal_Admin {

    public function __construct(
        private WCS_GCal_Auth $auth,
        private WCS_GCal_API  $api,
        private WCS_GCal_Sync $sync,
    ) {}

    // -----------------------------------------------------------------------
    // Registrácia hookov
    // -----------------------------------------------------------------------

    public function register_hooks(): void {
        add_action( 'admin_menu',            [ $this, 'add_menu_page' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_init',            [ $this, 'handle_oauth_callback' ] );
        add_action( 'admin_init',            [ $this, 'handle_manual_sync' ] );
        add_action( 'admin_init',            [ $this, 'handle_cancel_sync' ] );
        add_action( 'admin_init',            [ $this, 'handle_disconnect' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX endpoint pre priebeh hromadnej synchronizácie
        add_action( 'wp_ajax_wcs_gcal_sync_status', [ $this, 'ajax_sync_status' ] );

        // Zobraz stav sync v zozname podujatí
        add_filter( 'manage_class_posts_columns',       [ $this, 'add_gcal_column' ] );
        add_action( 'manage_class_posts_custom_column', [ $this, 'render_gcal_column' ], 10, 2 );
    }

    // -----------------------------------------------------------------------
    // Menu + nastavenia
    // -----------------------------------------------------------------------

    public function add_menu_page(): void {
        add_options_page(
            'WCS Google Calendar Sync',
            'WCS GCal Sync',
            'manage_options',
            'wcs-gcal-sync',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        // Credentials a výber kalendára MUSIA byť v samostatných skupinách:
        // options.php pri uložení nastaví všetky options danej skupiny, ktoré
        // vo formulári chýbajú, na null – jedna spoločná skupina preto pri
        // uložení výberu kalendára vymazala Client ID + Secret (a naopak).
        register_setting( 'wcs_gcal_credentials_group', WCS_GCal_Auth::OPTION_CLIENT_ID,     [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wcs_gcal_credentials_group', WCS_GCal_Auth::OPTION_CLIENT_SECRET, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wcs_gcal_calendar_group',    'wcs_gcal_calendar_id',              [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'settings_page_wcs-gcal-sync' ) {
            return;
        }
        wp_enqueue_style(
            'wcs-gcal-admin',
            WCS_GCAL_PLUGIN_URL . 'assets/admin.css',
            [],
            WCS_GCAL_VERSION
        );
    }

    // -----------------------------------------------------------------------
    // Spracovanie akcií
    // -----------------------------------------------------------------------

    /**
     * Spracuje Google OAuth callback (po presmerovaní z Google späť na stránku pluginu).
     */
    public function handle_oauth_callback(): void {
        // Musíme byť na stránke pluginu
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wcs-gcal-sync' ) {
            return;
        }
        if ( ! isset( $_GET['code'] ) ) {
            return;
        }

        // Overenie state (nonce) – ochrana pred CSRF
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
        if ( ! wp_verify_nonce( $state, 'wcs_gcal_oauth' ) ) {
            wp_die( 'Neplatný state parameter. Skúste to znova.' );
        }

        $code   = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $result = $this->auth->handle_callback( $code );

        $redirect = admin_url( 'options-general.php?page=wcs-gcal-sync' );

        if ( is_wp_error( $result ) ) {
            $redirect = add_query_arg( 'wcs_error', rawurlencode( $result->get_error_message() ), $redirect );
        } else {
            $redirect = add_query_arg( 'wcs_connected', '1', $redirect );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Spustí hromadnú synchronizáciu všetkých podujatí na pozadí.
     */
    public function handle_manual_sync(): void {
        if ( ! isset( $_POST['wcs_gcal_sync_all'] ) ) {
            return;
        }
        if ( ! check_admin_referer( 'wcs_gcal_sync_all' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $result = $this->sync->start_background_sync();

        $redirect = add_query_arg(
            'wcs_sync_started',
            (int) $result['total'],
            admin_url( 'options-general.php?page=wcs-gcal-sync' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Zruší bežiacu hromadnú synchronizáciu.
     */
    public function handle_cancel_sync(): void {
        if ( ! isset( $_GET['wcs_gcal_cancel_sync'] ) ) {
            return;
        }
        if ( ! check_admin_referer( 'wcs_gcal_cancel_sync' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->sync->cancel_background_sync();

        wp_safe_redirect( admin_url( 'options-general.php?page=wcs-gcal-sync&wcs_sync_cancelled=1' ) );
        exit;
    }

    /**
     * Vráti stav hromadnej synchronizácie ako JSON (pre polling z admin stránky).
     */
    public function ajax_sync_status(): void {
        check_ajax_referer( 'wcs_gcal_sync_status' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( null, 403 );
        }

        $job = $this->sync->get_job();

        if ( $job ) {
            // Popostrčí WP-Cron, keby dávka na pozadí zaspala
            spawn_cron();

            wp_send_json_success( [
                'running' => true,
                'total'   => $job['total'],
                'done'    => $job['total'] - count( $job['post_ids'] ),
                'success' => $job['success'],
                'errors'  => $job['errors'],
            ] );
        }

        wp_send_json_success( [
            'running' => false,
            'last'    => get_option( WCS_GCal_Sync::OPTION_LAST_RESULT ) ?: null,
        ] );
    }

    /**
     * Odpojí plugin od Google.
     */
    public function handle_disconnect(): void {
        if ( ! isset( $_GET['wcs_gcal_disconnect'] ) ) {
            return;
        }
        if ( ! check_admin_referer( 'wcs_gcal_disconnect' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->auth->disconnect();

        wp_safe_redirect( admin_url( 'options-general.php?page=wcs-gcal-sync&wcs_disconnected=1' ) );
        exit;
    }

    // -----------------------------------------------------------------------
    // Stĺpec v zozname podujatí
    // -----------------------------------------------------------------------

    public function add_gcal_column( array $columns ): array {
        $columns['wcs_gcal'] = 'GCal';
        return $columns;
    }

    public function render_gcal_column( string $column, int $post_id ): void {
        if ( $column !== 'wcs_gcal' ) {
            return;
        }
        $gcal_id = get_post_meta( $post_id, WCS_GCal_Sync::META_GCAL_EVENT_ID, true );
        if ( $gcal_id ) {
            echo '<span title="' . esc_attr( $gcal_id ) . '" style="color:#1a73e8;">&#10003; Synced</span>';
        } else {
            echo '<span style="color:#aaa;">—</span>';
        }
    }

    // -----------------------------------------------------------------------
    // Render admin stránky
    // -----------------------------------------------------------------------

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $connected     = $this->auth->is_connected();
        $client_id     = $this->auth->get_client_id();
        $client_secret = $this->auth->get_client_secret();
        $calendar_id     = (string) get_option( 'wcs_gcal_calendar_id', '' );
        $calendars       = [];
        $calendars_error = '';

        if ( $connected ) {
            $result = $this->api->get_calendars();
            if ( is_wp_error( $result ) ) {
                $calendars_error = $result->get_error_message();
            } elseif ( ! empty( $result['items'] ) ) {
                $calendars = $result['items'];
            }
        }

        $redirect_uri = $this->auth->get_redirect_uri();
        ?>
        <div class="wrap wcs-gcal-wrap">

            <h1 class="wcs-gcal-title">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="#1a73e8" style="vertical-align:middle;margin-right:8px"><path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v11zM5 7V6h14v1H5z"/></svg>
                WCS Google Calendar Sync
            </h1>

            <?php $this->render_notices(); ?>

            <div class="wcs-gcal-grid">

                <!-- ========== KARTA 1: Credentials ========== -->
                <div class="wcs-gcal-card">
                    <div class="wcs-gcal-card-header">
                        <span class="wcs-gcal-step">1</span>
                        Google API Credentials
                    </div>
                    <div class="wcs-gcal-card-body">
                        <p>
                            Vytvorte projekt v
                            <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>,
                            aktivujte <strong>Google Calendar API</strong> a vytvorte
                            <strong>OAuth 2.0 Client ID</strong> (typ: <em>Web application</em>).
                        </p>
                        <p>
                            Ako <strong>Authorized redirect URI</strong> zadajte presne:<br>
                            <code class="wcs-uri"><?php echo esc_html( $redirect_uri ); ?></code>
                        </p>

                        <form method="post" action="options.php">
                            <?php settings_fields( 'wcs_gcal_credentials_group' ); ?>
                            <table class="form-table wcs-form-table">
                                <tr>
                                    <th><label for="wcs_client_id">Client ID</label></th>
                                    <td>
                                        <input type="text"
                                               id="wcs_client_id"
                                               name="<?php echo esc_attr( WCS_GCal_Auth::OPTION_CLIENT_ID ); ?>"
                                               value="<?php echo esc_attr( $client_id ); ?>"
                                               class="regular-text"
                                               placeholder="xxxxxxxx.apps.googleusercontent.com" />
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="wcs_client_secret">Client Secret</label></th>
                                    <td>
                                        <input type="password"
                                               id="wcs_client_secret"
                                               name="<?php echo esc_attr( WCS_GCal_Auth::OPTION_CLIENT_SECRET ); ?>"
                                               value="<?php echo esc_attr( $client_secret ); ?>"
                                               class="regular-text" />
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button( 'Uložiť credentials', 'secondary' ); ?>
                        </form>
                    </div>
                </div>

                <!-- ========== KARTA 2: OAuth ========== -->
                <div class="wcs-gcal-card">
                    <div class="wcs-gcal-card-header">
                        <span class="wcs-gcal-step">2</span>
                        Prepojenie s Google
                    </div>
                    <div class="wcs-gcal-card-body">
                        <?php if ( $connected ) : ?>
                            <p class="wcs-status wcs-status-ok">
                                <span class="dashicons dashicons-yes-alt"></span>
                                Úspešne prepojené s Google Calendar
                            </p>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=wcs-gcal-sync&wcs_gcal_disconnect=1' ), 'wcs_gcal_disconnect' ) ); ?>"
                               class="button button-secondary"
                               onclick="return confirm('Naozaj odpojíte Google Calendar?')">
                                Odpojiť
                            </a>
                        <?php else : ?>
                            <p class="wcs-status wcs-status-error">
                                <span class="dashicons dashicons-no-alt"></span>
                                Neprepojené
                            </p>
                            <?php if ( $client_id && $client_secret ) : ?>
                                <a href="<?php echo esc_url( $this->auth->get_auth_url() ); ?>"
                                   class="button button-primary wcs-btn-google">
                                    Prihlásiť cez Google
                                </a>
                            <?php else : ?>
                                <p class="description">Najprv zadajte a uložte Client ID a Client Secret (krok 1).</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ========== KARTA 3: Výber kalendára ========== -->
                <?php if ( $connected ) : ?>
                <div class="wcs-gcal-card">
                    <div class="wcs-gcal-card-header">
                        <span class="wcs-gcal-step">3</span>
                        Cieľový kalendár
                    </div>
                    <div class="wcs-gcal-card-body">
                        <?php if ( empty( $calendars ) ) : ?>
                            <p class="wcs-status wcs-status-warn">
                                Nepodarilo sa načítať zoznam kalendárov.
                                Skontrolujte oprávnenia Google účtu.
                            </p>
                            <?php if ( $calendars_error ) : ?>
                                <p class="description">Odpoveď Google API: <code><?php echo esc_html( $calendars_error ); ?></code></p>
                            <?php endif; ?>
                        <?php else : ?>
                            <form method="post" action="options.php">
                                <?php settings_fields( 'wcs_gcal_calendar_group' ); ?>
                                <table class="form-table wcs-form-table">
                                    <tr>
                                        <th><label for="wcs_calendar_select">Kalendár</label></th>
                                        <td>
                                            <select id="wcs_calendar_select" name="wcs_gcal_calendar_id">
                                                <option value="">— vyberte —</option>
                                                <?php foreach ( $calendars as $cal ) : ?>
                                                    <option value="<?php echo esc_attr( $cal['id'] ); ?>"
                                                        <?php selected( $calendar_id, $cal['id'] ); ?>>
                                                        <?php echo esc_html( $cal['summary'] ?? $cal['id'] ); ?>
                                                        <?php if ( ! empty( $cal['primary'] ) ) echo ' (primárny)'; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ( $calendar_id ) : ?>
                                                <p class="description">Vybraný: <code><?php echo esc_html( $calendar_id ); ?></code></p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                                <?php submit_button( 'Uložiť výber', 'secondary' ); ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ========== KARTA 4: Manuálna synchronizácia ========== -->
                <?php if ( $connected && $calendar_id ) : ?>
                <div class="wcs-gcal-card">
                    <div class="wcs-gcal-card-header">
                        <span class="wcs-gcal-step">4</span>
                        Synchronizácia
                    </div>
                    <div class="wcs-gcal-card-body">
                        <?php $job = $this->sync->get_job(); ?>

                        <?php if ( $job ) : ?>
                            <?php
                            $done    = $job['total'] - count( $job['post_ids'] );
                            $percent = $job['total'] ? (int) round( $done / $job['total'] * 100 ) : 0;
                            ?>
                            <p>
                                Prebieha synchronizácia na pozadí – môžete túto stránku
                                zavrieť, synchronizácia bude pokračovať sama.
                            </p>
                            <div class="wcs-progress">
                                <div id="wcs-sync-bar" class="wcs-progress-bar" style="width:<?php echo $percent; ?>%"></div>
                            </div>
                            <p class="wcs-stats">
                                Spracovaných: <strong><span id="wcs-sync-done"><?php echo $done; ?></span></strong>
                                / <strong><?php echo (int) $job['total']; ?></strong> podujatí
                                (chyby: <span id="wcs-sync-errors"><?php echo (int) $job['errors']; ?></span>)
                            </p>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=wcs-gcal-sync&wcs_gcal_cancel_sync=1' ), 'wcs_gcal_cancel_sync' ) ); ?>"
                               class="button button-secondary"
                               onclick="return confirm('Naozaj zrušiť prebiehajúcu synchronizáciu?')">
                                Zrušiť synchronizáciu
                            </a>

                            <script>
                            ( function () {
                                var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) . '?action=wcs_gcal_sync_status&_ajax_nonce=' . wp_create_nonce( 'wcs_gcal_sync_status' ) ); ?>;
                                function poll() {
                                    fetch( url, { credentials: 'same-origin' } )
                                        .then( function ( r ) { return r.json(); } )
                                        .then( function ( res ) {
                                            if ( ! res.success ) { setTimeout( poll, 5000 ); return; }
                                            var d = res.data;
                                            if ( ! d.running ) { window.location.reload(); return; }
                                            document.getElementById( 'wcs-sync-done' ).textContent   = d.done;
                                            document.getElementById( 'wcs-sync-errors' ).textContent = d.errors;
                                            document.getElementById( 'wcs-sync-bar' ).style.width    = ( d.total ? Math.round( d.done / d.total * 100 ) : 0 ) + '%';
                                            setTimeout( poll, 3000 );
                                        } )
                                        .catch( function () { setTimeout( poll, 5000 ); } );
                                }
                                setTimeout( poll, 3000 );
                            } )();
                            </script>

                        <?php else : ?>
                            <p>
                                Automatická synchronizácia prebieha pri každom uložení podujatia.<br>
                                Toto tlačidlo synchronizuje <strong>všetky existujúce podujatia</strong>
                                po dávkach na pozadí (vhodné pri prvom spustení).
                            </p>
                            <form method="post">
                                <?php wp_nonce_field( 'wcs_gcal_sync_all' ); ?>
                                <button type="submit" name="wcs_gcal_sync_all" value="1" class="button button-primary">
                                    <span class="dashicons dashicons-update"></span>
                                    Synchronizovať všetky podujatia
                                </button>
                            </form>

                            <?php
                            $last_result = get_option( WCS_GCal_Sync::OPTION_LAST_RESULT );
                            if ( is_array( $last_result ) ) :
                                ?>
                                <p class="wcs-stats">
                                    Posledná synchronizácia (<?php echo esc_html( wp_date( 'j.n.Y H:i', (int) $last_result['finished'] ) ); ?>):
                                    <strong><?php echo (int) $last_result['success']; ?></strong> úspešne,
                                    <strong><?php echo (int) $last_result['errors']; ?></strong> chýb
                                </p>
                            <?php endif; ?>

                            <?php
                            // Štatistiky
                            $total_synced = (int) ( new WP_Query( [
                                'post_type'      => 'class',
                                'post_status'    => 'publish',
                                'posts_per_page' => -1,
                                'meta_query'     => [ [ 'key' => WCS_GCal_Sync::META_GCAL_EVENT_ID, 'compare' => 'EXISTS' ] ],
                                'fields'         => 'ids',
                            ] ) )->found_posts;

                            $total_all = (int) wp_count_posts( 'class' )->publish;
                            ?>
                            <p class="wcs-stats">
                                Synchronizovaných: <strong><?php echo $total_synced; ?></strong>
                                / <strong><?php echo $total_all; ?></strong> podujatí
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /.wcs-gcal-grid -->
        </div><!-- /.wrap -->
        <?php
    }

    // -----------------------------------------------------------------------
    // Flash správy
    // -----------------------------------------------------------------------

    private function render_notices(): void {
        if ( isset( $_GET['wcs_connected'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>&#10003; Google Calendar úspešne prepojený!</p></div>';
        }
        if ( isset( $_GET['wcs_disconnected'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>Google Calendar bol odpojený.</p></div>';
        }
        if ( isset( $_GET['wcs_error'] ) ) {
            $msg = esc_html( rawurldecode( (string) $_GET['wcs_error'] ) );
            echo '<div class="notice notice-error is-dismissible"><p>Chyba: ' . $msg . '</p></div>';
        }
        if ( isset( $_GET['wcs_sync_started'] ) ) {
            $total = (int) $_GET['wcs_sync_started'];
            echo "<div class='notice notice-success is-dismissible'><p>&#10003; Synchronizácia <strong>{$total}</strong> podujatí bola spustená na pozadí.</p></div>";
        }
        if ( isset( $_GET['wcs_sync_cancelled'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>Synchronizácia bola zrušená.</p></div>';
        }
    }
}
