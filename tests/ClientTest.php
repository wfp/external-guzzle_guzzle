<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class ClientTest extends \PHPUnit_Framework_TestCase
{
    public function testUsesDefaultHandler()
    {
        $client = new Client();
        Server::enqueue([new Response(200, ['Content-Length' => 0])]);
        $response = $client->get(Server::$url);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Magic request methods require a URI and optional options array
     */
    public function testValidatesArgsForMagicMethods()
    {
        $client = new Client();
        $client->get();
    }

    public function testCanSendMagicAsyncRequests()
    {
        $client = new Client();
        Server::flush();
        Server::enqueue([new Response(200, ['Content-Length' => 2], 'hi')]);
        $p = $client->getAsync(Server::$url, ['query' => ['test' => 'foo']]);
        $this->assertInstanceOf('GuzzleHttp\Promise\PromiseInterface', $p);
        $this->assertEquals(200, $p->wait()->getStatusCode());
        $received = Server::received(true);
        $this->assertCount(1, $received);
        $this->assertEquals('test=foo', $received[0]->getUri()->getQuery());
    }

    public function testCanSendSynchronously()
    {
        $client = new Client(['handler' => new MockHandler([new Response()])]);
        $request = new Request('GET', 'http://example.com');
        $r = $client->send($request);
        $this->assertInstanceOf('Psr\Http\Message\ResponseInterface', $r);
        $this->assertEquals(200, $r->getStatusCode());
    }

    public function testClientHasOptions()
    {
        $client = new Client([
            'base_uri' => 'http://foo.com',
            'timeout'  => 2,
            'headers'  => ['bar' => 'baz'],
            'handler'  => new MockHandler()
        ]);
        $base = $client->getConfig('base_uri');
        $this->assertEquals('http://foo.com', (string) $base);
        $this->assertInstanceOf('GuzzleHttp\Psr7\Uri', $base);
        $this->assertNotNull($client->getConfig('handler'));
        $this->assertEquals(2, $client->getConfig('timeout'));
        $this->assertArrayHasKey('timeout', $client->getConfig());
        $this->assertArrayHasKey('headers', $client->getConfig());
    }

    public function testCanMergeOnBaseUri()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'base_uri' => 'http://foo.com/bar/',
            'handler'  => $mock
        ]);
        $client->get('baz');
        $this->assertEquals(
            'http://foo.com/bar/baz',
            $mock->getLastRequest()->getUri()
        );
    }

    public function testCanUseRelativeUriWithSend()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client([
            'handler'  => $mock,
            'base_uri' => 'http://bar.com'
        ]);
        $this->assertEquals('http://bar.com', (string) $client->getConfig('base_uri'));
        $request = new Request('GET', '/baz');
        $client->send($request);
        $this->assertEquals(
            'http://bar.com/baz',
            (string) $mock->getLastRequest()->getUri()
        );
    }

    public function testMergesDefaultOptionsAndDoesNotOverwriteUa()
    {
        $c = new Client(['headers' => ['User-agent' => 'foo']]);
        $this->assertEquals(['User-agent' => 'foo'], $c->getConfig('headers'));
        $this->assertInternalType('array', $c->getConfig('allow_redirects'));
        $this->assertTrue($c->getConfig('http_errors'));
        $this->assertTrue($c->getConfig('decode_content'));
        $this->assertTrue($c->getConfig('verify'));
    }

    public function testDoesNotOverwriteHeaderWithDefault()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock
        ]);
        $c->get('http://example.com', ['headers' => ['User-Agent' => 'bar']]);
        $this->assertEquals('bar', $mock->getLastRequest()->getHeaderLine('User-Agent'));
    }

    public function testDoesNotOverwriteHeaderWithDefaultInRequest()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock
        ]);
        $request = new Request('GET', Server::$url, ['User-Agent' => 'bar']);
        $c->send($request);
        $this->assertEquals('bar', $mock->getLastRequest()->getHeaderLine('User-Agent'));
    }

    public function testDoesOverwriteHeaderWithSetRequestOption()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['User-agent' => 'foo'],
            'handler' => $mock
        ]);
        $request = new Request('GET', Server::$url, ['User-Agent' => 'bar']);
        $c->send($request, ['headers' => ['User-Agent' => 'YO']]);
        $this->assertEquals('YO', $mock->getLastRequest()->getHeaderLine('User-Agent'));
    }

    public function testCanUnsetRequestOptionWithNull()
    {
        $mock = new MockHandler([new Response()]);
        $c = new Client([
            'headers' => ['foo' => 'bar'],
            'handler' => $mock
        ]);
        $c->get('http://example.com', ['headers' => null]);
        $this->assertFalse($mock->getLastRequest()->hasHeader('foo'));
    }

    public function testRewriteExceptionsToHttpErrors()
    {
        $client = new Client(['handler' => new MockHandler([new Response(404)])]);
        $res = $client->get('http://foo.com', ['exceptions' => false]);
        $this->assertEquals(404, $res->getStatusCode());
    }

    public function testRewriteSaveToToSink()
    {
        $r = Psr7\stream_for(fopen('php://temp', 'r+'));
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['save_to' => $r]);
        $this->assertSame($r, $mock->getLastOptions()['sink']);
    }

    public function testAllowRedirectsCanBeTrue()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://foo.com', ['allow_redirects' => true]);
        $this->assertInternalType('array',  $mock->getLastOptions()['allow_redirects']);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage allow_redirects must be true, false, or array
     */
    public function testValidatesAllowRedirects()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://foo.com', ['allow_redirects' => 'foo']);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ClientException
     */
    public function testThrowsHttpErrorsByDefault()
    {
        $mock = new MockHandler([new Response(404)]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://foo.com');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage cookies must be an instance of GuzzleHttp\Cookie\CookieJarInterface
     */
    public function testValidatesCookies()
    {
        $mock = new MockHandler([new Response(200, [], 'foo')]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $client->get('http://foo.com', ['cookies' => 'foo']);
    }

    public function testSetCookieToTrueUsesSharedJar()
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'foo=bar']),
            new Response()
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler, 'cookies' => true]);
        $client->get('http://foo.com');
        $client->get('http://foo.com');
        $this->assertEquals('foo=bar', $mock->getLastRequest()->getHeaderLine('Cookie'));
    }

    public function testSetCookieToJar()
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'foo=bar']),
            new Response()
        ]);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);
        $jar = new CookieJar();
        $client->get('http://foo.com', ['cookies' => $jar]);
        $client->get('http://foo.com', ['cookies' => $jar]);
        $this->assertEquals('foo=bar', $mock->getLastRequest()->getHeaderLine('Cookie'));
    }

    public function testCanDisableContentDecoding()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => false]);
        $last = $mock->getLastRequest();
        $this->assertFalse($last->hasHeader('Accept-Encoding'));
        $this->assertFalse($mock->getLastOptions()['decode_content']);
    }

    public function testCanSetContentDecodingToValue()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['decode_content' => 'gzip']);
        $last = $mock->getLastRequest();
        $this->assertEquals('gzip', $last->getHeaderLine('Accept-Encoding'));
        $this->assertEquals('gzip', $mock->getLastOptions()['decode_content']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesHeaders()
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['headers' => 'foo']);
    }

    public function testAddsBody()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['body' => 'foo']);
        $last = $mock->getLastRequest();
        $this->assertEquals('foo', (string) $last->getBody());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesQuery()
    {
        $mock = new MockHandler();
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => false]);
    }

    public function testQueryCanBeString()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => 'foo']);
        $this->assertEquals('foo', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testQueryCanBeArray()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['query' => ['foo' => 'bar baz']]);
        $this->assertEquals('foo=bar%20baz', $mock->getLastRequest()->getUri()->getQuery());
    }

    public function testCanAddJsonData()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, ['json' => ['foo' => 'bar']]);
        $last = $mock->getLastRequest();
        $this->assertEquals('{"foo":"bar"}', (string) $mock->getLastRequest()->getBody());
        $this->assertEquals('application/json', $last->getHeaderLine('Content-Type'));
    }

    public function testCanAddJsonDataWithoutOverwritingContentType()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $request = new Request('PUT', 'http://foo.com');
        $client->send($request, [
            'headers' => ['content-type' => 'foo'],
            'json'    => 'a'
        ]);
        $last = $mock->getLastRequest();
        $this->assertEquals('"a"', (string) $mock->getLastRequest()->getBody());
        $this->assertEquals('foo', $last->getHeaderLine('Content-Type'));
    }

    public function testAuthCanBeTrue()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => false]);
        $last = $mock->getLastRequest();
        $this->assertFalse($last->hasHeader('Authorization'));
    }

    public function testAuthCanBeArrayForBasicAuth()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b']]);
        $last = $mock->getLastRequest();
        $this->assertEquals('Basic YTpi', $last->getHeaderLine('Authorization'));
    }

    public function testAuthCanBeArrayForDigestAuth()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => ['a', 'b', 'digest']]);
        $last = $mock->getLastOptions();
        $this->assertEquals([
            CURLOPT_HTTPAUTH => 2,
            CURLOPT_USERPWD  => 'a:b'
        ], $last['curl']);
    }

    public function testAuthCanBeCustomType()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->get('http://foo.com', ['auth' => 'foo']);
        $last = $mock->getLastOptions();
        $this->assertEquals('foo', $last['auth']);
    }

    public function testCanAddFormParams()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'form_params' => [
                'foo' => 'bar bam',
                'baz' => ['boo' => 'qux']
            ]
        ]);
        $last = $mock->getLastRequest();
        $this->assertEquals(
            'application/x-www-form-urlencoded',
            $last->getHeaderLine('Content-Type')
        );
        $this->assertEquals(
            'foo=bar+bam&baz%5Bboo%5D=qux',
            (string) $last->getBody()
        );
    }

    public function testCanSendMultipart()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->post('http://foo.com', [
            'multipart' => [
                [
                    'name'     => 'foo',
                    'contents' => 'bar'
                ],
                [
                    'name'     => 'test',
                    'contents' => fopen(__FILE__, 'r')
                ]
            ]
        ]);

        $last = $mock->getLastRequest();
        $this->assertContains(
            'multipart/form-data; boundary=',
            $last->getHeaderLine('Content-Type')
        );

        $this->assertContains(
            'Content-Disposition: form-data; name="foo"',
            (string) $last->getBody()
        );

        $this->assertContains('bar', (string) $last->getBody());
        $this->assertContains(
            'Content-Disposition: form-data; name="foo"' . "\r\n",
            (string) $last->getBody()
        );
        $this->assertContains(
            'Content-Disposition: form-data; name="test"; filename="ClientTest.php"',
            (string) $last->getBody()
        );
    }

    public function testUsesProxyEnvironmentVariables()
    {
        $http = getenv('HTTP_PROXY');
        $https = getenv('HTTPS_PROXY');
        $client = new Client();
        $this->assertNull($client->getConfig('proxy'));
        putenv('HTTP_PROXY=127.0.0.1');
        $client = new Client();
        $this->assertEquals(
            ['http' => '127.0.0.1'],
            $client->getConfig('proxy')
        );
        putenv('HTTPS_PROXY=127.0.0.2');
        $client = new Client();
        $this->assertEquals(
            ['http' => '127.0.0.1', 'https' => '127.0.0.2'],
            $client->getConfig('proxy')
        );
        putenv("HTTP_PROXY=$http");
        putenv("HTTPS_PROXY=$https");
    }

    public function testRequestSendsWithSync()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->request('GET', 'http://foo.com');
        $this->assertTrue($mock->getLastOptions()['synchronous']);
    }

    public function testSendSendsWithSync()
    {
        $mock = new MockHandler([new Response()]);
        $client = new Client(['handler' => $mock]);
        $client->send(new Request('GET', 'http://foo.com'));
        $this->assertTrue($mock->getLastOptions()['synchronous']);
    }

    public function testCanSetCustomHandler()
    {
        $mock = new MockHandler([new Response(500)]);
        $client = new Client(['handler' => $mock]);
        $mock2 = new MockHandler([new Response(200)]);
        $this->assertEquals(
            200,
            $client->send(new Request('GET', 'http://foo.com'), [
                'handler' => $mock2
            ])->getStatusCode()
        );
    }
}
