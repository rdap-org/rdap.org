<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use rdap_org\{registryManager,registry,ip};

class registryTests extends PHPUnit\Framework\TestCase {

    private static registryManager $registries;

    public static function setUpBeforeClass() : void {
        global $argv;

        require_once dirname(__DIR__).'/rdapd';
        self::$registries = new registryManager;
    }

    public static function domainTestData() : array {
        return [
            [ 'abc.xyz',            'https://rdap.centralnic.com/xyz'               ],
            [ 'verisign-grs.com',   'https://rdap.verisign.com/com/v1'              ],
            [ 'rdap.org',           'https://rdap.publicinterestregistry.org/rdap'  ],
            [ 'bbc.co.uk',          'https://rdap.nominet.uk/uk'                    ]
        ];
    }

    #[DataProvider("domainTestData")]
    public function testDomainRegistry(string $domain, string $url) : void {

        $result = self::$registries->get('dns')->search(fn($tld) => str_ends_with($domain, '.'.$tld));

        $this->assertIsString($result);
        $this->assertEquals($url, $result);
    }

    public static function ipTestData() : array {
        return [
            [ '1.1.1.1',        'https://rdap.apnic.net',           ],
            [ '8.8.8.8',        'https://rdap.arin.net/registry',   ],
            [ '2001:2002::',    'https://rdap.db.ripe.net',         ],
        ];
    }

    #[DataProvider("ipTestData")]
    public function testIPRegistry(string $ip, string $url) : void {
        $ip = new ip($ip);

        $type = match($ip->family) {
            AF_INET6    => 'ipv6',
            AF_INET     => 'ipv4',
            default     => throw new error("Bogus family for '{$ip}'"),
        };

        $result = self::$registries->get($type)->search(fn($range) => $range->contains($ip));

        $this->assertIsString($result);
        $this->assertEquals($url, $result);
    }
}
