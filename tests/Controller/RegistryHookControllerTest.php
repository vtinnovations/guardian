<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\Guardian\Controller\RegistryHookController;
use Vtinnovations\Guardian\External\ServiceEndpoints;
use Vtinnovations\Guardian\Security\RequestAuthorizer;
use Vtinnovations\Guardian\Service\RegistrationCoordinator;

/**
 * Endpoint semantics for the vendor-initiated update path.
 *
 * Two things are being checked at once: that the documented status codes are
 * what the vendor's diagnostics will see, and that nothing reaches the
 * coordinator without passing authentication first.
 */
final class RegistryHookControllerTest extends TestCase
{
    public function testGetIsMethodNotAllowedRatherThanNotFound(): void
    {
        // 404 would tell the vendor the endpoint is missing; 405 tells them it
        // is deployed and expects POST.
        $response = $this->controller()(Request::create(ServiceEndpoints::UPDATER_PATH, 'GET'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->headers->get('Allow'));
    }

    public function testNonJsonMediaTypeIsRefused(): void
    {
        $request = Request::create(
            ServiceEndpoints::UPDATER_PATH,
            'POST',
            server: ['CONTENT_TYPE' => 'text/plain'],
            content: '{}'
        );

        self::assertSame(415, $this->controller()($request)->getStatusCode());
    }

    public function testOversizedDeclaredLengthIsRefusedBeforeReading(): void
    {
        $request = Request::create(
            ServiceEndpoints::UPDATER_PATH,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '1048576'],
            content: '{}'
        );

        self::assertSame(413, $this->controller()($request)->getStatusCode());
    }

    public function testOversizedActualBodyIsRefused(): void
    {
        $request = Request::create(
            ServiceEndpoints::UPDATER_PATH,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: str_repeat('a', 70000)
        );

        self::assertSame(413, $this->controller()($request)->getStatusCode());
    }

    public function testEmptyBodyIsRefused(): void
    {
        $request = Request::create(
            ServiceEndpoints::UPDATER_PATH,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: ''
        );

        self::assertSame(400, $this->controller()($request)->getStatusCode());
    }

    public function testUnsignedRequestIsRefusedGenericallyAndNeverReachesTheCoordinator(): void
    {
        $coordinator = $this->createMock(RegistrationCoordinator::class);
        $coordinator->expects(self::never())->method('applyPush');

        $request = Request::create(
            ServiceEndpoints::UPDATER_PATH,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"action":"license_update"}'
        );

        $response = $this->controller($coordinator)($request);

        self::assertSame(401, $response->getStatusCode());

        // The refusal must not describe which check failed.
        self::assertSame('{"status":"rejected"}', $response->getContent());
    }

    public function testAuthenticatedRequestIsHandedToTheCoordinator(): void
    {
        $authorizer = $this->createMock(RequestAuthorizer::class);
        $authorizer->method('authorize')->willReturn([
            'ok'        => true,
            'reason'    => RequestAuthorizer::OK,
            'requestId' => 'req-1',
            'nonce'     => 'nonce-1',
            'timestamp' => time(),
            'keyId'     => 'vtone-2026a',
        ]);

        $coordinator = $this->createMock(RegistrationCoordinator::class);
        $coordinator->expects(self::once())
            ->method('applyPush')
            ->willReturn([
                'status' => 200,
                'body'   => ['status' => 'updated', 'request_id' => 'req-1', 'license_version' => 9],
            ]);

        $request = Request::create(
            ServiceEndpoints::UPDATER_PATH,
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"action":"license_update"}'
        );

        $response = $this->controller($coordinator, $authorizer)($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"status":"updated"', (string) $response->getContent());
    }

    public function testUpdaterPathMatchesTheProtocol(): void
    {
        self::assertSame('/rest/api/v1/guardian-license-updater', ServiceEndpoints::UPDATER_PATH);
    }

    private function controller(
        ?RegistrationCoordinator $coordinator = null,
        ?RequestAuthorizer $authorizer = null,
    ): RegistryHookController {
        if (null === $authorizer) {
            $authorizer = $this->createMock(RequestAuthorizer::class);
            $authorizer->method('authorize')->willReturn([
                'ok'        => false,
                'reason'    => 'request_signature_rejected',
                'requestId' => '',
                'nonce'     => '',
                'timestamp' => 0,
                'keyId'     => '',
            ]);
        }

        return new RegistryHookController(
            $authorizer,
            $coordinator ?? $this->createMock(RegistrationCoordinator::class),
        );
    }
}
