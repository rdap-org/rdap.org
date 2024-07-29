<?php

declare(strict_types=1);

use rdap_org\ip;

class ipTests extends PHPUnit\Framework\TestCase {

    public static function ipData(): array {
        return [
            ['192.168.1.1',         true    ],
            ['192.168.1.0',         true    ],
            ['192.168.1.255',       true    ],
            ['192.168.1.256',       false   ],
            ['192.168.1.0/32',      true    ],
            ['192.168.1.0/16',      true    ],
            ['192.168.1.0/33',      false   ],
            ['0.0.0.0',             true    ],
            ['0.0.0.0/0',           true    ],
            ['255.255.255.255/32',  true    ],
            ['192.168.1.1/-1',      false   ],
            ['::',                  true    ],
            [':::',                 false   ],
            ['::1',                 true    ],
            ['::/128',              true    ],
            ['::/129',              false   ],
            ['::/0',                true    ],
            ['::/-1',               false   ],
            ['::192.168.0.1',       true    ],
            ['::192.168.0.1/48',    true    ],
            ['dead:beef::',         true    ],
            ['dead:cow::',          false   ],
        ];
    }

    /**
     * @dataProvider ipData
     */
    public function testIPAddressParser(string $ip, bool $success): void {
        try {
            new ip($ip);
            $result = true;
        } catch (Throwable $e) {
            $result = false;
        }

        $this->assertEquals($success, $result);
    }

    public static function ipContainsData() : array {
        return [
            [ '0.0.0.0/0', '1.1.1.1',               true],
            [ '2.0.0.0/8', '1.1.1.1',               false],
            [ '2.0.0.0/8', '::',                    false],
            [ '::/0',      '2.0.0.0/8',             false],
            [ '192.168.1.0/25', '192.168.1.128',    false],
            [ '192.168.1.0/25', '192.168.1.127',    true],
        ];
    }

    /**
     * @dataProvider ipContainsData
     */
    public function testContains(string $a, string $b, bool $result): void {
        $this->assertEquals($result, (new ip($a))->contains(new ip($b)));
    }

    public static function stringData() : array {
        return [
            ['::',              '::'],
            ['::192.168.1.1',   '192.168.1.1'],
            ['192.168.1.1/32',  '192.168.1.1'],
            ['192.168.1.0/24',  '192.168.1.0/24'],
        ];
    }

    /**
     * @dataProvider stringData
     */
    public function testStringable(string $ip, string $result): void {
    }
}
