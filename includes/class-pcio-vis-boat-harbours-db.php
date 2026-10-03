<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom admin table; caching would hide real-time changes.

/**
 * Database layer for the me_boat_harbours table.
 */
class PCIO_VIS_Boat_Harbours_DB {

	const TABLE = 'me_boat_harbours';

	public static function t(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** Table name for use in queries from other classes. */
	public static function table_name(): string {
		return self::t();
	}

	public static function install(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'CREATE TABLE IF NOT EXISTS %i (
					id   INT          NOT NULL AUTO_INCREMENT,
					name VARCHAR(128) NOT NULL DEFAULT \'\',
					lat  DOUBLE       NOT NULL DEFAULT 0,
					lon  DOUBLE       NOT NULL DEFAULT 0,
					PRIMARY KEY (id),
					KEY idx_harbour_name (name(32))
				) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
				self::t()
			)
		);

		self::maybe_dedupe_names();
		self::add_unique_index();
	}

	/**
	 * Drop the harbours table. Used by PCIO_VIS_Boat_DB::drop_tables().
	 */
	public static function drop_table(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::t() ) );
	}

	/**
	 * Deduplicate harbour names so the unique index can be created.
	 *
	 * Groups by the collated name, keeps the lowest id as the keeper, repoints
	 * every `me_boat_boats` row from the duplicate to the keeper, then deletes
	 * the duplicate harbour rows. Must run before add_unique_index().
	 */
	public static function maybe_dedupe_names(): void {
		global $wpdb;

		$tbl    = self::t();
		$boats  = PCIO_VIS_Boat_DB::t_boats();
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );

		// Nothing to repoint when the harbours or boats table is not there yet.
		if ( ! $exists ) {
			return;
		}
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $boats, 'harbour_id' ) ) ) {
			return;
		}

		$dupes = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT name, COUNT(*) AS cnt FROM %i GROUP BY name HAVING cnt > 1',
				$tbl
			),
			ARRAY_A
		) ?: [];

		foreach ( $dupes as $d ) {
			$name    = (string) $d['name'];
			$keepers = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE name = %s ORDER BY id ASC',
					$tbl,
					$name
				)
			);
			if ( count( $keepers ) <= 1 ) {
				continue;
			}
			$keeper_id = (int) $keepers[0];
			$duplicates = array_slice( $keepers, 1 );

			foreach ( $duplicates as $dup_id ) {
				$wpdb->update(
					$boats,
					[ 'harbour_id' => $keeper_id ],
					[ 'harbour_id' => (int) $dup_id ],
					[ '%d' ],
					[ '%d' ]
				);
			}

			// Repoint boats to the keeper, then remove the duplicate harbours.
		$placeholders = implode( ',', array_fill( 0, count( $duplicates ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$placeholders})",
				array_merge( [ $tbl ], $duplicates )
			)
		);
		}
	}

	/**
	 * Add a case-insensitive unique index on the harbour name.
	 *
	 * The table collation (utf8mb4_unicode_ci) makes the index case-insensitive
	 * for duplicate-name detection. Falls back to a prefix index if the host
	 * rejects a full VARCHAR(128) index key.
	 *
	 * Idempotent: checks information_schema.STATISTICS before altering.
	 */
	public static function add_unique_index(): void {
		global $wpdb;

		$tbl    = self::t();
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$tbl,
				'uniq_harbour_name'
			)
		);
		if ( $exists ) {
			return;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				'ALTER TABLE %i ADD UNIQUE INDEX uniq_harbour_name (name)',
				$tbl
			)
		);

		if ( false === $result ) {
			$wpdb->query(
				$wpdb->prepare(
					'ALTER TABLE %i ADD UNIQUE INDEX uniq_harbour_name (name(64))',
					$tbl
				)
			);
		}
	}

	/** Populate from the bundled harbour list. No-op if rows already exist. */
	public static function seed(): void {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::t() ) );
		if ( $count > 0 ) {
			return;
		}
		foreach ( self::harbour_data() as [ $name, $lat, $lon ] ) {
			$wpdb->insert( self::t(), compact( 'name', 'lat', 'lon' ), [ '%s', '%f', '%f' ] );
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function get_all(): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::t() ),
			ARRAY_A
		) ?: [];
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::t(), $id ),
			ARRAY_A
		) ?: null;
	}

	/** Insert a new harbour and return its id. */
	public static function create( string $name, float $lat, float $lon ): int {
		global $wpdb;
		$wpdb->insert(
			self::t(),
			[
				'name' => substr( sanitize_text_field( $name ), 0, 128 ),
				'lat'  => $lat,
				'lon'  => $lon,
			],
			[ '%s', '%f', '%f' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Look up a harbour id by name, case-insensitively.
	 *
	 * The table has no unique index on name, so the lowest id wins to keep the
	 * result deterministic.
	 *
	 * @return int Harbour id, or 0 when the name is unknown.
	 */
	public static function find_id_by_name( string $name ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i WHERE name = %s ORDER BY id ASC LIMIT 1',
			self::t(),
			substr( $name, 0, 128 )
		) );
	}

	/**
	 * Check whether a harbour name already exists (case-insensitive via the
	 * table collation). Excludes a given id so a harbour can be edited in place.
	 */
	public static function name_exists( string $name, int $exclude_id = 0 ): bool {
		$name = trim( $name );
		if ( '' === $name ) {
			return false;
		}
		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE name = %s',
			self::t(),
			substr( $name, 0, 128 )
		);
		if ( $exclude_id > 0 ) {
			$sql .= $wpdb->prepare( ' AND id <> %d', $exclude_id );
		}
		return (bool) $wpdb->get_var( $sql );
	}

	/**
	 * Update a harbour's name and coordinates.
	 */
	public static function update( int $id, string $name, float $lat, float $lon ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::t(),
			[
				'name' => substr( sanitize_text_field( $name ), 0, 128 ),
				'lat'  => $lat,
				'lon'  => $lon,
			],
			[ 'id' => $id ],
			[ '%s', '%f', '%f' ],
			[ '%d' ]
		);
	}

	/**
	 * Delete a harbour. Returns false when no row was affected.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::t(), [ 'id' => $id ], [ '%d' ] )
			&& $wpdb->rows_affected > 0;
	}

	/**
	 * Count boats whose home harbour is the given id.
	 */
	public static function boat_count( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE harbour_id = %d',
				PCIO_VIS_Boat_DB::t_boats(),
				$id
			)
		);
	}

	/**
	 * Returns every harbour with its boat count, for the management list.
	 *
	 * Uses a correlated subquery per row so MySQL/MariaDB functional-dependency
	 * behaviour does not affect the result.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all_for_manage(): array {
		global $wpdb;
		$tbl   = self::t();
		$boats = PCIO_VIS_Boat_DB::t_boats();
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT h.id, h.name, h.lat, h.lon,
				        (SELECT COUNT(*) FROM %i WHERE harbour_id = h.id) AS boat_count
				 FROM %i h
				 ORDER BY h.name ASC',
				$boats,
				$tbl
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Returns every harbour with its member list.
	 * Harbours with no boats are included (count = 0, members = []).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_harbour_members(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT h.id AS harbour_id, h.name AS harbour_name, h.lat, h.lon,
				        b.id AS boat_id,
				        (SELECT COUNT(*) FROM %i b2 WHERE b2.harbour_id = h.id) AS boat_count,
				        m.id AS member_id, m.name AS member_name, m.member_number
				 FROM %i h
				 LEFT JOIN %i b ON b.harbour_id = h.id
				 LEFT JOIN %i m ON m.member_number = b.member_number
				 ORDER BY h.name ASC, m.name ASC',
				PCIO_VIS_Boat_DB::t_boats(),
				self::t(),
				PCIO_VIS_Boat_DB::t_boats(),
				PCIO_VIS_DB::table()
			),
			ARRAY_A
		) ?: [];

		// Group by harbour, collecting distinct member rows.
		$harbours = [];
		foreach ( $rows as $row ) {
			$hid = (int) $row['harbour_id'];
			if ( ! isset( $harbours[ $hid ] ) ) {
				$harbours[ $hid ] = [
					'harbour_id' => $hid,
					'name'       => (string) $row['harbour_name'],
					'lat'        => (float) $row['lat'],
					'lon'        => (float) $row['lon'],
					'count'      => (int) $row['boat_count'],
					'members'    => [],
				];
			}
			// Every member sharing the boat's membership number is a member of that
			// harbour, so several core rows can resolve to the same boat row.
			if ( empty( $row['member_id'] ) ) {
				continue;
			}
			$key = (int) $row['member_id'];
			if ( isset( $harbours[ $hid ]['members'][ $key ] ) ) {
				continue;
			}
			$harbours[ $hid ]['members'][ $key ] = [
				'name'          => (string) $row['member_name'],
				'member_number' => (string) $row['member_number'],
			];
		}

		foreach ( $harbours as $hid => $harbour ) {
			ksort( $harbour['members'] );
			$harbours[ $hid ]['members'] = array_values( $harbour['members'] );
		}

		return array_values( $harbours );
	}

	// ── Seed data (150 Danish harbours, sorted A–Z) ───────────────

	/** @return array<int,array{string,float,float}> */
	private static function harbour_data(): array {
		return [
			[ 'Aabenraa Lystbådehavn', 55.036581, 9.423866 ],
			[ 'Aalborg Havn', 57.048761, 10.05661 ],
			[ 'Aalborg Marina Fjordparken', 57.056183, 9.874993 ],
			[ 'Aalborg Skudehavn og Vestre Bådehavn', 57.058517, 9.896697 ],
			[ 'Anholt Havn', 56.714587, 11.518822 ],
			[ 'Assens Havn', 55.267552, 9.885764 ],
			[ 'Ballen Lystbådehavn', 55.815817, 10.63925 ],
			[ 'Bogense Havn og Marina', 55.567354, 10.079269 ],
			[ 'Bogø Havn', 54.912639, 12.050881 ],
			[ 'Brejning Lystbådehavn', 55.674656, 9.690875 ],
			[ 'Brøndby Lystbådehavn', 55.608802, 12.442017 ],
			[ 'Christiansminde Bro', 55.059292, 10.63505 ],
			[ 'Doverodde Havn', 56.717984, 8.472349 ],
			[ 'Dragør Havn', 55.593355, 12.681317 ],
			[ 'Dragør Lystbådehavn', 55.591612, 12.679188 ],
			[ 'Dybvig Havn', 54.944548, 11.43561 ],
			[ 'Dyvig Bådelaug Lystbådehavn', 55.041967, 9.704833 ],
			[ 'Dyreborg Havn', 55.071697, 10.217471 ],
			[ 'Ebeltoft Havn', 56.19546, 10.669527 ],
			[ 'Ebeltoft Skudehavn', 56.191066, 10.668368 ],
			[ 'Egå Marina', 56.20952, 10.289726 ],
			[ 'Esbjerg Havn', 55.474111, 8.424171 ],
			[ 'Fanø Lystbådehavn', 55.443336, 8.408162 ],
			[ 'Faxe Ladeplads Havn', 55.21387, 12.161631 ],
			[ 'Fredericia Lystbådehavn', 55.553593, 9.729252 ],
			[ 'Frederikshavn Marina', 57.425534, 10.529816 ],
			[ 'Frederikssund Havn', 55.835711, 12.054663 ],
			[ 'Frederikssund Lystbådehavn', 55.827227, 12.059813 ],
			[ 'Frederiksværk Lystbådehavn', 55.964612, 11.999345 ],
			[ 'Faaborg Havn', 55.097329, 10.232241 ],
			[ 'Gambøt Lystbådehavn', 55.041782, 10.668304 ],
			[ 'Gilleleje Havn', 56.127496, 12.311253 ],
			[ 'Glyngøre Havn', 56.763968, 8.865023 ],
			[ 'Grenaa Lystbådehavn', 56.404297, 10.923629 ],
			[ 'Greve Marina', 55.596395, 12.356208 ],
			[ 'Gråsten Havn', 54.918833, 9.597552 ],
			[ 'Gråsten Sejlklubs Havn', 54.912417, 9.600635 ],
			[ 'Haderslev Havn', 55.248219, 9.49723 ],
			[ 'Hadsund Lystbådehavn', 56.716801, 10.126455 ],
			[ 'Hals Havn', 56.991944, 10.302222 ],
			[ 'Hanbjerg Marina', 56.47542, 8.71815 ],
			[ 'Havnsø Havn', 55.75424, 11.324415 ],
			[ 'Hejlsminde Lystbådehavn', 55.361033, 9.600388 ],
			[ 'Hellerup Lystbådehavn', 55.731911, 12.581319 ],
			[ 'Helsingør Nordhavn', 56.042754, 12.614107 ],
			[ 'Herslev Havn', 55.668685, 11.939124 ],
			[ 'Hjarbæk Havn', 56.531294, 9.318617 ],
			[ 'Hobro Lystbådehavn', 56.643534, 9.812247 ],
			[ 'Holbæk Havn', 55.720234, 11.709366 ],
			[ 'Holbæk Marina', 55.723823, 11.760006 ],
			[ 'Holstebro-Struer Havn', 56.494651, 8.59147 ],
			[ 'Hornbæk Havn', 56.094952, 12.458103 ],
			[ 'Horsens Havn', 55.858438, 9.859058 ],
			[ 'Horsens Lystbådehavn', 55.856731, 9.873203 ],
			[ 'Horsens Marina', 55.857528, 9.87508 ],
			[ 'Hou Lystbådehavn', 55.910325, 10.254107 ],
			[ 'Hov Havn (Hou Havn)', 57.055926, 10.377545 ],
			[ 'Humlebæk Havn', 55.971006, 12.546061 ],
			[ 'Hundested Lystbådehavn', 55.965244, 11.84642 ],
			[ 'Hundige Lystbådehavn', 55.593043, 12.353826 ],
			[ 'Hvalpsund Lystbådehavn', 56.705, 9.201586 ],
			[ 'Hvide Sande Lystbådehavn', 56.008363, 8.136073 ],
			[ 'Hvidovre Lystbådehavn', 55.625488, 12.500639 ],
			[ 'Høruphav Havn', 54.906687, 9.888595 ],
			[ 'Ishøj Havn', 55.6095, 12.387 ],
			[ 'Jegindø Havn', 56.651516, 8.636143 ],
			[ 'Juelsminde Havn og Marina', 55.715556, 10.016012 ],
			[ 'Kalvehave Havn', 54.994358, 12.166286 ],
			[ 'Kaløvig Bådehavn', 56.243278, 10.340796 ],
			[ 'Kampeløkke Havn', 55.289221, 14.782577 ],
			[ 'Karrebæksminde Havn', 55.175951, 11.644349 ],
			[ 'Kastrup Strandpark', 55.641767, 12.652538 ],
			[ 'Kerteminde Havn', 55.453102, 10.666416 ],
			[ 'Kignæs Lystbådehavn', 55.857154, 12.009172 ],
			[ 'Kolding Lystbådehavn', 55.488945, 9.499441 ],
			[ 'Kongsdal Lystbådehavn', 56.684028, 10.07163 ],
			[ 'Korsør Lystbådehavn', 55.327521, 11.130692 ],
			[ 'Kulhuse Havn', 55.936654, 11.906519 ],
			[ 'Københavns Havn', 55.637783, 12.543898 ],
			[ 'Københavns Havn (Kalkbrænderihavnen)', 55.71158, 12.590336 ],
			[ 'Køge Marina', 55.469489, 12.197099 ],
			[ 'Langelinie Lystbådehavn', 55.694138, 12.599323 ],
			[ 'Lemvig Marina', 56.566142, 8.297374 ],
			[ 'Lynæs Havn', 55.942519, 11.867723 ],
			[ 'Løgstør Havn', 56.967626, 9.245078 ],
			[ 'Maarup Havn', 55.936716, 10.551398 ],
			[ 'Marbæk Lystbådehavn', 55.8276, 12.061915 ],
			[ 'Margretheholms Havn', 55.687952, 12.616274 ],
			[ 'Mariager Lystbådehavn', 56.653791, 9.982528 ],
			[ 'Marina Minde Havn', 54.896823, 9.618223 ],
			[ 'Marselisborg Lystbådehavn', 56.138018, 10.215479 ],
			[ 'Mellerup Lystbådehavn', 56.524027, 10.223656 ],
			[ 'Middelfart Marina', 55.491863, 9.727493 ],
			[ 'Mosede Fiskerihavn', 55.566383, 12.286449 ],
			[ 'Nappedam Lystbådehavn', 56.276995, 10.494619 ],
			[ 'Nibe Lystbådehavn', 56.987622, 9.633336 ],
			[ 'Nivå Havn', 55.939683, 12.526731 ],
			[ 'Nyborg Lystbådehavn', 55.306092, 10.786847 ],
			[ 'NyHavn 1', 55.506137, 9.735787 ],
			[ 'NyHavn 2', 55.50607, 9.738083 ],
			[ 'Nykøbing Falster Havn', 54.771105, 11.86131 ],
			[ 'Nykøbing Mors Havn', 56.79277, 8.865533 ],
			[ 'Nykøbing Sjælland Lystbådehavn', 55.913668, 11.672759 ],
			[ 'Næstved Kanalhavn', 55.208044, 11.714417 ],
			[ 'Nørre Uttrup Lystbådehavn', 57.070906, 9.95769 ],
			[ 'Odden Havn', 55.972057, 11.369755 ],
			[ 'Otterup Lystbådehavn', 55.526712, 10.471966 ],
			[ 'Præstø Havn', 55.124613, 12.041947 ],
			[ 'Randers Lystbådehavn', 56.462367, 10.05344 ],
			[ 'Rantzausminde Lystbådehavn', 55.033901, 10.541511 ],
			[ 'Reersø Fiskerihavn', 55.517001, 11.119488 ],
			[ 'Ringkøbing Havn', 56.086465, 8.239667 ],
			[ 'Roskilde Havn', 55.651587, 12.076206 ],
			[ 'Rudkøbing Havn', 54.941763, 10.711298 ],
			[ 'Rungsted Havn', 55.885469, 12.546966 ],
			[ 'Rønne Havn', 55.104388, 14.692498 ],
			[ 'Rønnerhavnen', 57.464343, 10.537026 ],
			[ 'Rørvig Havn', 55.943811, 11.767538 ],
			[ 'Sejerø Havn', 55.880318, 11.135867 ],
			[ 'Skagen Havn', 57.715484, 10.587376 ],
			[ 'Skive Søsports Havn', 56.575312, 9.053185 ],
			[ 'Skovshoved Havn', 55.761364, 12.601383 ],
			[ 'Skuldelev Havn', 55.795505, 12.056957 ],
			[ 'Skærbæk Havn', 55.513082, 9.626663 ],
			[ 'Snaptun Havn', 55.822695, 10.05174 ],
			[ 'Strandby Havn', 57.494314, 10.505464 ],
			[ 'Strib Bådehavn', 55.538564, 9.763112 ],
			[ 'Stubbekøbing Havn', 54.891641, 12.04771 ],
			[ 'Sundby Hvorup', 57.07093, 9.95769 ],
			[ 'Svanemøllehavnen', 55.716306, 12.588272 ],
			[ 'Svendborg Sund Marina', 55.055003, 10.644179 ],
			[ 'Sæby Havn', 57.333216, 10.532885 ],
			[ 'Sønderborg Lystbådehavn', 54.899439, 9.794354 ],
			[ 'Søndre Frihavn', 55.696539, 12.595786 ],
			[ 'Thisted Havn', 56.95237, 8.697067 ],
			[ 'Thurøbund Marina', 55.059698, 10.617954 ],
			[ 'Troense Dampskibsbro og Bådehavn', 55.034183, 10.645409 ],
			[ 'Tuborg Havn', 55.726319, 12.585107 ],
			[ 'Vallensbæk Havn', 55.612032, 12.396205 ],
			[ 'Vedbæk Havn', 55.849566, 12.572265 ],
			[ 'Veddelev Lystbådehavn', 55.678843, 12.068653 ],
			[ 'Vejle Lystbådehavn', 55.706248, 9.55641 ],
			[ 'Vindeby Lystbådehavn', 55.049343, 10.61496 ],
			[ 'Virksund Havn', 56.60896, 9.291138 ],
			[ 'Vive Havn', 56.698618, 10.044351 ],
			[ 'Øer Havn', 56.151622, 10.689698 ],
			[ 'Øster Hurup Fiskeri- og Lystbådehavn', 56.804826, 10.282516 ],
			[ 'Århus Lystbådehavn', 56.165961, 10.222049 ],
			[ 'Årøsund Havn', 55.262686, 9.709425 ],
		];
	}
}
