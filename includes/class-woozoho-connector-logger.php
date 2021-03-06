<?php

class Woozoho_Connector_Logger {

	public static function write_debug( $type, $data ) {
		//TODO: Fix debugging setting.
		$multisite = is_multisite() ? "[" . get_bloginfo( 'name' ) . "]" : ( "" ); //Multi-site support.
		$logfile   = realpath( __DIR__ . '/..' ) . '/debug_log';
		file_put_contents( $logfile,
			$multisite . "[" . date( "Y-m-d H:i:s" ) . "] [" . $type . "] " . $data . "\n", FILE_APPEND );
	}

}