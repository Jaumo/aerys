<?php

namespace Aerys\Test;

use Aerys\Client;
use Aerys\Response;
use Aerys\Root;
use Aerys\Server;
use Aerys\ServerObserver;
use Aerys\StandardResponse;
use Amp\Loop;
use PHPUnit\Framework\TestCase;

class RootTest extends TestCase {
    /** @var \Amp\Loop\Driver */
    private static $loop;

    private static function fixturePath() {
        return \sys_get_temp_dir() . "/aerys_root_test_fixture";
    }

    /**
     * Setup a directory we can use as the document root.
     */
    public static function setUpBeforeClass() {
        self::$loop = Loop::get();

        $fixtureDir = self::fixturePath();
        if (!file_exists($fixtureDir) && !\mkdir($fixtureDir)) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory: {$fixtureDir}"
            );
        }
        if (!file_exists($fixtureDir. "/dir") && !\mkdir($fixtureDir . "/dir")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture directory"
            );
        }
        if (!\file_put_contents($fixtureDir . "/index.htm", "test")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture file"
            );
        }
        if (!\file_put_contents($fixtureDir . "/svg.svg", "<svg></svg>")) {
            throw new \RuntimeException(
                "Failed creating temporary test fixture file"
            );
        }
    }

    public static function tearDownAfterClass() {
        $fixtureDir = self::fixturePath();
        if (!@\file_exists($fixtureDir)) {
            return;
        }
        if (\stripos(\PHP_OS, "win") === 0) {
            \system('rd /Q /S "' . $fixtureDir . '"');
        } else {
            \system('/usr/bin/env rm -rf ' . \escapeshellarg($fixtureDir));
        }
    }

    /**
     * Restore original loop driver instance as data providers require the same driver instance to be active as when
     * the data was generated.
     */
    public function setUp() {
        Loop::set(self::$loop);
    }

    /**
     * @dataProvider provideBadDocRoots
     * @expectedException \Error
     * @expectedExceptionMessage Document root requires a readable directory
     */
    public function testConstructorThrowsOnInvalidDocRoot($badPath) {
        $filesystem = $this->createMock('Amp\File\Driver');
        $root = new Root($badPath, $filesystem);
    }

    public function provideBadDocRoots() {
        return [
            [self::fixturePath() . "/some-dir-that-doesnt-exist"],
            [self::fixturePath() . "/index.htm"],
        ];
    }

    public function testBasicFileResponse() {
        $root = new Root(self::fixturePath());
        $server = new class extends Server {
            function __construct() {
            }
            function attach(ServerObserver $obj) {
            }
            function detach(ServerObserver $obj) {
            }
            function getOption(string $option) {
                return true;
            }
            function state(): int {
                return Server::STARTING;
            }
        };
        $root->update($server);
        foreach ([
            ["/", "test"],
            ["/index.htm", "test"],
            ["/dir/../dir//..//././index.htm", "test"],
        ] as list($path, $contents)) {
            $request = $this->createMock('Aerys\Request');
            $request->expects($this->once())
                ->method("getUri")
                ->will($this->returnValue($path));
            $request->expects($this->any())
                ->method("getMethod")
                ->will($this->returnValue("GET"));
            $response = $this->createMock('Aerys\Response');
            $response->expects($this->once())
                ->method("end")
                ->with($contents);
            $response->expects($this->atLeastOnce())
                ->method("setHeader")
                ->will($this->returnCallback(function ($header, $value) use ($response, &$wasCalled): Response {
                    if ($header === "Content-Type") {
                        $this->assertEquals("text/html; charset=utf-8", $value);
                        $wasCalled = true;
                    }
                    return $response;
                }));
            $generator = $root->__invoke($request, $response);
            $promise = new \Amp\Coroutine($generator);
            \Amp\Promise\wait($promise);
            $this->assertTrue($wasCalled);
        }

        // Return so we can test cached responses in the next case
        return $root;
    }

    /**
     * @depends testBasicFileResponse
     * @dataProvider provideRelativePathsAboveRoot
     */
    public function testPathsOnRelativePathAboveRoot($relativePath, $root) {
        $request = $this->createMock("Aerys\\Request");
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue($relativePath));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock("Aerys\\Response");
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /index.htm fixture file
;
        $root->__invoke($request, $response);
    }

    public function provideRelativePathsAboveRoot() {
        return [
            ["/../../../index.htm"],
            ["/dir/../../"],
        ];
    }

    /**
     * @depends testBasicFileResponse
     * @dataProvider provideUnavailablePathsAboveRoot
     */
    public function testUnavailablePathsOnRelativePathAboveRoot($relativePath, $root) {
        $request = $this->createMock("Aerys\\Request");
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue($relativePath));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock("Aerys\\Response");
        $response->expects($this->never())
            ->method("end");
        $root->__invoke($request, $response);
    }

    public function provideUnavailablePathsAboveRoot() {
        return [
            ["/../aerys_root_test_fixture/index.htm"],
            ["/aerys_root_test_fixture/../../aerys_root_test_fixture"],
        ];
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testCachedResponse($root) {
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /index.htm fixture file
;
        $root->__invoke($request, $response);
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo($root) {
        $server = new class extends Server {
            function __construct() {
            }
            function attach(ServerObserver $obj) {
            }
            function detach(ServerObserver $obj) {
            }
            function getOption(string $option) {
                return true;
            }
            function state(): int {
                return Server::STARTED;
            }
        };
        $root->update($server);

        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->once())
            ->method("getHeaderArray")
            ->will($this->returnValue(["no-cache"]));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /index.htm fixture file
;
        $generator = $root->__invoke($request, $response);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);

        return $root;
    }

    /**
     * @depends testDebugModeIgnoresCacheIfCacheControlHeaderIndicatesToDoSo
     */
    public function testDebugModeIgnoresCacheIfPragmaHeaderIndicatesToDoSo($root) {
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->exactly(2))
            ->method("getHeaderArray")
            ->will($this->onConsecutiveCalls([], ["no-cache"]));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /index.htm fixture file
