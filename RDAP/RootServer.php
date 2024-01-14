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
     * @var resource[]
     */
    private array $procs = [];

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
     */
    protected function updateData() : void {
        //
        // these are the external commands we want to run, and
        // the directory paths to be provided as their argument
        //
        $cmds = [
            dirname(__DIR__).'/bin/root.pl' => self::ROOTDIR,
            dirname(__DIR__).'/bin/registrars.pl' => self::RARDIR,
        ];

        //
        // get all the currently running commands
        //
        $running = [];
        foreach ($this->procs as $proc) {
            $s = proc_get_status($proc);
            if (true === $s['running']) $running[] = $s['command'];
        }

        $p = [];
        foreach ($cmds as $cmd => $dir) {
            //
            // command still running, so skip
            //
            if (in_array($cmd, $running)) continue;

            if (!file_exists($dir)) mkdir($dir, 0755, true);
            $proc = proc_open([$cmd, $dir], [], $p);
            if (false !== $proc) $this->procs[] = $proc;
        }

        $this->after(5000, fn() => $this->cleanProcesses()); // @phpstan-ignore-line

        //
        // schedule a refresh
        //
        $this->after(1000 * self::registryTTL, fn() => $this->updateData()); // @phpstan-ignore-line
    }

    private function cleanProcesses() : void {
        $closed = 0;

        for ($i = 0 ; $i < count($this->procs) ; $i++) {
            $proc = $this->procs[$i];
            $s = proc_get_status($proc);
            if (true !== $s['running']) {
                proc_close($proc);

                array_splice($this->procs, $i--, 1);

                $closed++;
            }
        }

        $this->after(5000, fn() => $this->cleanProcesses()); // @phpstan-ignore-line
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
