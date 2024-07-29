<?php declare(strict_types=1);

namespace rdap_org;

use GMP;

/**
 * class represent an IP address or CIDR block - which may be IPv4 or IPv6
 */
class ip implements \Stringable {

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

    /**
     * @var int either AF_INET or AF_INET6
     */
    public readonly int $family;

    /**
     * @var int 32 for v4, 128 for v6
     */
    public readonly int $len;

    public function __construct(string $block) {
        $parts = explode('/', $block, 2);

        $ip = inet_pton(array_shift($parts));
        if (false === $ip) throw new error("Invalid IP address '{$block}'");

        $this->addr     = gmp_import($ip);
        $this->family   = (4 == strlen($ip) ? AF_INET : AF_INET6);
        $this->len      = 8 * strlen($ip);
        $this->mlen     = intval(array_shift($parts) ?? $this->len);
        $this->minAddr  = clone($this->addr);
        $this->maxAddr  = clone($this->addr);

        if ($this->mlen < 0 || $this->mlen > $this->len) throw new error("Invalid mask length {$this->mlen}");

        for ($i = 0 ; $i < $this->len - $this->mlen ; $i++) {
            gmp_clrbit($this->minAddr, $i);
            gmp_setbit($this->maxAddr, $i);
        }
    }

    /**
     * does this block contain $block?
     */
    public function contains(IP $block) : bool {
        return (
            $this->family == $block->family &&
            gmp_cmp($block->minAddr, $this->minAddr) > -1 &&
            gmp_cmp($block->maxAddr, $this->maxAddr) < 1
        );
    }

    public function __toString() : string {
        $str = inet_ntop(gmp_export($this->addr));

        if ($this->mlen < $this->len) $str .= '/'.$this->mlen;

        return (string)$str;
    }
}
