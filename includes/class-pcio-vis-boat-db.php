<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom admin tables; caching would hide real-time changes. Schema changes are intentional install/uninstall routines.

/**
 * Database layer for PCIO VIS Boat.
 *
 * Tables (all prefixed with $wpdb->prefix)
 * ─────────────────────────────────────────
 *   me_boat_boats  – one row per physical boat. `member_number` is the
 *                    registered owner (FK to the core members table). The home
 *                    harbour is stored as `harbour_id` only; the name is
 *                    resolved by joining me_boat_harbours.
 *
 */
class PCIO_VIS_Boat_DB {

	const TABLE_BOATS = 'me_boat_boats';
	const DB_VERSION  = '1.3.0';

	public static function t_boats(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BOATS;
	}

	// ── Schema ────────────────────────────────────────────────────

	/**
	 * Create tables on first install / version bump.
	 *
	 * Uses $wpdb->prepare with %i (WP 6.2+ table-identifier placeholder) so the
	 * table name is safely quoted without touching dbDelta (which cannot accept
	 * prepared SQL).
	 *
	 * Because CREATE TABLE IF NOT EXISTS is a no-op on an existing table, every
	 * structural change between releases is applied by migrate_to_current(),
	 * which runs before the harbours table is touched.
	 *
	 * The stored version is only a fast path, not the source of truth: the live
	 * schema is checked as well. A release that recorded its version after a
	 * failed migration would otherwise leave the site permanently half-migrated
	 * with no way to recover short of deleting the option by hand.
	 */
	public static function install(): void {
		if ( get_option( 'pcio_me_boat_db_version' ) === self::DB_VERSION && self::schema_is_current() ) {
			return;
		}

		global $wpdb;

		self::query_schema(
			$wpdb->prepare(
				"CREATE TABLE IF NOT EXISTS %i (
					id              INT          NOT NULL AUTO_INCREMENT,
					harbour_id      INT          NOT NULL DEFAULT 0,
					boat_name       VARCHAR(200) NOT NULL DEFAULT '',
					boat_type       VARCHAR(100) NOT NULL DEFAULT '',
					boat_model      VARCHAR(100) NOT NULL DEFAULT '',
					build_year      SMALLINT              DEFAULT NULL,
					sail_id         VARCHAR(50)  NOT NULL DEFAULT '',
					mmsi            VARCHAR(20)  NOT NULL DEFAULT '',
					member_number   INT          NULL     DEFAULT NULL,
					note            TEXT,
					created         DATETIME     DEFAULT CURRENT_TIMESTAMP,
					updated         DATETIME     DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					UNIQUE KEY idx_boat_owner (member_number),
					KEY idx_boat_harbour (harbour_id)
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
				self::t_boats()
			)
		);

		// The harbours table has to exist and be populated before the boats migration
		// resolves legacy home_harbour text against it, so both happen first.
		PCIO_VIS_Boat_Harbours_DB::install();
		PCIO_VIS_Boat_Harbours_DB::seed();

		self::migrate_to_current();

		// Only mark the schema as done once it is actually in place. Storing the
		// version after a failed migration would make install() a no-op from then
		// on, leaving the site permanently half-migrated with no way back.
		if ( self::schema_is_current() ) {
			update_option( 'pcio_me_boat_db_version', self::DB_VERSION );
			return;
		}

		self::schema_error( 'schema is not fully migrated; pcio_me_boat_db_version was left unchanged so this retries on the next admin page load' );
	}

