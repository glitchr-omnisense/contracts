<?php

namespace Omnisense\Contracts\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The public surface of 1.x, frozen: every class, interface and enum the
 * families implement, extend or call (not those marked @internal), with
 * their public members and, where a family can extend them, their protected
 * ones, as Tests/api-1.x.txt lists them.
 *
 * A line gone or changed is a break of the BC promise (README, Stability):
 * it waits for 2.0. A line added is an addition: write the list again
 * (OMNISENSE_API_SNAPSHOT=write vendor/bin/phpunit --filter ApiSurface) and
 * say it in the CHANGELOG - unless it adds a method to an interface or an
 * abstract method, which breaks every implementation and waits for 2.0 too.
 */
final class ApiSurfaceTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/api-1.x.txt';

    private const NAMESPACES = ['Exception', 'Http', 'Policy', 'Provider', 'Testing'];

    public function testThePublicSurfaceIsTheOneFrozenFor1x(): void
    {
        $now = self::surface();
        if ('write' === getenv('OMNISENSE_API_SNAPSHOT')) {
            file_put_contents(self::SNAPSHOT, implode("\n", $now)."\n");
        }
        $frozen = file(self::SNAPSHOT, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];

        self::assertSame([], array_values(array_diff($frozen, $now)), 'Gone or changed since the list (a BC break, for 2.0 only); added lines show below.');
        self::assertSame([], array_values(array_diff($now, $frozen)), 'Added (write the list again, see the class comment), or the new form of a line above.');
    }

    public function testTheSurfaceSkipsWhatIsInternal(): void
    {
        $classes = array_map(static fn (string $line): string => explode(':', $line, 2)[0], self::surface());

        self::assertContains('Policy\PolicyGuard', $classes);
        self::assertNotContains('Policy\Read', $classes);
        self::assertNotContains('Http\SentOnce', $classes);
    }

    /** @return list<string> one line per class and member, sorted */
    private static function surface(): array
    {
        $root = \dirname(__DIR__);
        $lines = [];
        foreach (self::NAMESPACES as $namespace) {
            foreach (glob($root.'/'.$namespace.'/*.php') ?: [] as $file) {
                $class = 'Omnisense\\Contracts\\'.$namespace.'\\'.basename($file, '.php');
                if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                    self::fail($class.' does not load from '.$file);
                }
                $reflection = new \ReflectionClass($class);
                if (str_contains((string) $reflection->getDocComment(), '@internal')) {
                    continue;
                }
                array_push($lines, ...self::lines($reflection));
            }
        }
        sort($lines);

        return $lines;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private static function lines(\ReflectionClass $class): array
    {
        $name = self::short($class->getName());
        $kind = match (true) {
            $class->isInterface() => 'interface',
            $class->isEnum() => 'enum',
            $class->isTrait() => 'trait',
            default => trim(($class->isFinal() ? 'final ' : '').($class->isAbstract() ? 'abstract ' : '').($class->isReadOnly() ? 'readonly ' : '').'class'),
        };
        $parent = $class->getParentClass();
        $interfaces = $class->getInterfaceNames();
        sort($interfaces);
        $lines = [$name.': '.$kind
            .(false !== $parent ? ' extends '.self::short($parent->getName()) : '')
            .([] !== $interfaces ? ' implements '.implode(', ', array_map(self::short(...), $interfaces)) : '')];
        // What a subclass sees counts only where there can be one.
        $extendable = !$class->isFinal() && !$class->isEnum();

        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $class->getName() || $constant->isPrivate() || ($constant->isProtected() && !$extendable)) {
                continue;
            }
            $lines[] = $name.'::'.$constant->getName().($constant->isEnumCase() ? ' (case)' : ' = '.self::value($constant->getValue()));
        }
        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName() || $property->isPrivate() || ($property->isProtected() && !$extendable)) {
                continue;
            }
            $lines[] = $name.'::$'.$property->getName().': '.($property->isProtected() ? 'protected ' : '').($property->isStatic() ? 'static ' : '').($property->isReadOnly() ? 'readonly ' : '').self::type($property->getType());
        }
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName() || $method->isPrivate() || ($method->isProtected() && !$extendable)) {
                continue;
            }
            if ($class->isEnum() && \in_array($method->getName(), ['cases', 'from', 'tryFrom'], true)) {
                continue;
            }
            $parameters = array_map(static fn (\ReflectionParameter $parameter): string => self::parameter($parameter, $class->getName()), $method->getParameters());
            $lines[] = $name.'::'.$method->getName().'('.implode(', ', $parameters).')'
                .($method->hasReturnType() ? ': '.self::type($method->getReturnType(), $class->getName()) : '')
                .' ['.trim(($method->isProtected() ? 'protected' : 'public').($method->isStatic() ? ' static' : '').($method->isFinal() ? ' final' : '').($method->isAbstract() && !$class->isInterface() ? ' abstract' : '')).']';
        }

        return $lines;
    }

    private static function parameter(\ReflectionParameter $parameter, string $class): string
    {
        $text = ($parameter->hasType() ? self::type($parameter->getType(), $class).' ' : '')
            .($parameter->isPassedByReference() ? '&' : '').($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $text .= ' = '.($parameter->isDefaultValueConstant() ? self::short((string) $parameter->getDefaultValueConstantName()) : self::value($parameter->getDefaultValue()));
        }

        return $text;
    }

    /** The declaring class written self, as PHP 8.2 to 8.4 print it (8.5 prints the class's name). */
    private static function type(?\ReflectionType $type, string $class = ''): string
    {
        if (null === $type) {
            return 'mixed';
        }
        $text = (string) $type;
        if ('' !== $class) {
            $text = (string) preg_replace('/(?<![\\\\\w])'.preg_quote($class, '/').'(?![\\\\\w])/', 'self', $text);
        }

        return self::short($text);
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value instanceof \UnitEnum => self::short($value::class).'::'.$value->name,
            \is_object($value) => 'new '.self::short($value::class).'()',
            // A path built from __DIR__ depends on where the package is installed: only its place in the package is frozen.
            \is_string($value) && str_starts_with($value, \dirname(__DIR__).'/') => (string) json_encode('<package>'.substr($value, \strlen(\dirname(__DIR__))), \JSON_UNESCAPED_SLASHES),
            default => (string) json_encode($value, \JSON_UNESCAPED_SLASHES),
        };
    }

    private static function short(string $name): string
    {
        return str_replace('Omnisense\\Contracts\\', '', $name);
    }
}
