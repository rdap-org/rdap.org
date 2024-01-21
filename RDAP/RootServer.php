<?php declare(strict_types=1);

namespace RDAP;

use OpenSwoole\HTTP\{Request,Response};

/**
 * @codeCoverageIgnore
 */
class RootServer extends Server {

    private const ROOTDIR = '/tmp/tlds';
    private const RARDIR = '/tmp/registrars';

    /**
     * load the registry data and then start the server
     */
    public function start() : bool {
        fwrite($this->STDERR, "loading registry data...\n");

        // tell updateData() not to run the updater scripts in the background
        $this->updateData(false);

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
     * @param bool $$background if true, scripts will be run in the background
     */
    protected function updateData(bool $background=true) : void {
        //
        // these are the external commands we want to run, and
        // the directory paths to be provided as their argument
        //
        $cmds = [
            dirname(__DIR__).'/bin/root.pl' => self::ROOTDIR,
            dirname(__DIR__).'/bin/registrars.pl' => self::RARDIR,
        ];

        foreach ($cmds as $cmd => $dir) {
            $fmt = '%s %s 1>&2';
            if ($background) $fmt .= ' &';

            shell_exec(sprintf($fmt, escapeshellcmd($cmd), escapeshellarg($dir)));
        }

        //
        // schedule a refresh
        //
        $this->after(1000 * self::registryTTL, fn() => $this->updateData()); // @phpstan-ignore-line
    }

    /**
     * clean up any finished child processes
     * @var bool $once if true, a timer will not be set to call this method again after 5000ms
     */
    private function cleanProcesses(bool $once=false) : void {
        for ($i = 0 ; $i < count($this->procs) ; $i++) {
            $proc = $this->procs[$i];
            $s = (object)proc_get_status($proc);
            if (true !== $s->running) {
                if (abs($s->exitcode) > 0 && $once) exit($s->exitcode);

                proc_close($proc);
                array_splice($this->procs, $i--, 1);
            }
        }

        if (count($this->procs) > 0 && !$once) {
            $this->after(5000, fn() => $this->cleanProcesses()); // @phpstan-ignore-line
        }
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
}