	/**
	 * Whether the boats table already carries the columns the rest of the code
	 * assumes. One query, run on admin_init.
	 */
	private static function schema_is_current(): bool {
		global $wpdb;

		return 2 === (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ( %s, %s )',
			self::t_boats(),
			'member_number',
			'harbour_id'
		) );
	}

	/**
	 * Bring an existing boats table up to the current schema.
	 *
	 * Idempotent — every step checks the live table first, so this is safe to run
	 * on a fresh install, on 1.0.x, and on a partially migrated site:
	 *
	 *  1. rename `owner_member_id` to `member_number`, preserving the values;
	 *  2. add `harbour_id` when missing;
	 *  3. convert rows that still hold a member row id instead of a membership
	 *     number over to the real membership number;
	 *  4. backfill `harbour_id` from the legacy free-text `home_harbour` column
	 *     and then drop that column;
	 *  5. make `member_number` unique;
	 *  6. drop the `me_boat_crew` table, which 1.1 replaces with the core
	 *     member_number crew group.
	 */
	private static function migrate_to_current(): void {
		global $wpdb;

		$boats = self::t_boats();

		// 1. owner_member_id -> member_number, in place so no row is recreated.
		//    This runs first because the harbour_id column is positioned after it.
		//    NULL (not NOT NULL) is required: a boat with no owner membership
		//    number is legitimate, and the unique index added in step 5 relies on
		//    MySQL permitting any number of NULLs in a UNIQUE column. MySQL rejects
		//    "NOT NULL DEFAULT NULL" outright, so this must stay as written.
		if ( ! self::has_column( $boats, 'member_number' ) && self::has_column( $boats, 'owner_member_id' ) ) {
			self::query_schema(
				$wpdb->prepare(
					'ALTER TABLE %i CHANGE COLUMN owner_member_id member_number INT NULL DEFAULT NULL',
					$boats
				)
			);
		}

		if ( ! self::has_column( $boats, 'member_number' ) ) {
			// The rename failed — most likely because the host blocked ALTER TABLE.
			// Carry on with the steps that do not depend on it so a later run has
			// less to do, and leave the option untouched so this retries.
			self::schema_error( 'could not create member_number on ' . $boats . '; boats cannot be read until this succeeds' );
		}

		// 2. harbour_id.
		if ( ! self::has_column( $boats, 'harbour_id' ) ) {
			self::query_schema(
				$wpdb->prepare(
					'ALTER TABLE %i ADD COLUMN harbour_id INT NOT NULL DEFAULT 0 AFTER member_number, ADD KEY idx_boat_harbour (harbour_id)',
					$boats
				)
			);
		}

		// 3-5. Value fixups and constraints.
		if ( self::has_column( $boats, 'member_number' ) ) {
			self::convert_owner_ids_to_member_numbers( $boats );
			self::add_unique_member_number_index( $boats );
		}
		self::backfill_and_drop_home_harbour( $boats );

		// 6. Legacy crew table — crew is now the core member_number group.
		self::query_schema( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'me_boat_crew' ) );
	}

	/**
	 * Run a schema statement, keeping WordPress from printing the error.
	 *
	 * A migration must not take the whole admin request down when a host refuses
	 * an ALTER, but the failure still has to be visible: swallowing these silently
	 * is what left a 1.1 upgrade half-applied with no trace in the log. Failures
	 * are written to the PHP error log with the statement that produced them.
	 */
	private static function query_schema( string $sql ): void {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->query( $sql );
		$last     = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			self::schema_error( $last . ' — ' . preg_replace( '/\s+/', ' ', $sql ) );
		}
	}

	/** Surface a schema migration problem in the PHP error log. */
	private static function schema_error( string $message ): void {
		error_log( 'pcio-vis-boat: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surfaced in the server log.
	}

	/**
	 * Whether a column exists on a table.
	 */
	private static function has_column( string $table, string $column ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SHOW COLUMNS FROM %i LIKE %s',
			$table,
			$column
		) );
	}

	/**
	 * Rows written before the owner key was corrected hold the member row id in
	 * `member_number` rather than the membership number.
	 *
	 * A value is only rewritten when it matches no existing membership number but
	 * does match a member row id, so an already-correct value is never touched and
	 * an ambiguous value (a number that is both an id and a membership number)
	 * keeps its current value.
	 */
	private static function convert_owner_ids_to_member_numbers( string $table ): void {
		global $wpdb;

		if ( ! class_exists( 'PCIO_VIS_DB' ) ) {
			return;
		}

		$members = PCIO_VIS_DB::table();
		if ( ! self::has_column( $members, 'member_number' ) ) {
			return;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.id AS boat_id, b.member_number AS value
				 FROM %i b
				 WHERE b.member_number IS NOT NULL AND b.member_number > 0
				   AND NOT EXISTS (
					   SELECT 1 FROM %i m WHERE m.member_number = b.member_number
				   )
				   AND EXISTS (
					   SELECT 1 FROM %i m2 WHERE m2.id = b.member_number
				   )',
				$table,
				$members,
				$members
			),
			ARRAY_A
		) ?: [];

		foreach ( $rows as $row ) {
			$number = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT member_number FROM %i WHERE id = %d',
				$members,
				(int) $row['value']
			) );
			if ( $number > 0 ) {
				$wpdb->update( $table, [ 'member_number' => $number ], [ 'id' => (int) $row['boat_id'] ], [ '%d' ], [ '%d' ] );
			}
		}
	}

	/**
	 * Resolve `harbour_id` from the legacy free-text `home_harbour` column, then
	 * drop the column.
	 *
	 * Names that do not match an existing harbour are left unassigned and written
	 * to the server log: the harbour itself is not invented, because a row with
	 * no coordinates would show up on the public map at 0,0. Those boats stay in
	 * the register with no home harbour until an administrator assigns one at
	 * /vis/harbours.
	 */
	private static function backfill_and_drop_home_harbour( string $table ): void {
		global $wpdb;

		if ( ! self::has_column( $table, 'home_harbour' ) || ! self::has_column( $table, 'harbour_id' ) ) {
			return;
		}

		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT home_harbour FROM %i WHERE home_harbour <> %s',
				$table,
				''
			)
		) ?: [];

		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$harbour_id = PCIO_VIS_Boat_Harbours_DB::find_id_by_name( $name );
			if ( $harbour_id > 0 ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE %i SET harbour_id = %d WHERE harbour_id = 0 AND home_harbour = %s',
					$table,
					$harbour_id,
					$name
				) );
				continue;
			}
			self::schema_error( sprintf(
				'home harbour "%s" did not match any harbour, so its boats were left without a home harbour. Add it under /vis/harbours and assign the boats.',
				$name
			) );
		}

		self::query_schema( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN home_harbour', $table ) );
	}

	/**
	 * Make `member_number` unique — one boat per membership number.
	 *
	 * Boats already present under a duplicated number are kept: the surplus rows
	 * have their owner key set to NULL (which the unique index permits) and are
	 * logged, so no boat record is destroyed by the migration.
	 */
	private static function add_unique_member_number_index( string $table ): void {
		global $wpdb;

		// Already unique — nothing to do.
		$unique = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0',
			$table,
			'idx_boat_owner'
		) );
		if ( $unique ) {
			return;
		}

		$dupes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT member_number, COUNT(*) AS cnt FROM %i
				 WHERE member_number IS NOT NULL
				 GROUP BY member_number HAVING cnt > 1',
				$table
			),
			ARRAY_A
		) ?: [];

		// Keep the lowest boat id per number; NULL the rest (unique allows many NULLs).
		foreach ( $dupes as $dupe ) {
			$number = (int) $dupe['member_number'];
			$keep   = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT MIN(id) FROM %i WHERE member_number = %d',
				$table,
				$number
			) );
			$wpdb->query( $wpdb->prepare(
				'UPDATE %i SET member_number = NULL WHERE member_number = %d AND id <> %d',
				$table,
				$number,
				$keep
			) );
			self::schema_error( sprintf(
				'member number %d owned %d boats. The extra boats were kept but unassigned — reassign them at /vis/boats.',
				$number,
				(int) $dupe['cnt']
			) );
		}

		// Drop the 1.0.x non-unique index of the same name before re-adding it.
		self::query_schema( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX idx_boat_owner', $table ) );

		self::query_schema( $wpdb->prepare(
			'ALTER TABLE %i ADD UNIQUE KEY idx_boat_owner (member_number)',
			$table
		) );
	}

	/**
	 * Drop every table this plugin owns, including the legacy me_boat_crew
	 * table left behind by installs older than 1.1. Not called by the default
	 * uninstall — see uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'me_boat_crew' ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::t_boats() ) );
		if ( class_exists( 'PCIO_VIS_Boat_Harbours_DB' ) ) {
			PCIO_VIS_Boat_Harbours_DB::drop_table();
		}
	}

	// ── Boats ─────────────────────────────────────────────────────

	/**
	 * All boats, optionally filtered by owner, ordered by boat name.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_boats( int $member_number = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, h.name AS home_harbour
				 FROM %i b
				 LEFT JOIN %i h ON h.id = b.harbour_id
				 WHERE ( %d = 0 OR b.member_number = %d )
				 ORDER BY b.boat_name ASC',
				self::t_boats(),
				PCIO_VIS_Boat_Harbours_DB::table_name(),
				$member_number,
				$member_number
			),
			ARRAY_A
		);
		return array_map( [ self::class, 'shape_boat' ], $rows ?: [] );
	}

	public static function get_boat_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, h.name AS home_harbour
				 FROM %i b
				 LEFT JOIN %i h ON h.id = b.harbour_id
				 WHERE b.id = %d',
				self::t_boats(),
				PCIO_VIS_Boat_Harbours_DB::table_name(),
				$id
			),
			ARRAY_A
		);
		return $row ? self::shape_boat( $row ) : null;
	}

/**
	 * Get the boat registered for a membership number (owner or co-owner group).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_boat_by_member_number( int $member_number ): ?array {
		if ( $member_number <= 0 ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, h.name AS home_harbour
				 FROM %i b
				 LEFT JOIN %i h ON h.id = b.harbour_id
				 WHERE b.member_number = %d
				 LIMIT 1',
				self::t_boats(),
				PCIO_VIS_Boat_Harbours_DB::table_name(),
				$member_number
			),
			ARRAY_A
		);
		return $row ? self::shape_boat( $row ) : null;
	}

	/**
	 * Create a boat. Returns the new id, or false on failure.
	 *
	 * @return int|false
	 */
	public static function create_boat( int $member_number, array $data ) {
		global $wpdb;
		$clean = self::sanitize_boat( $data );
		$clean['member_number'] = $member_number;
		$wpdb->insert( self::t_boats(), $clean, self::boat_formats( $clean ) );
		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	public static function update_boat( int $id, array $data ): bool {
		global $wpdb;
		$clean = self::sanitize_boat( $data );
		if ( ! $clean ) {
			return false;
		}
		$clean['updated'] = current_time( 'mysql' );
		return false !== $wpdb->update(
			self::t_boats(),
			$clean,
			[ 'id' => $id ],
			self::boat_formats( $clean ),
			[ '%d' ]
		);
	}

	/**
	 * Move a boat row onto a different membership number.
	 *
	 * Rows written before the owner key was corrected hold the member row id in
	 * `member_number`; this re-keys such a row onto the member's real number.
	 */
	public static function rekey_boat( int $id, int $member_number ): bool {
		global $wpdb;
		if ( $id <= 0 || $member_number <= 0 ) {
			return false;
		}
		return false !== $wpdb->update(
			self::t_boats(),
			[
				'member_number' => $member_number,
				'updated'       => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Create or update the boat owned by a membership number.
	 *
	 * Idempotent: an existing boat for that number is updated in place, so a
	 * repeated save (sign-up, then a later profile edit) never creates a second
	 * row.
	 *
	 * Creating a harbour row is opt-in. A harbour placed on the map
	 * (`new_harbour_*`) is only inserted when the caller passes
	 * $allow_new_harbour, which callers set only for a confirmed payment or an
	 * authenticated member. Without that proof the map placement is discarded and
	 * the boat keeps whatever harbour it already had — an anonymous form post can
	 * therefore never add rows to the shared harbour list behind the public map.
	 *
	 * @param int   $member_number     Membership number the boat belongs to.
	 * @param array $data              Raw boat fields.
	 * @param bool  $allow_new_harbour Whether the caller may create a new harbour row.
	 * @return int|false Boat id on success, false when nothing was saved.
	 */
	public static function save_boat_for_number( int $member_number, array $data, bool $allow_new_harbour = false ) {
		if ( $member_number <= 0 ) {
			return false;
		}

		$clean = self::sanitize_boat( $data );
		if ( '' === ( $clean['boat_name'] ?? '' ) ) {
			return false;
		}

		$new_name = substr( sanitize_text_field( (string) ( $data['new_harbour_name'] ?? '' ) ), 0, 128 );
		$new_lat  = ( isset( $data['new_harbour_lat'] ) && '' !== $data['new_harbour_lat'] ) ? (float) $data['new_harbour_lat'] : null;
		$new_lon  = ( isset( $data['new_harbour_lon'] ) && '' !== $data['new_harbour_lon'] ) ? (float) $data['new_harbour_lon'] : null;
		if ( '' !== $new_name && null !== $new_lat && null !== $new_lon
			&& $new_lat >= -90 && $new_lat <= 90 && $new_lon >= -180 && $new_lon <= 180 ) {
			if ( $allow_new_harbour && class_exists( 'PCIO_VIS_Boat_Harbours_DB' ) ) {
				$created = PCIO_VIS_Boat_Harbours_DB::create( $new_name, $new_lat, $new_lon );
				if ( $created > 0 ) {
					$clean['harbour_id'] = $created;
				}
			} elseif ( '' !== $new_name ) {
				// Discarded on purpose — see the method docblock.
				error_log( 'pcio-vis-boat: harbour "' . $new_name . '" not created for member number ' . $member_number . ' (caller not allowed to create harbours).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surfaced in the server log.
			}
		}

		$existing = self::get_boat_by_member_number( $member_number );
		if ( $existing ) {
			return self::update_boat( (int) $existing['id'], $clean ) ? (int) $existing['id'] : false;
		}
		return self::create_boat( $member_number, $clean );
	}

	/**
	 * Delete a boat. Crew membership lives on the shared member_number in the
	 * core members table, so there is no separate crew link to clean up.
	 */
	public static function delete_boat( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::t_boats(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Delete the boat owned by a member unless there are other members with this member_number.
	 * Called when the core deletes a member.
	 */
	public static function delete_member_data( int $member_id ): void {
		$member = class_exists( 'PCIO_VIS_DB' ) ? PCIO_VIS_DB::get( $member_id ) : null;
		if ( ! $member ) {
			return;
		}
		$member_number = $member['member_number'];
		if ( ! $member_number ) {
			return;
		}
		$boat = self::get_boat_by_member_number( $member_number );
		if ( ! $boat ) {
			return;
		}
		$owners = PCIO_VIS_DB::get_member_ids_by_member_number( $member_number );
		if ( count( $owners ) <= 1 ) {
			self::delete_boat( (int) $boat['id'] );
		}
	}

	// ── Crew ──────────────────────────────────────────────────────

	/**
	 * All members sharing the boat owner's membership number (the crew group).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_crew( int $boat_id ): array {
		$boat = self::get_boat_by_id( $boat_id );
		if ( ! $boat ) {
			return [];
		}
		$owners = class_exists( 'PCIO_VIS_DB' )
			? PCIO_VIS_DB::get_members_by_member_number( (int) $boat['member_number'] )
			: [];
		return array_map( [ self::class, 'shape_crew' ], $owners ?: [] );
	}

	// ── Internal helpers ──────────────────────────────────────────

	/**
	 * @return array<string,mixed>
	 */
	private static function shape_boat( array $row ): array {
		return [
			'id'              => (int) $row['id'],
			// Derived: the harbour name comes from the harbours table via the join
			// in the read queries, never from a column on the boat row.
			'home_harbour'    => (string) ( $row['home_harbour'] ?? '' ),
			'boat_name'       => (string) $row['boat_name'],
			'boat_type'       => (string) $row['boat_type'],
			'boat_model'      => (string) $row['boat_model'],
			'build_year'      => isset( $row['build_year'] ) && $row['build_year'] !== null
								? (int) $row['build_year']
								: null,
			'sail_id'         => (string) $row['sail_id'],
			'mmsi'            => (string) $row['mmsi'],
			'member_number'   => (int) $row['member_number'],
			'harbour_id'      => (int) ( $row['harbour_id'] ?? 0 ),
			'note'            => (string) ( $row['note'] ?? '' ),
			'created'         => (string) $row['created'],
			'updated'         => (string) $row['updated'],
		];
	}

	/**
	 * Shape a core member row into a crew row.
	 *
	 * @param array<string,mixed> $row Row from the core members table.
	 * @return array<string,mixed>
	 */
	private static function shape_crew( array $row ): array {
		return [
			'member_id'       => (int) $row['id'],
			'member_number'   => (int) $row['member_number'],
			'subscription_id' => 0,
			'created'         => (string) ( $row['created_at'] ?? '' ),
		];
	}

	/**
	 * Sanitize an incoming boat data payload to writable fields only.
	 * `id`, `member_number`, `created`, `updated` are excluded.
	 *
	 * @return array<string,mixed>
	 */
	private static function sanitize_boat( array $data ): array {
		$out = [];

		$varchar_fields = [
			'boat_name'    => 200,
			'boat_type'    => 100,
			'boat_model'   => 100,
			'sail_id'      => 50,
			'mmsi'         => 20,
		];

		if ( array_key_exists( 'harbour_id', $data ) ) {
			$out['harbour_id'] = absint( $data['harbour_id'] );
		}

		foreach ( $varchar_fields as $key => $max_len ) {
			if ( array_key_exists( $key, $data ) ) {
				$out[ $key ] = substr( sanitize_text_field( (string) $data[ $key ] ), 0, $max_len );
			}
		}

		if ( array_key_exists( 'build_year', $data ) ) {
			$val              = $data['build_year'];
			$out['build_year'] = ( $val !== null && $val !== '' ) ? (int) $val : null;
		}

		if ( array_key_exists( 'note', $data ) ) {
			$out['note'] = sanitize_textarea_field( (string) $data['note'] );
		}

		return $out;
	}

	/**
	 * Return a format array (for $wpdb->insert / update) matching the given
	 * data keys.
	 *
	 * @return string[]
	 */
	private static function boat_formats( array $data ): array {
		$int_keys = [ 'id', 'member_number', 'harbour_id', 'build_year' ];
		$formats  = [];
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $int_keys, true ) ) {
				$formats[] = ( $value === null ) ? '%s' : '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}
}
