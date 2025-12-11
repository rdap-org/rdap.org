<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

class serverTests extends PHPUnit\Framework\TestCase {
    const baseURL = 'http://127.0.0.1:8080';

    public static function urlData():array {
        return [
            ['/heartbeat',          200],
            ['/help',               200],
            ['/domain/rdap.org',    302],
            ['/ip/8.8.8.8',         302],
            ['/ip/8.8.8.8/32',      302],
            ['/autnum/1701',        302],
        ];
    }

    #[DataProvider("urlData")]
    public function testURLs(string $path, int $expected): void {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => self::baseURL.$path,
            CURLOPT_RETURNTRANSFER  => true,
        ]);

        curl_exec($ch);
        $actual = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $this->assertEquals($expected, $actual);
    }
}
