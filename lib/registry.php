<?php declare(strict_types=1);

namespace rdap_org;

use OpenSwoole\Table;

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

    private Table $table;

    /**
     * @param mixed[] $rows
     */
    public function __construct(
        public readonly string $url,
        array $rows,
    ) {
        $this->type = self::typeFromURL($this->url);

        //
        // since the table is of fixed size, we give ourselves at least 100%
        // headroom to allow it to grow through updates. If the cumulative
        // effect of updates means it runs out of space, a restart is needed.
        //
        $this->table = new Table(max(2*count($rows), 1000));
        $this->table->column('resource',    Table::TYPE_STRING, 128);
        $this->table->column('url',         Table::TYPE_STRING, 256);
        $this->table->create();

        foreach ($rows as $row) {
            $this->table->set($row[0], [
                'resource'  => $row[0],
                'url'       => $row[1]
            ]);
        }
    }

    /**
     * get type from URL
     */
    public static function typeFromURL(string $url) : string {
        return basename($url, '.json');
    }

    /**
     * scan through the registry, passing each resource to the callback $cb,
     * and return the corresponding URL to the resource for which the callback
     * returns true
     */
    public function search(callable $cb) : ?string {

        foreach ($this->table as $row) {
            $result = call_user_func($cb, $row['resource']); // @phpstan-ignore-line

            if (true === $result) return $row['url']; // @phpstan-ignore-line
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
    private static function getArrayIndexes(string $type) : array {
        //
        // for whatever reason, the object-tag entity has an extra value in the
        // array, so the resources and URLs are at different offsets
        //
        return match($type) {
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

        $type = self::typeFromURL($url);
        $rows = self::extractRows($json, $type);

        return new self(
            url:    $url,
            rows:   $rows,
        );
    }

    /**
     * generate a set of table rows from the registry data structure.
     * @return mixed[]
     */
    private static function extractRows(object $json, string $type): array {

        if (
            !property_exists($json, 'services') ||
            !is_array($json->services)
        ) return [];

        list($i, $j) = self::getArrayIndexes($type);

        $rows = [];

        foreach ($json->services as $service) {
            if (!is_array($service) || !is_array($service[$i]) || !is_array($service[$j])) continue;

            //
            // all resources in this service are associated with the same URL.
            //
            // remove any trailing slashes from the URL (just so the URL
            // construction in handleRequest() looks cleaner)
            //
            $url = preg_replace('/\/+$/', '', self::chooseURL($service[$j]));

            if (is_string($url)) {
                foreach ($service[$i] as $resource) {

                    //
                    // coerce the resource into the appropriate type
                    //
                    $resource = match($type) {
                        'ipv4'  => (string)new ip($resource),
                        'ipv6'  => (string)new ip($resource),
                        default => strtolower($resource),
                    };

                    $rows[] = [$resource, $url];
                }
            }
        }

        return $rows;
    }

    public function update(?string $data) : void {

        $json = self::parseJSON($data);

        if (
            !is_object($json) ||
            !property_exists($json, 'services') ||
            !is_array($json->services)
        ) return;

        $rows = self::extractRows($json, $this->type);

        //
        // process updates and additions
        //
        foreach ($rows as $row) {
            $tbl_row = $this->table->get($row[0]);

            if (is_array($tbl_row) && array_key_exists('row', $tbl_row) && $tbl_row['url'] == $row[1]) continue;

            $this->table->set($row[0], [
                'resource'  => $row[0],
                'url'       => $row[1],
            ]);
        }

        //
        // process deletes
        //
        foreach ($this->table as $tbl_row) {
            $seen = false;

            foreach ($rows as $row) {
                if ($row[0] == $tbl_row['resource']) { // @phpstan-ignore-line
                    $seen = true;
                    break;
                }
            }

            if (!$seen) $this->table->del($tbl_row['resource']); // @phpstan-ignore-line
        }
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
