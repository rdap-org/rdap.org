<?php declare(strict_types=1);

namespace rdap_org;

use OpenSwoole\HTTP\{Request,Response};
use Redis;

/**
 * @codeCoverageIgnore
 */
class logger {
    private static mixed $STDOUT = null;
    private static mixed $REDIS  = null;

    public static function logRequest(
        Request $request,
        int     $status,
        ip      $peer,
    ) : void {
        if (false !== getenv("REDIS_HOSTNAME")) {
            self::logAnalytics($request, $status, $peer);
        }

        if (false !== getenv("ENABLE_OUTPUT_LOGGING")) {
            self::logCombinedLogFormat($request, $status, $peer);
        }
    }

    private static function logCombinedLogFormat(
        Request $request,
        int     $status,
        ip      $peer,
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

    private static function logAnalytics(
        Request $request,
        int     $status,
        ip      $peer,
    ) : void {

        self::connectToRedis();

        if (!is_null(self::$REDIS)) {
            try {
                self::$REDIS->multi(Redis::PIPELINE);

                self::$REDIS->incr("total_queries");

                self::$REDIS->hIncrBy("queries_by_status", (string)$status, 1);

                $type = self::getQueryType($request);
                if ($status < 400) {
                    self::$REDIS->hIncrBy("queries_by_type", $type, 1);

                    if ("domain" == $type) self::$REDIS->hIncrBy("queries_by_tld", self::getTLD($request), 1);
                }

                self::$REDIS->hIncrBy("queries_by_user_agent", $request->header['user-agent'] ?? "-", 1);
                self::$REDIS->hIncrBy("queries_by_network", self::ipToNetwork($peer), 1);

                self::$REDIS->exec();

            } catch (\Throwable $e) {
                error_log($e->getMessage());

            }
        }
    }

    private static function getQueryType(Request $request) : string {
        $segments = server::getPathSegments($request);
        return strtolower((string)array_shift($segments));
    }

    private static function ipToNetwork(ip $ip) : string {
        $len = (AF_INET == $ip->family ? 24 : 48);

        $gmp = gmp_import(gmp_export($ip->addr));

        for ($i = 0 ; $i < (AF_INET == $ip->family ? 32-24 : 128-48) ; $i++) gmp_clrbit($gmp, $i);

        return sprintf("%s/%u", inet_ntop(gmp_export($gmp)), $len);
    }

    private static function connectToRedis() : void {
        if (is_null(self::$REDIS)) {
            $host = getenv("REDIS_HOSTNAME");

            if (is_string($host) && strlen($host) > 0) {
                try {
                    self::$REDIS = new Redis();

                    $context = [];

                    if (false !== getenv("REDIS_USERNAME")) {
                        $context["auth"] = [
                            (string)getenv("REDIS_USERNAME"),
                            (string)getenv("REDIS_PASSWORD"),
                        ];
                    }

                    self::$REDIS->connect(
                        $host,
                        6379,
                        1,
                        "",
                        0,
                        0,
                        $context
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
            }
        }
    }

    /**
     * @return array<string, int|array<string, int>|string>
     */
    public static function stats() : array {
        $stats = [];

        try {
            self::connectToRedis();

            $stats["timestamp"] = time();
            $stats["total_queries"] = (int)self::$REDIS->get("total_queries");

            foreach (["queries_by_status", "queries_by_type", "queries_by_user_agent", "queries_by_network"] as $name) {
                $stats[$name] = [];

                $data = self::$REDIS->hGetAll($name);
                if (is_array($data)) {
                    foreach ($data as $key => $value) {
                        $stats[$name][$key] = intval($value ?? 0); /* @phpstan-ignore-line */
                    }
                }
            }

        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $stats["error"] = $e->getMessage();

        }

        return $stats;
    }

    public static function clearStats() : void {
        self::connectToRedis();

        try {
            foreach (self::$REDIS->keys("*") as $key) {
                self::$REDIS->del($key);
            }

        } catch (\Throwable $e) {
            error_log($e->getMessage());

        }
    }
}