;
        $generator = $root->__invoke($request, $response);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);

        return $root;
    }

    public function testOptionsHeader() {
        $root = new Root(self::fixturePath());
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/"));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("OPTIONS"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->exactly(2))
            ->method("setHeader")
            ->withConsecutive(["Allow", "GET, HEAD, OPTIONS"], ["Accept-Ranges", "bytes"]);
        $generator = $root->__invoke($request, $response);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);
    }

    public function testPreconditionFailure() {
        $root = new Root(self::fixturePath());
        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->atLeastOnce())
            ->method("getHeader")
            ->with("If-Match")
            ->will($this->returnValue("any value"));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->exactly(1))
            ->method("setStatus")
            ->with(\Aerys\HTTP_STATUS["PRECONDITION_FAILED"]);
        $generator = $root->__invoke($request, $response);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);
    }

    public function testPreconditionNotModified() {
        $root = new Root(self::fixturePath());
        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $etag = md5($diskPath.filemtime($diskPath).filesize($diskPath));
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->any())
            ->method("getHeader")
            ->will($this->returnCallback(function ($header) use ($etag) {
                return [
                "If-Match" => $etag,
                "If-Modified-Since" => "2.1.1970",
            ][$header] ?? "";
            }));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->exactly(1))
            ->method("setStatus")
            ->with(\Aerys\HTTP_STATUS["NOT_MODIFIED"]);
        $response->expects($this->exactly(2))
            ->method("setHeader")
            ->withConsecutive(
                ["Last-Modified", gmdate("D, d M Y H:i:s", filemtime($diskPath))." GMT"],
                ["Etag", $etag]
            );
        $generator = $root->__invoke($request, $response);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);
    }

    public function testPreconditionRangeFail() {
        $root = new Root(self::fixturePath());
        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $etag = md5($diskPath.filemtime($diskPath).filesize($diskPath));
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->any())
            ->method("getHeader")
            ->will($this->returnCallback(function ($header) use ($etag) {
                return [
                "If-Range" => "foo",
            ][$header] ?? "";
            }));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->once())
            ->method("end")
            ->with("test") // <-- the contents of the /index.htm fixture file
