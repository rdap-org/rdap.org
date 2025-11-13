<?php declare(strict_types=1);

namespace rdap_org;

class logger {
	private static mixed $STDOUT = null;
    private static mixed $REDIS  = null;

	public static function logRequest(
		\OpenSwoole\HTTP\Request 	$request,
		int 											$status,
		ip 												$peer,
	) : void {

		if (is_null(self::$STDOUT)) self::$STDOUT = fopen('php://stdout', 'w');

        if (is_null(self::$REDIS)) {
            $host = getenv("REDIS_HOSTNAME");

            if (is_string($host) && strlen($host) > 0) {
                try {
                    self::$REDIS = new Redis();

                    self::$REDIS->connect(
                        $host,
                        6379,
                        1,
                        "",
                        0,
                        0,
                        ["auth" => [getenv("REDIS_USERNAME"), getenv("REDIS_PASSWORD")]]
                    );

                } catch (\Throwable $e) {
                    self::$REDIS = null;

                    error_log(sprintf("Unable to connect to Redis server on %s: %s", $host, $e->getMessage()));
                }
            }
        }

        if (!is_null(self::$REDIS)) {
            if (!self::$REDIS->isConnected()) {
                self::$REDIS = null;

            } else {
                try {
                    self::$REDIS->incr("total_queries");

                } catch (\Throwable $e) {
                    error_log($e->getMessage());

                }
            }
        }

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
