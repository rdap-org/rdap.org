<?php declare(strict_types=1);

namespace rdap_org;

class logger {
	private static mixed $STDOUT = null;

	public static function logRequest(
		\OpenSwoole\HTTP\Request 	$request,
		int 											$status,
		ip 												$peer,
	) : void {

		if (is_null(self::$STDOUT)) self::$STDOUT = fopen('php://stdout', 'w');

		fprintf(
			self::$STDOUT,
			"%s - - [%s] \"%s %s %s\" %03u 0 \"%s\" \"%s\"\n",
			strval($peer),
			gmdate('d/m/Y:h:i:s O'),
			$request->server['request_method'],
			$request->server['request_uri'],
			$request->server['server_protocol'],
			$status,
			$request->header['referer'] ?? '-', 
			$request->header['user-agent'] ?? '-', 
		);
	}
}
