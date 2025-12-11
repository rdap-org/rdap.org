<?php

declare(strict_types=1);

class integrationTests extends PHPUnit\Framework\TestCase {

    public function testIntegration(): void {
        global $argv;
        require_once dirname(__DIR__).'/rdapd';
    }

    public function testClasses(): void {
        $this->assertTrue(class_exists('\\rdap_org\\server'));
        $this->assertTrue(class_exists('\\rdap_org\\registry'));
        $this->assertTrue(class_exists('\\rdap_org\\iP'));
        $this->assertTrue(class_exists('\\rdap_org\\error'));
        $this->assertTrue(class_exists('\\rdap_org\\rdapd'));
    }
}
