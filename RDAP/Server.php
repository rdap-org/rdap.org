<?php declare(strict_types=1);

namespace RDAP;

foreach (glob(__DIR__.'/{Registry,Error,IP}.php', GLOB_BRACE) ?: [] as $f) require_once $f;

use OpenSwoole\HTTP\{Request,Response};
use OpenSwoole\Constant;

/**
 * @codeCoverageIgnore
 */
class Server extends \OpenSwoole\HTTP\Server {

    /**
     * this stores the bootstrap registries and is populated by updateData()
     * @var array<string, Registry>
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
     * @var IP[]
     */
    private array $blocked = [];

    protected const OK            = 200;
    protected const FOUND         = 302;
    protected const BAD_REQUEST   = 400;
    protected const FORBIDDEN     = 403;
    protected const NOT_FOUND     = 404;
    protected const ERROR         = 500;

    public function __construct(string $host='::', int $port=8080, int $mode=self::POOL_MODE, int $sock_type=Constant::SOCK_TCP) {
        parent::__construct($host, $port, $mode, $sock_type);

        $this->STDOUT = fopen('php://stdout', 'w');
        $this->STDERR = fopen('php://stderr', 'w');

        //
        // parse the IP_BLOCK_LIST environment variable to get a list of blocked IP addresses
        //
        $this->blocked = array_map(
            fn($ip) => new IP(trim($ip)),
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

    private function handleRequest(Request $request, Response $response) : void {
        $response->header('access-control-allow-origin', '*');
        $response->header('content-type', 'application/rdap+json');
        $response->header('server', 'https://github.com/gbxyz/rdap-bootstrap-server');

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

            $this->logRequest($request, $status, $peer);
        }
    }

    /**
     * @return string[]
     */
    protected function getPathSegments(Request $request) : array {
        return preg_split('/\//', strtolower($request->server['request_uri']), 2, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * handle a request
     */
    protected function generateResponse(Request $request, Response $response) : int {

        $path = $this->getPathSegments($request);

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
                $response->write($this->help());

                return SELF::OK;

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
                    'ip'        => $this->ip(new IP($object)),
                    default     => null,
                };

            } catch (Error $e) {
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
     * this outputs a log line in Combined Log Format
     */
    private function logRequest(Request $request, int $status, IP $peer) : void {
        fprintf(
            $this->STDOUT,
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

    /**
     * get the base URL for the given domain
     */
    private function domain(string $domain) : ?string {
        return $this->registries['dns']->search(fn($tld) => str_ends_with($domain, '.'.$tld));
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
    private function ip(IP $ip) : ?string {
        return $this->registries['ip']->search(fn($range) => $range->contains($ip));
    }

    /**
     * load the IANA registries. this is called once when the server starts
     * and then periodically as a background job
     */
    protected function updateData() : void {
        try {
            $this->registries = Registry::load();

        } catch (Error $e) {
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
    private function getPeer(Request $request) : IP {
        if (isset($request->header['cf-connecting-ip'])) {
            return new IP($request->header['cf-connecting-ip']);

        } elseif (isset($request->header['fly-client-ip'])) {
            return new IP($request->header['fly-client-ip']);

        } elseif (isset($request->header['x-forwarded-for'])) {
            $list = preg_split('/,/', $request->header['x-forwarded-for'], -1, PREG_SPLIT_NO_EMPTY) ?: [];

            try {
                return new IP(trim((string)array_pop($list)));

            } catch (\Throwable $e) {
                return new IP($request->server['remote_addr']);

            }

        } else {
            return new IP($request->server['remote_addr']);

        }
    }

    /**
     * check the blocklist for the given IP
     */
    private function isBlocked(IP $ip) : bool {
        foreach ($this->blocked as $block) if ($block->contains($ip)) return true;
        return false;
    }

    protected function help() : string {
        return json_encode([
            'rdapConformance' => ['rdap_level_0'],
            'notices' => [[
                'title' => 'About this service',
                'description' => [
                    'RDAP.org aims to support users and developers of RDAP clients by providing a "bootstrap server", i.e. single end point for RDAP queries.',
                    'RDAP.org aggregates information about all known RDAP servers. RDAP clients can send RDAP queries to RDAP.org, which will then redirect requests to the appropriate RDAP service.'
                ],
                'links' => [[
                    'title' => 'Further information',
                    'value' => 'https://about.rdap.org',
                    'href'  => 'https://about.rdap.org',
                    'rel'   => 'related',
                ]],
            ]]
        ]);
    }
}
