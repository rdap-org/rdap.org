<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use rdap_org\{registry,ip};

class registryTests extends PHPUnit\Framework\TestCase {

    private static array $registries = [];

    public static function setUpBeforeClass():void {
        global $argv;

        require_once dirname(__DIR__).'/rdapd';
        self::$registries = registry::load();
    }

    public static function registryData(): array {
        return [['dns'], ['ip'], ['asn'], ['object-tags']];
    }

    #[DataProvider("registryData")]
    public function testRegistry(string $type): void {
        $this->assertArrayHasKey($type, self::$registries);
    }

    public static function domainTestData(): array {
        return [
            [ 'abc.xyz',            'https://rdap.centralnic.com/xyz'               ],
            [ 'verisign-grs.com',   'https://rdap.verisign.com/com/v1'              ],
            [ 'rdap.org',           'https://rdap.publicinterestregistry.org/rdap'  ],
            [ 'bbc.co.uk',          'https://rdap.nominet.uk/uk'                    ]
        ];
    }

    #[DataProvider("domainTestData")]
    public function testDomainRegistry(string $domain, string $url): void {

        $result = self::$registries['dns']->search(fn($tld) => str_ends_with($domain, '.'.$tld));

        $this->assertIsString($result);
        $this->assertEquals($url, $result);
    }

    public static function ipTestData(): array {
        return [
            [ '1.1.1.1',        'https://rdap.apnic.net',           ],
            [ '8.8.8.8',        'https://rdap.arin.net/registry',   ],
            [ '2001:2002::',    'https://rdap.db.ripe.net',         ],
        ];
    }

    #[DataProvider("ipTestData")]
    public function testIPRegistry(string $ip, string $url): void {
        $ip = new ip($ip);

        $result = self::$registries['ip']->search(fn($range) => $range->contains($ip));

        $this->assertIsString($result);
        $this->assertEquals($url, $result);
    }
}
