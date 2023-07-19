<?php

declare(strict_types=1);

class integrationTests extends PHPUnit\Framework\TestCase {

    public function testIntegration(): void {
        global $argv;
        require_once dirname(__DIR__).'/rdapd';
    }

    public function testClasses(): void {
        $this->assertTrue(class_exists('\\RDAP\\Server'));
        $this->assertTrue(class_exists('\\RDAP\\Registry'));
        $this->assertTrue(class_exists('\\RDAP\\IP'));
        $this->assertTrue(class_exists('\\RDAP\\Error'));
        $this->assertTrue(class_exists('rdapd'));
    }
}
