<?php

namespace Aerys;

use Amp\Deferred;
use Amp\Loop;

class WorkerProcess extends Process {
    private $logger;
    private $ipcSock;
    private $bootstrapper;
    private $server;

    public function __construct(IpcLogger $logger, $ipcSock, Bootstrapper $bootstrapper = null) {
        parent::__construct($logger);
        $this->logger = $logger;
        $this->ipcSock = $ipcSock;
        $this->bootstrapper = $bootstrapper ?: new Bootstrapper;
    }

    public function recvServerSocketCallback($addrCtxMap) {
        $deferred = new Deferred;

        $json = json_encode(array_map(function ($context) { return $context["socket"]; }, $addrCtxMap));
        $data = "\x1" . pack("N", \strlen($json)) . $json;
        // Logger must not be writing at the same time as we do here
        $this->logger->disableSending()->onResolve(function () use (&$data, $deferred, $addrCtxMap) {
            Loop::onWritable($this->ipcSock, function ($watcherId, $socket) use (&$data, $deferred, $addrCtxMap) {
                $bytesWritten = \fwrite($socket, $data);
                if ($bytesWritten === false || ($bytesWritten === 0 && (!\is_resource($socket) || @\feof($socket)))) {
                    $deferred->fail(new \RuntimeException("Server context data could not be transmitted to watcher process"));
                    Loop::cancel($watcherId);
                }

                $data = \substr($data, $bytesWritten);
                if ($data != "") {
                    return;
                }

                Loop::cancel($watcherId);
                $this->logger->enableSending();

                $gen = (function () use ($socket, &$watcherId, $deferred, $addrCtxMap) {
                    $serverSockets = [];

                    // number of sockets followed by sockets with address in iov(ec)
                    $data = "";
                    do {
                        yield;
                        $data .= fread($socket, 4 - \strlen($data));
                    } while (\strlen($data) < 4);
                    $sockets = unpack("Nlength", $data)["length"];

                    // block the recvmsg (IF there is data available), otherwise we risk getting Warning: socket_recvmsg(): error converting native data (path: msghdr > control > element #1 > data): error creating resource for received file descriptor %d: fstat() call failed with errno 9
                    \stream_set_blocking($socket, true);
                    while ($sockets--) {
                        yield;
                        $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4];
                        if (!\socket_recvmsg(\socket_import_stream($socket), $data)) {
                            $deferred->fail(new \RuntimeException("Server sockets could not be received from watcher process"));
                            Loop::cancel($watcherId);
                        }
                        $address = $data["iov"][0];
                        $newSock = $data["control"][0]["data"][0];
                        \socket_listen($newSock, $addrCtxMap[$address]["socket"]["backlog"] ?? 0);

                        $newSocket = \socket_export_stream($newSock);
                        \stream_context_set_option($newSocket, $addrCtxMap[$address]); // put eventual options like ssl back (per worker)
                        $serverSockets[$address] = $newSocket;
                    }
                    \stream_set_blocking($socket, false);

                    Loop::cancel($watcherId);
                    $deferred->resolve($serverSockets);
                })();
                $watcherId = Loop::onReadable($socket, function () use ($gen) {
                    $gen->next();
                });
            });
        });

        return $deferred->promise();
    }

    protected function doStart(Console $console): \Generator {
        // Shutdown the whole server in case we needed to stop during startup
        register_shutdown_function(function () use ($console) {
            if (!$this->server) {
                // ensure a clean reactor for clean shutdown
                Loop::run(function () use ($console) {
                    yield (new CommandClient((string) $console->getArg("config")))->stop();
                });
            }
        });

        $server = yield from $this->bootstrapper->boot($this->logger, $console);
        if ($console->isArgDefined("socket-transfer")) {
            \assert(\extension_loaded("sockets") && PHP_VERSION_ID > 70007);
            yield $server->start([$this, "recvServerSocketCallback"]);
        } else {
            yield $server->start();
        }
        $this->server = $server;
        Loop::onReadable($this->ipcSock, function ($watcherId) {
            Loop::cancel($watcherId);
            yield from $this->stop();
        });
    }

    protected function doStop(): \Generator {
        if ($this->server) {
            yield $this->server->stop();
        }
        if (\method_exists($this->logger, "flush")) {
            $this->logger->flush();
        }
    }

    protected function exit() {
        if (\method_exists($this->logger, "flush")) {
            $this->logger->flush();
        }
        parent::exit();
    }
}
