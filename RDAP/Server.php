<?php declare(strict_types=1);

namespace RDAP;

foreach (glob(__DIR__.'/{Registry,Error,IP}.php', GLOB_BRACE) as $f) require_once $f;

use OpenSwoole\HTTP\{Request,Response};
use OpenSwoole\Constant;

class Server extends \OpenSwoole\HTTP\Server {

    /**
     * this stores the bootstrap registries and is populated by loadRegistries()
     */
    private array $registries;

    /*
     * private handle for STDOUT
     * @var resource
     */
    private $STDOUT;

    /*
     * private handle for STDERR
     * @var resource
     */
    private $STDERR;

    /*
     * how long between refreshses of the registry data (in seconds)
     */
    private const registryTTL = 3600;

    /**
     * array of blocked client addresses
     */
    private array $blocked = [];

    private const OK            = 200;
    private const FOUND         = 302;
    private const BAD_REQUEST   = 400;
    private const FORBIDDEN     = 403;
    private const NOT_FOUND     = 404;
    private const ERROR         = 500;

    public function __construct(string $host='::', int $port=8080, int $mode=self::POOL_MODE, int $sock_type=Constant::SOCK_TCP) {
        parent::__construct($host, $port, $mode, $sock_type);

        $this->STDOUT = fopen('php://stdout', 'w');
        $this->STDERR = fopen('php://stderr', 'w');

        $this->blocked = array_map(
            fn($ip) => new IP($ip),
            preg_split('/,/', getenv('IP_BLOCK_LIST') ?: '', -1, PREG_SPLIT_NO_EMPTY)
        );

        $this->on('Request', function (Request $request, Response $response) {
            $response->header('access-control-allow-origin', '*');
            $response->header('content-type', 'application/rdap+json');

            try {
                $peer = $this->getPeer($request);

                $blocked = $this->isBlocked($peer);

            } catch (\Throwable $e) {
                fwrite($this->STDERR, $e->getMessage()."\n");

                $blocked = false;
            }

            try {
                $status = ($blocked ? self::FORBIDDEN : $this->handleRequest($request, $response));

            } catch (\Throwable $e) {
                fwrite($this->STDERR, $e->getMessage()."\n");

                $status = self::ERROR;

            } finally {
                $response->status($status);

                $response->end();

                $this->logRequest($request, $status, $peer);
            }
        });
    }

    /**
     * load the registry data and then start the server
     */
    public function start() : bool {
        fwrite($this->STDERR, "loading registry data...\n");
        $this->loadRegistries();
        fwrite($this->STDERR, "ready to accept requests!\n");
        return parent::start();
    }

    private function getPeer(Request $request) : IP {
        if (isset($request->header['x-forwarded-for'])) {
            $list = preg_split('/[ \t]*,[ \t]*/', trim($request->header['x-forwarded-for']), -1, PREG_SPLIT_NO_EMPTY);

            try {
                return new IP(array_pop($list));

            } catch (\Throwable $e) {
                return new IP($request->server['remote_addr']);

            }
        }

        return new IP($request->server['remote_addr']);
    }

    private function isBlocked(IP $ip) : bool {
        foreach ($this->blocked as $block) if ($block->contains($ip)) return true;
        return false;
    }

    /**
     * handle a request
     */
    private function handleRequest(Request $request, Response $response) : int {
        //
        // split the path into segments
        //
        $path = preg_split('/\//', strtolower($request->server['request_uri']), 2, PREG_SPLIT_NO_EMPTY);

        if (0 == count($path)) {
            //
            // empty path, so redirect to the about page
            //
            $url = 'https://about.rdap.org/';

        } elseif (1 == count($path) && 'heartbeat' == $path[0]) {
            //
            // heartbeat request for internal monitoring purposes
            //
            return SELF::OK;

        } elseif (2 != count($path)) {
            //
            // incorrect number of segments
            //
            return self::BAD_REQUEST;

        } else {
            list($type, $object) = $path;

            //
            // coerce the object into the appropriate type
            //
            try {
                $object = match ($type) {
                    'ip'        => new IP($object),
                    'autnum'    => intval($object),
                    default     => $object,
                };
            } catch (Error $e) {
                //
                // object was somehow malformed or unparseable
                //
                return self::BAD_REQUEST;

            }

            //
            // look up the URL for the requested object
            //
            $url = match ($type) {
                'domain'    => $this->domain($object),
                'entity'    => $this->entity($object),
                'autnum'    => $this->autnum($object),
                'ip'        => $this->ip($object),
                default     => null,
            };

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
    private function loadRegistries() : void {
        try {
            $this->registries = Registry::load();

        } catch (Error $e) {
            fwrite($this->STDERR, $e->getMessage()."\n");

            if (empty($this->registries)) exit(1);
        }

        //
        // schedule a refresh
        //
        $this->after(1000 * self::registryTTL, fn() => $this->loadRegistries());
    }
}
