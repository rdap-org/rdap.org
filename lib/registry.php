<?php declare(strict_types=1);

namespace rdap_org;

/**
 * this class represents an RDAP bootstrap registry, which
 * maps a "resource" (TLD, object tag, ASN range, CIDR block)
 * to the appropriate RDAP Base URL
 */
class registry {

    /**
     * list of IANA bootstrap registries
     * @var string[]
     */
    private static array $registryIDs = ['dns', 'ipv4', 'ipv6', 'asn', 'object-tags'];

    /**
     * list of IP bootstrap registries (that are coalesced into a single "ip" registry)
     * @var string[]
     */
    private static array $ipTypes = ['ipv4', 'ipv6'];

    /**
     * @var array<int, mixed>
     */
    private array $resources = [];

    /**
     * @var array<int, string>
     */
    private array $urls = [];

    /**
     * add a resource and its corresponding URL to the registry
     */
    public function add(mixed $resource, string $url) : void {
        $this->resources[] = $resource;
        $this->urls[] = $url;
    }

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

    /**
     * return an array of registry objects
     * @return registry[]
     */
    public static function load() : array {
        return self::parseRegistryData(self::loadRegistryData());
    }

    /**
     * load IANA registry data over multiple parallel HTTP transfers
     * @param array<string, string|null> $data
     * @return registry[]
     */
    private static function parseRegistryData(array $data): array {
        $registries = [];

        foreach (self::$registryIDs as $key) {
            if (is_null($data[$key])) continue;

            $json = json_decode($data[$key], false, 512, JSON_THROW_ON_ERROR);

            //
            // v4 and v6 registries are coalesced into a single "ip" registry
            //
            if (in_array($key, self::$ipTypes)) $key = 'ip';

            if (!isset($registries[$key])) $registries[$key] = new registry;

            //
            // for whatever reason, the object-tag entity has an extra value in the
            // array, so the resources and URLs are at different offsets
            //
            list($i, $j) = match($key) {
                'object-tags'   => [1, 2],
                default         => [0, 1],
            };

            foreach ($json->services as $service) {
                foreach ($service[$i] as $resource) {

                    //
                    // coerce the resource into the appropriate type
                    //
                    $resource = match($key) {
                        'asn'   => array_map(fn($i) => intval($i), explode('-', $resource, 2)),
                        'ip'    => new ip($resource),
                        default => strtolower($resource),
                    };

                    //
                    // remove any trailing slashes from the URL (just so the URL construction
                    // in handleRequest() looks cleaner)
                    //
                    $url = preg_replace('/\/+$/', '', self::chooseURL($service[$j]));

                    if (is_string($url)) $registries[$key]->add($resource, $url);
                }
            }
        }

        return $registries;
    }

    /**
     * load IANA registry data over multiple parallel HTTP transfers
     * @return array<string, string|null>
     */
    private static function loadRegistryData(): array {
        $mh = curl_multi_init();

        //
        // create curl handlers for each URL and add them to the multi-handler
        //
        $handles = [];
        foreach (self::$registryIDs as $key) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, sprintf('https://data.iana.org/rdap/%s.json', $key));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $handles[$key] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        //
        // execute the curl handles in parallel
        //
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh);
        } while ($active && CURLM_OK == $status);

        //
        // extract JSON payloads from each curl handle
        //
        $data = [];

        foreach ($handles as $key => $ch) {
            $data[$key] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        return $data;
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
