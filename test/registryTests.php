<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

class registryTests extends PHPUnit\Framework\TestCase {

    private static array $registries = [];

    public static function setUpBeforeClass():void {
        global $argv;

        require_once dirname(__DIR__).'/rdapd';
        self::$registries = \rdap_org\registry::load();
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
}
