<?php declare(strict_types=1);

namespace rdap_org;

foreach (glob(__DIR__.'/{registry,error,ip,logger}.php', GLOB_BRACE) ?: [] as $f) require_once $f;

use OpenSwoole\HTTP\{Request,Response};
use OpenSwoole\Constant;

/**
 * @codeCoverageIgnore
 */
class server extends \OpenSwoole\HTTP\Server {

    /**
     * this stores the bootstrap registries and is populated by updateData()
     * @var array<string, registry>
     */
    private array $registries;

    /*
     * private handle for STDOUT
     * @var resource
     */
    protected mixed $STDOUT;

    /*
     * private handle for STDERR
     * @var resource
     */
    protected mixed $STDERR;

    /*
     * how long between refreshes of the registry data (in seconds)
     */
    protected const registryTTL = 3600 * 6;

    /**
     * array of blocked client addresses, which is populated from an environment variable
     * @var ip[]
     */
    private array $blocked = [];

    public const OK             = 200;
    public const NO_CONTENT     = 204;
    public const FOUND          = 302;
    public const BAD_REQUEST    = 400;
    public const UNAUTHORIZED   = 401;
    public const FORBIDDEN      = 403;
    public const NOT_FOUND      = 404;
    public const BAD_METHOD     = 405;
    public const ERROR          = 500;

