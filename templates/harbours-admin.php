<?php
/**
 * Front-end template: Harbours management.
 * Served at /vis/harbours by PCIO_VIS_Boat_Harbours_Admin::serve().
 *
 * Access control (login + vis_boat_manage) is enforced before this loads.
 * The entire UI is rendered by harbours-admin.js into #pcio-me-app.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

show_admin_bar( false );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title>Vis &mdash; <?php esc_html_e( 'Harbours', 'pcio-vis-boat' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="pcio-me-body">
<!-- PCIO_VIS_BOAT_TEMPLATE_OK:<?php echo esc_html( PCIO_VIS_BOAT_VERSION ); ?> -->

<div id="pcio-me-wrap">

	<?php
	$pcio_me_active_page = 'harbours';
	$pcio_vis_nav = defined( 'PCIO_VIS_PLUGIN_DIR' ) ? PCIO_VIS_PLUGIN_DIR . 'templates/_nav.php' : '';
	if ( $pcio_vis_nav && file_exists( $pcio_vis_nav ) ) {
		require $pcio_vis_nav;
	}
	?>

	<main class="me-main">
		<div id="pcio-me-app">
			<div class="me-loading">
				<div class="me-spinner"></div>
				<span><?php esc_html_e( 'Loading&hellip;', 'pcio-vis-boat' ); ?></span>
			</div>
		</div>
	</main>

</div>

<?php wp_footer(); ?>
</body>
</html>
