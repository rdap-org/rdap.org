<?php declare(strict_types=1);

namespace rdap_org;

/**
 * this class represents an RDAP bootstrap registry, which
 * maps a "resource" (TLD, object tag, ASN range, CIDR block)
 * to the appropriate RDAP Base URL
 */
class registry {

    /**
     * the "type" of the registry (ie dns, asn, etc)
     */
    public readonly string $type;

    /**
     * when the registry was last updated (0 means never)
     */
    public float $updated = 0;

    /**
     * get type from URL
     */
    public static function typeFromURL(string $url) : string {
        return basename($url, '.json');
    }

    public function __construct(
        public readonly string $url,
    ) {
        $this->type = self::typeFromURL($this->url);
    }

    /**
     * @var array<int, mixed>
     */
    private array $resources = [];

    /**
     * @var array<int, string>
     */
    private array $urls = [];

    /**
     * return the number of resources in this registry
     */
    private function count() : int {
        return count($this->resources);
    }

    /**
     * get the resource at position $i
     */
    private function getResource(int $i) : mixed {
        return $this->resources[$i] ?? null;
    }

    /**
     * get the URL for resource $i
     */
    private function getURL(int $i) : ?string {
        return $this->urls[$i] ?? null;
    }

    /**
     * scan through the registry, passing each resource to the callback $cb,
     * and return the corresponding URL to the resource for which the callback
     * returns true
     */
    public function search(callable $cb) : ?string {

        for ($i = 0 ; $i < $this->count() ; $i++) {

            $result = call_user_func($cb, $this->getResource($i));

            if (true === $result) return $this->getURL($i);
        }

        return null;
    }

    public static function parseJSON(?string $data) : ?object {
        if (is_null($data)) return null;

        try {
            $json = json_decode($data, false, 512, JSON_THROW_ON_ERROR);

            if (!is_object($json)) return null;

            return $json;

        } catch (\Throwable $e) {
            error_log(sprintf("%s(): bogus JSON (%s)", __METHOD__, $e->getMessage()));
            return null;

        }
    }

    /**
     * @return int[]
     */
    private function getArrayIndexes() : array {
        //
        // for whatever reason, the object-tag entity has an extra value in the
        // array, so the resources and URLs are at different offsets
        //
        return match($this->type) {
           'object-tags'   => [1, 2],
           default         => [0, 1],
       };
    }

    /**
     * parse a blob of JSON into a registry object
     */
    public static function parse(string $url, ?string $data) : ?registry {

        $json = self::parseJSON($data);

        if (
            !is_object($json) ||
            !property_exists($json, 'services') ||
            !is_array($json->services)
        ) return null;

        //
        // derive the "type" of this registry from the filename
        //
        $registry = new self($url);

        list($i, $j) = $registry->getArrayIndexes();

        foreach ($json->services as $service) {
            if (!is_array($service) || !is_array($service[$i])) continue;

            foreach ($service[$i] as $resource) {
                if (!is_array($service[$j])) continue;

                //
                // remove any trailing slashes from the URL (just so the URL
                // construction in handleRequest() looks cleaner)
                //
                $url = preg_replace('/\/+$/', '', self::chooseURL($service[$j]));

                if (is_string($url)) {
                    //
                    // coerce the resource into the appropriate type
                    //
                    $resource = match($registry->type) {
                        'asn'   => array_map(fn($i) => intval($i), explode('-', $resource, 2)),
                        'ipv4'  => new ip($resource),
                        'ipv6'  => new ip($resource),
                        default => strtolower($resource),
                    };

                    $registry->resources[]  = $resource;
                    $registry->urls[]       = $url;
                }
            }
        }

        $registry->updated = microtime(true);

        return $registry;
    }

    function update(?string $data) : void {
        $json = self::parseJSON($data);

        if (!is_object($json)) return;

        list($i, $j) = $this->getArrayIndexes();
    }

    /**
     * picks the first HTTPS URL found in the array, or the first URL if no HTTPS URL is found
     * @param array<string> $urls
     */
    private static function chooseURL(array $urls) : string {
        foreach ($urls as $url) if (str_starts_with($url, 'https://')) return $url;
        return $urls[0];
    }
}