;
        $generator = $root->__invoke($request, $response);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);
    }

    public function testBadRange() {
        $root = new Root(self::fixturePath());
        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $etag = md5($diskPath.filemtime($diskPath).filesize($diskPath));
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->any())
            ->method("getHeader")
            ->will($this->returnCallback(function ($header) use ($etag) {
                return [
                "If-Range" => $etag,
                "Range" => "bytes=7-10",
            ][$header] ?? "";
            }));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock('Aerys\Response');
        $response->expects($this->exactly(1))
            ->method("setStatus")
            ->with(\Aerys\HTTP_STATUS["REQUESTED_RANGE_NOT_SATISFIABLE"]);
        $response->expects($this->exactly(1))
            ->method("setHeader")
            ->with("Content-Range", "*/4");
        $generator = $root->__invoke($request, $response);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);
    }

    /**
     * @dataProvider provideValidRanges
     */
    public function testValidRange($range, $cb) {
        $root = new Root(self::fixturePath());
        $root->setOption("useEtagInode", false);
        $diskPath = realpath(self::fixturePath())."/index.htm";
        $request = $this->createMock('Aerys\Request');
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/index.htm"));
        $request->expects($this->any())
            ->method("getHeader")
            ->will($this->returnCallback(function ($header) use ($range) {
                return [
                "If-Range" => "+1 second",
                "Range" => "bytes=$range",
            ][$header] ?? "";
            }));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        // fuck it, I won't use createMock() here, that's horrible, at least for something where the sum of requests counts and not the individual ones...
        $generator = $root->__invoke($request, $res = new StandardResponse((function () use (&$headers, &$body, &$part) {
            $headers = yield;
            while (($part = yield) !== null) {
                $body .= $part;
            }
        })(), new Client));
        $this->assertNull($part);
        $promise = new \Amp\Coroutine($generator);
        \Amp\Promise\wait($promise);
        $this->assertEquals(\Aerys\HTTP_STATUS["PARTIAL_CONTENT"], $headers[":status"]);
        $cb($headers, $body);
    }

    public function provideValidRanges() {
        return [
            ["1-2", function ($headers, $body) {
                $this->assertEquals(2, $headers["content-length"][0]);
                $this->assertEquals("bytes 1-2/4", $headers["content-range"][0]);
                $this->assertEquals("es", $body);
            }],
            ["-0,1-2,2-", function ($headers, $body) {
                $start = "multipart/byteranges; boundary=";
                $this->assertEquals($start, substr($headers["content-type"][0], 0, strlen($start)));
                $boundary = substr($headers["content-type"][0], strlen($start));
                foreach ([["3-3", "t"], ["1-2", "es"], ["2-3", "st"]] as list($range, $text)) {
                    $expected = <<<PART
--$boundary\r
Content-Type: text/plain; charset=utf-8\r
Content-Range: bytes $range/4\r
\r
$text\r

PART;
                    $this->assertEquals($expected, substr($body, 0, strlen($expected)));
                    $body = substr($body, strlen($expected));
                }
                $this->assertEquals("--$boundary--", $body);
            }],
        ];
    }

    /**
     * @depends testBasicFileResponse
     */
    public function testMimetypeParsing($root) {
        $request = $this->createMock("Aerys\\Request");
        $request->expects($this->once())
            ->method("getUri")
            ->will($this->returnValue("/svg.svg"));
        $request->expects($this->any())
            ->method("getMethod")
            ->will($this->returnValue("GET"));
        $response = $this->createMock("Aerys\\Response");
        $response->expects($this->once())
            ->method("end")
            ->with("<svg></svg>") // <-- the contents of the /svg.svg fixture file
;
        $response->expects($this->atLeastOnce())
            ->method("setHeader")
            ->will($this->returnCallback(function ($header, $value) use ($response, &$wasCalled): Response {
                if ($header === "Content-Type") {
                    $this->assertEquals("image/svg+xml", $value);
                    $wasCalled = true;
                }
                return $response;
            }));
        \Amp\Promise\wait(new \Amp\Coroutine($root->__invoke($request, $response)));
    }
}
