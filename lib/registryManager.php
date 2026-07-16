<?php declare(strict_types=1);

namespace rdap_org;

/**
 * this class manages the IANA registries on behalf of the server.
 */
class registryManager {

    const URL_TEMPLATE = 'https://data.iana.org/rdap/%s.json';

    /**
    * @var array<string, registry>
    */
    private array $registries = [];

    /**
     * list of IANA bootstrap registries. these values correspond to the file
     * name in the URL.
     * @var string[]
     */
    private static array $types = ['dns', 'ipv4', 'ipv6', 'asn', 'object-tags'];

    public function __construct() {
        $urls = self::getRegistryURLs();

        $data = self::getURLs($urls);

        foreach ($data as $url => $registryData) {
            $registry = registry::parse($url, $registryData);

            if (!is_null($registry)) {
                $this->registries[$registry->type] = $registry;
            }
        }
    }

    public function get(string $type) : registry {
        if (!array_key_exists($type, $this->registries)) {
            throw new error("Unknown registry type '{$type}'");

        } else {
            return $this->registries[$type];

        }
    }

    /**
     * returns an array containing the URLs of all RDAP bootstrap registries
     * @return string[]
     */
    public static function getRegistryURLs() : array {
        $urls = [];

        foreach (self::$types as $type) {
            $urls[] = sprintf(self::URL_TEMPLATE, $type);
        }

        return $urls;
    }

    /**
     * do multiple parallel HTTP transfers
     *
     * @param string[] $urls
     * @return array<string|null>
     */
    public static function getURLs(array $urls) : array {
        $mh = curl_multi_init();

        //
        // create curl handlers for each URL and add them to the multi-handler
        //
        $handles = [];
        foreach ($urls as $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url); // @phpstan-ignore-line
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $handles[$url] = $ch;
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
        // extract response bodies from each curl handle
        //
        $data = [];

        foreach ($handles as $url => $ch) {
            $data[$url] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        return $data;
    }
}