    public function __construct(
        string $host='::',
        int $port=8080,
        int $mode=self::POOL_MODE,
        int $sock_type=Constant::SOCK_TCP
    ) {
        parent::__construct($host, $port, $mode, $sock_type);

        $this->STDOUT = fopen('php://stdout', 'w');
        $this->STDERR = fopen('php://stderr', 'w');

        //
        // parse the IP_BLOCK_LIST environment variable to get a list of blocked IP addresses
        //
        $this->blocked = array_map(
            fn($ip) => new ip(trim($ip)),
            preg_split('/,/', getenv('IP_BLOCK_LIST') ?: '', -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        //
        // request handler
        //
        $this->on('Request', fn(Request $request, Response $response) => $this->handleRequest($request, $response));
    }

    /**
     * load the registry data and then start the server
     */
    public function start() : bool {
        fwrite($this->STDERR, "loading registry data...\n");
        $this->updateData();
        fwrite($this->STDERR, "ready to accept requests!\n");
        return parent::start();
    }

    private function handleRequest(
        Request $request,
        Response $response
    ) : void {
        $response->header('access-control-allow-origin', '*');
        $response->header('server', 'github.com/rdap-org/rdap.org');
        $response->header('expires', gmdate('D, d M Y h:i:s', time()+86400).' GMT');
        $response->header('cache-control', 'public');

        $peer = $this->getPeer($request);

        try {
            $blocked = $this->isBlocked($peer);

        } catch (\Throwable $e) {
            fwrite($this->STDERR, $e->getMessage()."\n");

            $blocked = false;
        }

        $status = self::ERROR;

        try {
            $status = ($blocked ? self::FORBIDDEN : $this->generateResponse($request, $response));

        } catch (\Throwable $e) {
            fwrite($this->STDERR, $e->getMessage()."\n");

        } finally {
            $response->status($status);

            $response->end();

            logger::logRequest($request, $status, $peer);
        }
    }

    /**
     * @return string[]
     */
    public static function getPathSegments(Request $request) : array {
        return preg_split('/\//', strtolower($request->server['request_uri']), 2, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * handle a request
     */
    protected function generateResponse(
        Request $request,
        Response $response
    ) : int {

        $path = self::getPathSegments($request);

        if (0 == count($path)) {
            //
            // empty path, so redirect to the about page
            //
            $url = 'https://about.rdap.org/';

        } elseif (1 == count($path)) {
            if ('heartbeat' == $path[0]) {
                //
                // heartbeat request for internal monitoring purposes
                //
                return SELF::OK;

            } elseif ('help' == $path[0]) {
                //
                // help request
                //
                $response->header('content-type', 'application/rdap+json');
                $response->write($this->help());

                return SELF::OK;

            } elseif ('stats' == $path[0]) {
                //
                // stats request
                //
                return $this->statsHandler($request, $response);

            } else {
                return SELF::BAD_REQUEST;

            }

        } elseif (2 != count($path)) {
            //
            // incorrect number of segments
            //
            return self::BAD_REQUEST;

        } else {
            list($type, $object) = $path;

            try {
                //
                // look up the URL for the requested object
                //
                $url = match ($type) {
                    'domain'    => $this->domain($object),
                    'entity'    => $this->entity($object),
                    'autnum'    => $this->autnum(intval($object)),
                    'ip'        => $this->ip(new ip($object)),
                    default     => null,
                };

            } catch (error $e) {
                //
                // object was somehow malformed or unparseable
                //
                return self::BAD_REQUEST;

            }

            //
            // append type and object to the URL
            //
            if ($url) $url = implode('/', [$url, $type, $object]);
        }

        //
        // no URL so we can't offer a redirect
        //
        if (!$url) return self::NOT_FOUND;

        $response->header('location', $url);

        return self::FOUND;
    }

    /**
     * get the base URL for the given domain
     */
    private function domain(string $domain) : ?string {
        return $this->registries['dns']->search(function($tld) use ($domain) {
            if (empty($tld) && false == strpos($domain, '.')) {
                // empty TLD indicates the root zone, and no dot indicates
                // domain is a TLD
                return true;

            } else {
                return str_ends_with($domain, '.'.$tld);

            }
        });
    }

    /**
     * get the base URL for the given entity
     */
    private function entity(string $entity) : ?string {
        return $this->registries['object-tags']->search(fn($tag) => str_ends_with($entity, '-'.$tag));
    }

    /**
     * get the base URL for the given ASN
     */
    private function autnum(int $autnum) : ?string {
        return $this->registries['asn']->search(function($range) use ($autnum) {
            if (1 == count($range)) {
                return ($range[0] === $autnum);

            } else {
                return ($range[0] <= $autnum && $autnum <= $range[1]);

            }
        });
    }

    /**
     * get the base URL for the given IP
     */
    private function ip(ip $ip) : ?string {
        return $this->registries['ip']->search(fn($range) => $range->contains($ip));
    }

    /**
     * load the IANA registries. this is called once when the server starts
     * and then periodically as a background job
     */
    protected function updateData() : void {
        try {
            $this->registries = registry::load();

        } catch (error $e) {
            fwrite($this->STDERR, $e->getMessage()."\n");

            if (empty($this->registries)) exit(1);
        }

        //
        // schedule a refresh
        //
        $this->after(1000 * self::registryTTL, fn() => $this->updateData()); // @phpstan-ignore-line
    }

    /**
     * parse the request properties to get the client IP
     */
    private function getPeer(Request $request) : ip {
        if (isset($request->header['cf-connecting-ip'])) {
            return new ip($request->header['cf-connecting-ip']);

        } elseif (isset($request->header['fly-client-ip'])) {
            return new ip($request->header['fly-client-ip']);

        } elseif (isset($request->header['x-forwarded-for'])) {
            $list = preg_split('/,/', $request->header['x-forwarded-for'], -1, PREG_SPLIT_NO_EMPTY) ?: [];

            try {
                return new ip(trim((string)array_pop($list)));

            } catch (\Throwable $e) {
                return new ip($request->server['remote_addr']);

            }

        } else {
            return new ip($request->server['remote_addr']);

        }
    }

    /**
     * check the blocklist for the given IP
     */
    private function isBlocked(ip $ip) : bool {
        foreach ($this->blocked as $block) if ($block->contains($ip)) return true;
        return false;
    }

    protected function help() : string {
        return strval(json_encode([
            'rdapConformance' => ['rdap_level_0'],
            'lang' => 'en',
            'notices' => [[
                'title' => 'About this service',
                'description' => [
                    'RDAP.org aims to support users and developers of RDAP clients by providing a "bootstrap server", i.e. single end point for RDAP queries.',
                    'RDAP.org aggregates information about all known RDAP servers. RDAP clients can send RDAP queries to RDAP.org, which will then redirect requests to the appropriate RDAP service.'
                ],
                'links' => [[
                    'title' => 'Further information',
                    'value' => 'https://rdap.org/help',
                    'href'  => 'https://about.rdap.org',
                    'rel'   => 'about',
                ]],
            ]]
        ]));
    }

    private function statsHandler(
        Request     $request,
        Response $response
    ) : int {

        if (!isset($request->header['authorization'])) return self::UNAUTHORIZED;

        if (1 != preg_match('/^Bearer\s+(.*)$/i', $request->header['authorization'], $matches)) return self::UNAUTHORIZED;

        $token = getenv("STATS_TOKEN");
        if (false === $token || $token !== $matches[1]) return self::UNAUTHORIZED;

        try {
            return match ($request->server['request_method']) {
                "DELETE"    => $this->deleteStats($response),
                "GET"       => $this->getStats($response),
                default     => self::BAD_METHOD,
            };

        } catch (\Throwable $e) {
            fwrite($this->STDERR, $e->getMessage()."\n");

            return self::ERROR;
        }
    }

    private function deleteStats(Response $response) : int {
        logger::clearStats();

        return SELF::NO_CONTENT;
    }

    private function getStats(Response $response) : int {
        $response->header('content-type', 'application/rdap+json');
        $response->write(strval(json_encode(logger::stats())));

        return SELF::OK;
    }   

}
