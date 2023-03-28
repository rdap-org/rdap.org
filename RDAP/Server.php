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

    function __construct(string $host='::', int $port=8080, int $mode=self::POOL_MODE, int $sock_type=Constant::SOCK_TCP) {
        parent::__construct($host, $port, $mode, $sock_type);

        $this->STDOUT = fopen('php://stdout', 'w');
        $this->STDERR = fopen('php://stderr', 'w');

        $this->on('Request', function (Request $request, Response $response) {
            try {
                $status = $this->handleRequest($request, $response);

            } catch (\Throwable $e) {
                fwrite($this->STDERR, $e->getMessage()."\n");

                $status = 500;

            } finally {
                $response->status($status);

                $response->end();

                $this->logRequest($request, $status);
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

    /**
     * handle a request
     */
    public function handleRequest(Request $request, Response $response) : int {

        $response->header('access-control-allow-origin', '*');
        $response->header('content-type', 'application/rdap+json');

        //
        // split the path into segments
        //
        $path = preg_split('/\//', strtolower($request->server['request_uri']), 2, PREG_SPLIT_NO_EMPTY);

        if (0 == count($path)) {
            //
            // empty path, so redirect to the about page
            //
            $url = 'https://about.rdap.org/';

        } elseif (2 != count($path)) {
            //
            // incorrect number of segments
            //
            return 400;

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
                return 400;

            }

            //
            // find the URL for the requested object
            //
            $url = match ($type) {
                'domain'    => $this->domain($object),
                'entity'    => $this->entity($object),
                'autnum'    => $this->autnum($object),
                'ip'        => $this->ip($object),
                default     => null,
            };

            if ($url) $url = implode('/', [$url, $type, $object]);
        }

        if (!$url) return 404;

        $response->header('location', $url);

        return 302;
    }

    /**
     * this outputs a log line in Combined Log Format
     */
    private function logRequest(Request $request, int $status) : void {
        fprintf(
            $this->STDOUT,
            "%s - - [%s] \"%s %s %s\" %03u 0 \"%s\" \"%s\"\n",
            $request->server['remote_addr'],
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
    public function domain(string $domain) : ?string {
        return $this->registries['dns']->search(fn($tld) => str_ends_with($domain, '.'.$tld));
    }

    /**
     * get the base URL for the given entity
     */
    public function entity(string $entity) : ?string {
        return $this->registries['object-tags']->search(fn($tag) => str_ends_with($entity, '-'.$tag));
    }

    /**
     * get the base URL for the given ASN
     */
    public function autnum(int $autnum) : ?string {
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
    public function ip(IP $ip) : ?string {
        return $this->registries['ip']->search(fn($range) => $range->contains($ip));
    }

    /**
     * load the IANA registries. this is called once when the server starts
     * and then periodically as a background job
     */
    public function loadRegistries() : void {
        try {
            $this->registries = Registry::loadRegistries();

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
