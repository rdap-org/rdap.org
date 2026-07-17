<?php

declare(strict_types=1);

use rdap_org\{error,registryManager};
use PHPUnit\Framework\Attributes\DataProvider;
use Uri\Rfc3986\Uri;

class registryManagerTests extends PHPUnit\Framework\TestCase {
    public function testregistryManager() : void {
        $this->expectNotToPerformAssertions();

        $mgr = new registryManager;

        $mgr->get('dns');

        $mgr->update();        

        try {
            $mgr->get("invalid");
            $this->assertTrue(false);

        } catch (\Throwable $e) {
            $this->assertInstanceOf(error::class, $e);

        }
    }
}
