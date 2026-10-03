<?php
/** Separate processes exercise actual database row-lock contention. */
require $argv[3] . 'wp-load.php';
$wins = 0;
for ( $i = 0;$i < 10;$i++ ) {
	if ( true === \Lineweb\ChangeDesk\Quota::reserve( (int) $argv[1], $argv[2] ) ) {
		++$wins;
	}
}
echo $wins;
