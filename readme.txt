===== PCIO VIS Boat =====
Contributors: pcio
Tags: members, boats, club, nautical
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.1.1
Requires PHP: 8.1
Requires Plugins: pcio-vis-member-event
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extends PCIO VIS Member Event with a boat club register.

== Description ==

Adds a **Boat Register** to PCIO VIS Member Event for sailing clubs and other
boat-oriented organisations.

**Features**

* Register boats with: home harbour, name, type, model, build year, sail
  number and MMSI number.
* A boat belongs to a **membership number**. Everyone who shares that membership
  number is crew on the boat, so the crew group and the subscription follow the
  same number — there is no separate crew table to keep in sync.
* The home harbour is picked from the shared harbour list and stored by id, so
  the same harbour name cannot drift across boats.
* Members can maintain their own boat and crew from the core member profile —
  no administrator needed.
* When a core member is deleted their boat is removed only if they were the
  last member on that membership number; otherwise the boat stays with the
  remaining crew.
* Boat Register admin page at `/vis/boats`, access-controlled by the
  `vis_boat_manage` capability (granted to Editor and Administrator on
  activation).
* Harbours management page at `/vis/harbours` — list, create, edit, and delete
  harbours on a map, with duplicate-name protection and delete-guard against
  harbours still in use.
* Boat name appears in the core member list; clicking it opens a read-only
  popup with the full boat details and the crew group.

This plugin stores all its data locally in two custom database tables. It does not
communicate with any external service, apart from the harbour map tiles listed
under **External services** below.

== Installation ==

1. Make sure **PCIO VIS Member Event** is installed and active.
2. Upload the `pcio-vis-boat` folder to `/wp-content/plugins/`.
3. Activate the plugin in WordPress.
4. Navigate to `/vis/boats` to start managing the boat register.

== Frequently Asked Questions ==

= Where do I manage boats? =

Go to `/vis/boats` on the front end. A "Boats" link also appears in the Vis
navigation and as a tile on the `/vis/` landing page for users with the
`vis_boat_manage` capability.

= Where do I manage harbours? =

Go to `/vis/harbours` on the front end. A "Harbours" link appears in the Vis
navigation and as a tile on the `/vis/` landing page for users with the
`vis_boat_manage` capability. A "Vis → Harbours" submenu in wp-admin redirects
there. Harbour names must be unique; existing duplicates are merged on upgrade.

= Can crew members see their own boats? =

Yes. `GET /pcio-vis/v1/boat/member/{member_id}` returns the boat registered for
that member's membership number — an empty list when they have none, otherwise
one boat with a `crew` array listing everyone on that number. The endpoint is
available to administrators and to the member themselves when they are logged
in. Members can also edit their own boat and add or remove crew at
`/vis/members` → their profile.

= Does this plugin send any data externally? =

No boat, member or order data ever leaves your server. The only outbound requests are the OpenStreetMap and OpenSeaMap map tiles drawn by the harbour map in wp-admin — see **External services** below for the details.

= What happens when a member is deleted from the core plugin? =

If they were the only member on their membership number, the boat is deleted.
If other members still share that number, the boat is kept and stays with the
remaining crew.

== Upgrade Notice ==

= 1.1 =
Boat records are migrated automatically. The owner column becomes the membership number, the free-text home harbour column is removed, and the membership number is made unique. Boats needing manual attention are listed in the PHP error log — see the changelog.

== Screenshots ==

1. New register for boat data
2. Map showing members home habour
3. Advanced member statistics and sponsor activity (number of link clicks)

== Changelog ==

= 1.1 =
* Added: **Member boat profile.** Signed-in members can now edit their own boat
  and manage their crew directly on the core member profile page, with no
  administrator involved. New "Boat details" and "Crew" sections appear there,
  backed by a new `[pcio_boat_edit_profile]` shortcode alias.
* Added: **Crew management for members.** New self-service routes
  `GET/POST /pcio-vis/v1/boat/my-boat/{id}/crew` and
  `DELETE /pcio-vis/v1/boat/my-boat/{id}/crew/{member_id}` let a boat owner add
  and remove crew. A new crew member is created in the core member register,
  given a WordPress login and the welcome email; an existing WordPress user
  without `vis_member` is accepted and promoted. Removing a crew member clears
  their membership number and revokes `vis_member`.
* Added: **Boat detail popup** in the core member list. The Boat cell is now a
  button that opens a read-only dialog with type, model, home harbour, build
  year, sail number, MMSI, membership number, note and the full crew group.
* Added: **Harbour list navigation** — a "Harbours" entry in the Vis navigation
  and a tile on the `/vis/` landing page, both gated on `vis_boat_manage`.
