<?php
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function __( $text, $domain = '' ) {
	return $text;
}
