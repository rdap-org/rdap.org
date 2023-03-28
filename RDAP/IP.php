<?php declare(strict_types=1);

namespace RDAP;

use GMP;

/**
 * class represent an IP address or CIDR block - which may be IPv4 or IPv6
 */
class IP implements \Stringable {

    /**
     * the IP address
     */
    public readonly GMP $addr;

    /**
     * the lowest address in the CIDR range
     */
    public readonly GMP $minAddr;

    /**
     * the highest address in the CIDR range
     */
    public readonly GMP $maxAddr;

    /**
     * the mask length in bits
     */
    public readonly int $mlen;

    public function __construct(string $block) {
        $parts = explode('/', $block, 2);

        $ip = inet_pton(array_shift($parts));
        if (false === $ip) throw new Error("Invalid IP address '{$block}'");

        $bits = 8 * strlen($ip);

        $this->mlen     = intval(array_shift($parts) ?? $bits);
        $this->addr     = gmp_import($ip);
        $this->minAddr  = clone($this->addr);
        $this->maxAddr  = clone($this->addr);

        for ($i = 0 ; $i < $bits - $this->mlen ; $i++) {
            gmp_clrbit($this->minAddr, $i);
            gmp_setbit($this->maxAddr, $i);
        }
    }

    /**
     * does this block contain $block?
     */
    public function contains(IP $block) : bool {
        return gmp_cmp($block->minAddr, $this->minAddr) > -1 && gmp_cmp($block->maxAddr, $this->maxAddr) < 1;
    }

    public function __toString() : string {
        return sprintf('%s/%u', inet_ntop(gmp_export($this->addr)), $this->mlen);
    }
}
