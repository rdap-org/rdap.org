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
            [ 'bbc.co.uk',          'https://rdap.nominet.uk/uk'                    ],
            [ 'invalid.invalid',    null                                            ]
        ];
    }

    #[DataProvider("domainTestData")]
    public function testDomainRegistry(string $domain, ?string $url) : void {

        $result = self::$registries->get('dns')->search(fn($tld) => str_ends_with($domain, '.'.$tld));

        $this->assertTrue($url === $result);
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

        $result = self::$registries->get($type)->search(fn($range) => new ip($range)->contains($ip));

        $this->assertIsString($result);
        $this->assertEquals($url, $result);
    }

    public static function jsonParseData() : array {
        return [
            [ null,     false   ],
            [ 'null',   false   ],
            [ 'false',  false   ],
            [ '',       false   ],
            [ '[]',     false   ],
            [ '{}',     true    ],
        ];
    }

    #[DataProvider("jsonParseData")]
    public function testJsonParse(?string $data, bool $success) : void {
        $result = registry::parseJSON($data);
        $this->assertTrue($success === is_object($result));
    }

    public static function parseData() : array {
        return [
            [ 'null',               false   ],
            [ 'false',              false   ],
            [ '',                   false   ],
            [ '[]',                 false   ],
            [ '{}',                 false   ],
            [ '{"services":true}',  false   ],
            [ '{"services":[]}',    true    ],
        ];
    }

    #[DataProvider("parseData")]
    public function testParse(?string $data, bool $success) : void {
        $result = registry::parse('https://www.example.com/', $data);
        $this->assertTrue($success === $result instanceof registry);
    }
}
