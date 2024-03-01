<?php declare(strict_types=1);

namespace RDAP;

require_once __DIR__.'/Error.php';

use OpenSwoole\HTTP\{Request,Response};

/**
 * @codeCoverageIgnore
 */
class RootServer extends Server {

    private const ROOTDIR = '/tmp/cache/tlds';
    private const RARDIR = '/tmp/cache/registrars';

    /**
     * load the registry data and then start the server
     */
    public function start() : bool {
        fwrite($this->STDERR, "ready to accept requests!\n");
        return \OpenSwoole\HTTP\Server::start();
    }

    /**
     * handle a request
     */
    protected function generateResponse(Request $request, Response $response) : int {

        $path = $this->getPathSegments($request);

        if (0 == count($path)) {
            $response->header('location', 'https://about.rdap.org/');
            return self::FOUND;

        } elseif (count($path) > 2) {
            //
            // incorrect number of segments
            //
            return self::BAD_REQUEST;

        } else {
            $content = match(count($path)) {
                1 => match($path[0]) {
                    'heartbeat' => 'OK',
                    'help'      => $this->help(),
                    'domains'   => $this->tlds(),
                    'entities'  => $this->registrars(),
                    default     => null,
                },

                2 => match($path[0]) {
                    'domain'    => $this->tld($path[1]),
                    'entity'    => $this->registrar($path[1]),
                    default     => null,
                },
            };

            if (is_null($content)) return SELF::NOT_FOUND;

            $response->write($content);
            return SELF::OK;
        }
    }

    /**
     * update data
     * @param bool $background if true, scripts will be run in the background
     */
    protected function updateData(bool $background=true) : void {
        // do nothing
    }

    private function tld(string $tld) : ?string {
        return @file_get_contents(sprintf(self::ROOTDIR.'/%s.json', $tld)) ?: null;
    }

    private function registrar(string $id) : ?string {
        return @file_get_contents(sprintf(self::RARDIR.'/%s.json', $id)) ?: null;
    }

    private function tlds() : ?string {
        return @file_get_contents(self::ROOTDIR.'/_all.json') ?: null;
    }

    private function registrars() : ?string {
        return @file_get_contents(self::RARDIR.'/_all.json') ?: null;
    }

    protected function help() : string {
        return strval(json_encode([
            'rdapConformance' => ['rdap_level_0'],
            'notices' => [[
                'title' => 'About this service',
                'description' => [
                    'This service provides information about top-level domains and ICANN-accredited registrars. It is NOT provided by the IANA.'
                ],
                'links' => [[
                    'title' => 'Further information',
                    'value' => 'https://about.rdap.org',
                    'href'  => 'https://about.rdap.org',
                    'rel'   => 'related',
                ]],
            ]]
        ]));
    }
}
