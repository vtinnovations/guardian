<?php

declare(strict_types=1);

namespace Vtinnovations\Guardian\Tests\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\Guardian\Service\HostInventory;

/**
 * Host normalisation and inventory resolution.
 *
 * Normalisation is allowed to change how a host is written. It is never
 * allowed to change which host is meant — that difference is the whole of the
 * domain-binding security model.
 */
final class HostInventoryTest extends TestCase
{
    #[DataProvider('normalisation')]
    public function testNormalisation(string $input, ?string $expected): void
    {
        self::assertSame($expected, $this->inventory([])->normalise($input));
    }

    public static function normalisation(): array
    {
        return [
            'already canonical'    => ['example.com', 'example.com'],
            'uppercase'            => ['EXAMPLE.COM', 'example.com'],
            'mixed case'           => ['Example.Com', 'example.com'],
            'one trailing dot'     => ['example.com.', 'example.com'],
            'surrounding space'    => ['  example.com  ', 'example.com'],
            'explicit port'        => ['example.com:8080', 'example.com'],
            'full url'             => ['https://example.com/path', 'example.com'],
            'url with port'        => ['https://example.com:8443', 'example.com'],
            'www preserved'        => ['www.example.com', 'www.example.com'],
            'subdomain preserved'  => ['shop.example.com', 'shop.example.com'],
            'deep subdomain'       => ['admin.shop.example.com', 'admin.shop.example.com'],
            'wildcard rejected'    => ['*.example.com', null],
            'ipv4 rejected'        => ['192.0.2.1', null],
            'ipv6 rejected'        => ['[2001:db8::1]', null],
            'empty rejected'       => ['', null],
            'underscore rejected'  => ['ex_ample.com', null],
            'two dots rejected'    => ['example..com', null],
            'leading dot rejected' => ['.example.com', null],
        ];
    }

    public function testNormalisationNeverCollapsesDistinctHosts(): void
    {
        $inventory = $this->inventory([]);

        // These are four separate identities and must stay that way.
        self::assertNotSame($inventory->normalise('example.com'), $inventory->normalise('www.example.com'));
        self::assertNotSame($inventory->normalise('example.com'), $inventory->normalise('shop.example.com'));
        self::assertNotSame(
            $inventory->normalise('shop.example.com'),
            $inventory->normalise('admin.shop.example.com')
        );
    }

    public function testConfiguredHostsAreNormalisedUniqueAndSorted(): void
    {
        $inventory = $this->inventory(['WWW.Example.com', 'example.com.', 'example.com', 'not a host']);

        self::assertSame(['example.com', 'www.example.com'], $inventory->configured());
    }

    public function testEmptyInventoryWhenNothingIsConfigured(): void
    {
        self::assertSame([], $this->inventory([])->configured());
    }

    public function testUnreachableDatabaseYieldsAnEmptyInventoryRatherThanAGuess(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException(new \RuntimeException('no database'));

        $inventory = new HostInventory($connection, new RequestStack());

        self::assertSame([], $inventory->configured());
    }

    public function testTrustedRequestHostIsIgnoredWhenItIsNotConfigured(): void
    {
        // A forged Host header has no standing: it can select among configured
        // domains, never introduce one.
        $inventory = $this->inventory(['example.com'], 'attacker.test');

        self::assertNull($inventory->trustedRequestHost());
        self::assertSame('example.com', $inventory->verificationHost());
    }

    public function testTrustedRequestHostIsUsedWhenItIsConfigured(): void
    {
        $inventory = $this->inventory(['example.com', 'www.example.com'], 'www.example.com');

        self::assertSame('www.example.com', $inventory->trustedRequestHost());
        self::assertSame('www.example.com', $inventory->verificationHost());
    }

    public function testVerificationHostIsDeterministicWithoutARequest(): void
    {
        $inventory = $this->inventory(['zzz.example.com', 'aaa.example.com']);

        self::assertSame('aaa.example.com', $inventory->verificationHost());
    }

    public function testVerificationHostIsNullWithoutAnyConfiguredDomain(): void
    {
        self::assertNull($this->inventory([])->verificationHost());
    }

    public function testMembershipIsExact(): void
    {
        $inventory = $this->inventory(['example.com']);

        self::assertTrue($inventory->has('EXAMPLE.COM'));
        self::assertFalse($inventory->has('www.example.com'));
        self::assertFalse($inventory->has('shop.example.com'));
    }

    /** @param list<string> $rootPageDomains */
    private function inventory(array $rootPageDomains, ?string $requestHost = null): HostInventory
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn($rootPageDomains);

        $stack = new RequestStack();

        if (null !== $requestHost) {
            $stack->push(Request::create('https://' . $requestHost . '/contao'));
        }

        return new HostInventory($connection, $stack);
    }
}