* Added: **Shortcode reference** — the Boat plugins are documented in the core
  shortcode reference with a dedicated sidebar group.
* Changed: **Crew is now the membership number.** The `me_boat_crew` table is
  gone; every member carrying the boat's membership number is crew on that
  boat, so the crew group and the subscription always agree. A boat's owner
  column is the membership number and is unique — one boat per membership
  number.
* Changed: **Home harbour is stored by id.** The free-text `home_harbour`
  column is removed from `me_boat_boats` and the harbour name is resolved by
  joining the harbours table, so the same harbour cannot be spelled differently
  on two boats. The sign-up form and the boat form now save only the selection;
  typing free text without picking a harbour no longer stores a name.
* Changed: **Harbour names are unique.** A case-insensitive unique index is
  added to `me_boat_harbours.name`, and any duplicates found on upgrade are
  merged — boats are repointed to the surviving harbour before the duplicate rows
  are removed.
* Changed: **Harbour picker is keyboard-navigable.** The autocomplete supports
  Arrow Up/Down, Enter and Escape with `aria-activedescendant`, and editing the
  text after picking clears the selection so a typo cannot save the wrong
  harbour.
* Changed: The Boat Register table shows a Crew column instead of an Owner
  column, with each crew name linking to the member directory when the viewer
  is allowed to see it.
* Improved: Boat form validation now reports a missing boat name, an MMSI that
  is not exactly 9 digits, a build year outside 1900–this year, and invalid
  harbour coordinates, inline on the offending field.
* Improved: The `/shop/accepted` style confirmation is unchanged, but a crew
  member without their own subscription now inherits the shared membership's
  status instead of showing an unknown state.
* Security: a harbour placed on the map is now created only when the caller is
  entitled to it — a confirmed order for sign-ups, or a signed-in member for
  profile saves. Previously a sign-up that completed without a payment (no
  active membership product, or Stripe not configured) could write a harbour
  row from the anonymous form post.
* Fixed: The harbour map could show no members for any harbour, because it
  matched the boat's owner column against the member id while the rest of the
  plugin had moved to membership numbers.
* Fixed: Boats whose owner membership number no longer existed were silently
  orphaned by the ownership check on the REST routes.

= 1.0.3 =
* Added a Harbours management page at `/vis/harbours` (capability `vis_boat_manage`):
  list, create, edit and delete harbours with a Leaflet draggable-marker map.
* Deleting a harbour that still has boats is blocked with a 409 response showing
  the boat count; move the boats first.
* Creating or editing a harbour rejects duplicate names (case-insensitive) with a
  409 response, and a unique index on `me_boat_harbours.name` was added.
* "Boats" column on the management page shows the boat-row count for each harbour.
* A "Vis → Harbours" wp-admin submenu entry redirects to the front-end page.

= 1.0.1 =
* Security: the harbour map endpoint that lists member names and membership
  numbers now requires the `vis_member` capability instead of being public.
* Security: creating a shared harbour now requires the `vis_boat_manage`
  capability. Harbours added on the map during sign-up are stored only after
  membership is confirmed, removing the unauthenticated write path.

= 1.0.0 =
* Initial release.

== Uninstall ==

Deleting the plugin from **Plugins** removes its `pcio_me_boat_db_version` option, so a reinstall rebuilds the tables from scratch.

The database tables are deliberately **kept**, because they hold your club's boat register and harbour list. These tables survive:

* `{prefix}me_boat_boats`
* `{prefix}me_boat_harbours`

If you want a full wipe, drop both tables manually from phpMyAdmin or a SQL client. Also delete the leftover `{prefix}me_boat_crew` table if your site was created before 1.1 — it is no longer used and is dropped automatically on the 1.1 upgrade.

== External services ==

The plugin itself makes no outbound requests: all boat, harbour and member data stays in your local database, no analytics or tracking is included, and no account with any third party is required.

The one exception is the harbour map on `/vis/boats` and `/vis/harbours`, which displays map tiles in your browser:

* **OpenStreetMap** — `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`. Your browser's IP address is visible to OpenStreetMap, along with the map area you pan to. No boat or member data is sent. Their [tile usage policy](https://operations.osmfoundation.org/policies/tiles/) applies.
* **OpenSeaMap** — `https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png`, a transparent overlay of seamarks. Same conditions as above; see the [OpenSeaMap site](https://openseamap.org).

The map is only used in wp-admin and requires the `vis_boat_manage` capability. You can replace both tile URLs in `assets/harbour-map.js` and `assets/harbours-admin.js` with your own tile server or a local tile set if you would rather not contact them at all.
