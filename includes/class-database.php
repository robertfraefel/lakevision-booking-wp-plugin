<?php
/**
 * LVB_Database – installs and manages database tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Database {

    /**
     * Run on plugin activation via register_activation_hook.
     */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $tables = [];

        // Services
        $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lvb_services (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL,
            description TEXT            DEFAULT NULL,
            duration    INT UNSIGNED    NOT NULL DEFAULT 60  COMMENT 'minutes',
            price       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            buffer_time INT UNSIGNED    NOT NULL DEFAULT 0   COMMENT 'minutes after booking',
            status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        // Staff
        $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lvb_staff (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL,
            email       VARCHAR(255)    DEFAULT NULL,
            phone       VARCHAR(50)     DEFAULT NULL,
            calendar_id VARCHAR(255)    DEFAULT NULL COMMENT 'Google Calendar ID for this staff member',
            status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        // Staff <-> Services pivot
        $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lvb_staff_services (
            staff_id    BIGINT UNSIGNED NOT NULL,
            service_id  BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (staff_id, service_id)
        ) $charset;";

        // Customers
        $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lvb_customers (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name  VARCHAR(100)    NOT NULL,
            last_name   VARCHAR(100)    NOT NULL,
            email       VARCHAR(255)    NOT NULL,
            phone       VARCHAR(50)     DEFAULT NULL,
            notes       TEXT            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) $charset;";

        // Bookings
        $tables[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lvb_bookings (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id       BIGINT UNSIGNED NOT NULL,
            staff_id         BIGINT UNSIGNED DEFAULT NULL,
            customer_id      BIGINT UNSIGNED NOT NULL,
            start_datetime   DATETIME        NOT NULL,
            end_datetime     DATETIME        NOT NULL,
            status           ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
            google_event_id  VARCHAR(255)    DEFAULT NULL,
            price            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            notes            TEXT            DEFAULT NULL,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_service  (service_id),
            KEY idx_staff    (staff_id),
            KEY idx_customer (customer_id),
            KEY idx_status   (status),
            KEY idx_start    (start_datetime)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }

        // Add sort_order column if missing (migration for existing installs)
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}lvb_services LIKE 'sort_order'" );
        if ( empty( $cols ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}lvb_services ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER status" );
            // Initialize sort_order based on current name order
            $rows = $wpdb->get_results( "SELECT id FROM {$wpdb->prefix}lvb_services ORDER BY name ASC", ARRAY_A );
            foreach ( $rows as $i => $row ) {
                $wpdb->update( "{$wpdb->prefix}lvb_services", [ 'sort_order' => $i + 1 ], [ 'id' => $row['id'] ] );
            }
        }

        update_option( 'lvb_db_version', LVB_VERSION );
    }

    /**
     * Generic get-all with optional WHERE clause.
     */
    public static function get_all( $table, $where = [], $order_by = 'id DESC' ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        $sql = "SELECT * FROM $tbl";
        if ( ! empty( $where ) ) {
            $conditions = [];
            foreach ( $where as $col => $val ) {
                $conditions[] = $wpdb->prepare( "`$col` = %s", $val );
            }
            $sql .= ' WHERE ' . implode( ' AND ', $conditions );
        }
        $sql .= " ORDER BY $order_by";
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get a single row by ID.
     */
    public static function get_by_id( $table, $id ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE id = %d", $id ), ARRAY_A );
    }

    /**
     * Insert a row and return the new ID (or false).
     */
    public static function insert( $table, $data ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        $wpdb->insert( $tbl, $data );
        return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update rows matching $where.
     */
    public static function update( $table, $data, $where ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        return $wpdb->update( $tbl, $data, $where );
    }

    /**
     * Delete rows matching $where.
     */
    public static function delete( $table, $where ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        return $wpdb->delete( $tbl, $where );
    }

    /**
     * Count rows in a table with optional where.
     */
    public static function count( $table, $where = [] ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        $sql = "SELECT COUNT(*) FROM $tbl";
        if ( ! empty( $where ) ) {
            $conditions = [];
            foreach ( $where as $col => $val ) {
                $conditions[] = $wpdb->prepare( "`$col` = %s", $val );
            }
            $sql .= ' WHERE ' . implode( ' AND ', $conditions );
        }
        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Get services assigned to a staff member.
     */
    public static function get_services_for_staff( $staff_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT s.* FROM {$wpdb->prefix}lvb_services s
             INNER JOIN {$wpdb->prefix}lvb_staff_services ss ON ss.service_id = s.id
             WHERE ss.staff_id = %d AND s.status = 'active'
             ORDER BY s.name ASC",
            $staff_id
        );
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Get staff assigned to a service.
     */
    public static function get_staff_for_service( $service_id ) {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT st.* FROM {$wpdb->prefix}lvb_staff st
             INNER JOIN {$wpdb->prefix}lvb_staff_services ss ON ss.staff_id = st.id
             WHERE ss.service_id = %d AND st.status = 'active'
             ORDER BY st.name ASC",
            $service_id
        );
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Full bookings query with joins, search and pagination.
     */
    public static function get_bookings( $args = [] ) {
        global $wpdb;
        $defaults = [
            'status'   => '',
            'search'   => '',
            'per_page' => 20,
            'page'     => 1,
            'order_by' => 'b.start_datetime',
            'order'    => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );

        $where  = [];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'b.status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $offset = ( (int) $args['page'] - 1 ) * (int) $args['per_page'];
        $order  = in_array( strtoupper( $args['order'] ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $args['order'] ) : 'DESC';

        $sql = "SELECT b.*,
                    sv.name AS service_name,
                    st.name AS staff_name,
                    c.first_name, c.last_name, c.email AS customer_email
                FROM {$wpdb->prefix}lvb_bookings b
                LEFT JOIN {$wpdb->prefix}lvb_services sv ON sv.id = b.service_id
                LEFT JOIN {$wpdb->prefix}lvb_staff st    ON st.id = b.staff_id
                LEFT JOIN {$wpdb->prefix}lvb_customers c ON c.id  = b.customer_id
                $where_sql
                ORDER BY {$args['order_by']} $order
                LIMIT %d OFFSET %d";

        $params[] = (int) $args['per_page'];
        $params[] = $offset;

        if ( $params ) {
            $sql = $wpdb->prepare( $sql, ...$params );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Count bookings (for pagination).
     */
    public static function count_bookings( $args = [] ) {
        global $wpdb;
        $where  = [];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'b.status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}lvb_bookings b
                LEFT JOIN {$wpdb->prefix}lvb_customers c ON c.id = b.customer_id
                $where_sql";

        if ( $params ) {
            $sql = $wpdb->prepare( $sql, ...$params );
        }

        return (int) $wpdb->get_var( $sql );
    }
}
