<?php

declare(strict_types=1);

namespace Payxy\Tests\Unit\Support;

use Payxy\Exception\InvalidArgumentException;
use Payxy\Support\Amount;
use Payxy\Support\Reference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function testParsesExactly(int|string $input, string $decimal, int $minor): void
    {
        $amount = Amount::of($input);

        self::assertSame($decimal, $amount->toDecimalString());
        self::assertSame($minor, $amount->toMinorUnits());
    }

    /**
     * @return iterable<array{int|string, string, int}>
     */
    public static function validAmounts(): iterable
    {
        yield [1500, '1500.00', 150000];
        yield ['1500', '1500.00', 150000];
        yield ['1500.5', '1500.50', 150050];
        yield ['0.01', '0.01', 1];
        yield [' 19.99 ', '19.99', 1999];
        yield [0, '0.00', 0];
    }

    #[DataProvider('invalidAmounts')]
    public function testRejectsAmbiguousInput(int|string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::of($input);
    }

    /**
     * @return iterable<array{int|string}>
     */
    public static function invalidAmounts(): iterable
    {
        yield ['-1'];
        yield [-1];
        yield ['1.234'];
        yield ['1,000'];
        yield ['1e3'];
        yield ['01.00'];
        yield [''];
        yield [\PHP_INT_MAX];
    }

    public function testMinorUnitsRoundTrip(): void
    {
        self::assertTrue(Amount::fromMinorUnits(1999)->equals(Amount::of('19.99')));
        self::assertSame('75000.00', Amount::fromString('75000.00')->toDecimalString());
    }

    public function testLenientApiParsing(): void
    {
        self::assertSame('19.99', (string) Amount::tryFromApi(19.99));
        self::assertSame('20.00', (string) Amount::tryFromApi(20));
        self::assertNull(Amount::tryFromApi('garbage'));
        self::assertNull(Amount::tryFromApi(null));
        self::assertNull(Amount::tryFromApi(\NAN));
    }

    public function testReferencesAreUniqueAndPrefixed(): void
    {
        $a = Reference::generate('SHOP');
        $b = Reference::generate('SHOP');

        self::assertMatchesRegularExpression('/^SHOP-[0-9A-F]{16}$/', $a);
        self::assertNotSame($a, $b);
    }
}
