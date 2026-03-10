<?php
/**
 * LVB_Database – installs and manages database tables.
 *
 * This class is responsible for:
 *  - Creating (and migrating) all custom database tables on plugin activation.
 *  - Providing a thin generic CRUD layer (get_all, get_by_id, insert, update,
 *    delete, count) that prefixes table names automatically and returns plain
 *    associative arrays.
 *  - Offering specialised query methods for cross-table lookups such as
 *    fetching bookings with joined customer/service/staff data.
 *
 * All table names follow the pattern `{wpdb->prefix}lvb_{table}`, e.g.
 * `wp_lvb_bookings`. The generic helpers accept the short name without the
 * prefix or `lvb_` segment (e.g. 'bookings', 'services').
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides database installation and generic CRUD helpers for all LVB tables.
 *
 * @package LakeVision_Booking
 */
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
     * Fetch all rows from an LVB table with an optional WHERE clause.
     *
     * @param string $table    Short table name without the `{prefix}lvb_` portion
     *                         (e.g. 'services', 'bookings').
     * @param array  $where    Associative array of column => value equality conditions.
     *                         All conditions are AND-ed together. Pass an empty array
     *                         to return every row.
     * @param string $order_by ORDER BY clause appended verbatim (not user-supplied
     *                         at runtime, so no additional escaping is needed).
     * @return array[]  Array of associative row arrays, or an empty array.
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
     * Fetch a single row by its primary key.
     *
     * @param string $table  Short table name (e.g. 'bookings', 'customers').
     * @param int    $id     The primary key value to look up.
     * @return array|null    Associative row array, or null if no row is found.
     */
    public static function get_by_id( $table, $id ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tbl WHERE id = %d", $id ), ARRAY_A );
    }

    /**
     * Insert a row into an LVB table and return the new auto-increment ID.
     *
     * @param string  $table  Short table name (e.g. 'bookings').
     * @param array   $data   Associative array of column => value pairs to insert.
     * @return int|false      The new row ID on success, or false if the insert failed.
     */
    public static function insert( $table, $data ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        $wpdb->insert( $tbl, $data );
        return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update rows in an LVB table that match the given WHERE conditions.
     *
     * @param string $table  Short table name (e.g. 'bookings').
     * @param array  $data   Associative array of column => value pairs to set.
     * @param array  $where  Associative array of column => value equality conditions
     *                       used to identify rows to update.
     * @return int|false     Number of rows updated, 0 if no rows matched, or false
     *                       on database error.
     */
    public static function update( $table, $data, $where ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        return $wpdb->update( $tbl, $data, $where );
    }

    /**
     * Delete rows from an LVB table that match the given WHERE conditions.
     *
     * @param string $table  Short table name (e.g. 'bookings').
     * @param array  $where  Associative array of column => value equality conditions.
     * @return int|false     Number of rows deleted, or false on database error.
     */
    public static function delete( $table, $where ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lvb_' . $table;
        return $wpdb->delete( $tbl, $where );
    }

    /**
     * Count rows in an LVB table with optional WHERE conditions.
     *
     * @param string $table  Short table name (e.g. 'bookings').
     * @param array  $where  Associative array of column => value equality conditions.
     *                       Pass an empty array to count all rows.
     * @return int  The number of matching rows.
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
     * Get all active services assigned to a specific staff member.
     *
     * Performs an INNER JOIN on the staff_services pivot table and filters to
     * only services with status = 'active', ordered by name ascending.
     *
     * @param int $staff_id  The staff member ID.
     * @return array[]       Array of service row arrays, or an empty array.
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
     * Get all active staff members assigned to a specific service.
     *
     * Performs an INNER JOIN on the staff_services pivot table and filters to
     * only staff with status = 'active', ordered by name ascending.
     *
     * @param int $service_id  The service ID.
     * @return array[]         Array of staff row arrays, or an empty array.
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
     * Fetch bookings with joined service, staff, and customer data.
     *
     * Supports optional status filtering, a free-text search against customer
     * name/email, and full pagination. Intended for the admin bookings list view.
     *
     * @param array $args {
     *     Optional query arguments.
     *
     *     @type string $status   Filter by booking status ('pending', 'confirmed',
     *                            'cancelled'). Empty string = all statuses.
     *     @type string $search   Free-text search against customer first_name,
     *                            last_name, and email.
     *     @type int    $per_page Number of results per page. Default 20.
     *     @type int    $page     1-based page number. Default 1.
     *     @type string $order_by Column expression to sort by. Default 'b.start_datetime'.
     *     @type string $order    Sort direction: 'ASC' or 'DESC'. Default 'DESC'.
     * }
     * @return array[]  Array of booking row arrays enriched with service_name,
     *                  staff_name, first_name, last_name, customer_email columns.
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
     * Count bookings matching the given filter arguments (for pagination).
     *
     * Accepts the same `status` and `search` keys as {@see get_bookings()} so
     * callers can derive the total page count without fetching all rows.
     *
     * @param array $args {
     *     Optional filter arguments.
     *
     *     @type string $status  Filter by booking status.
     *     @type string $search  Free-text search against customer fields.
     * }
     * @return int  Total number of bookings matching the filters.
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
