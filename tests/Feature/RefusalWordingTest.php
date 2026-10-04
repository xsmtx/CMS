<?php

declare(strict_types=1);

use App\Domain\Api\Exceptions\SessionRefused;
use Illuminate\Support\Facades\File;

/**
 * Every refusal somebody reads is worded in their own language.
 *
 * The rule has been in CLAUDE.md since `RackRefused` cost it: **the sentence
 * an operator reads is `key()`, and the exception's own message is English
 * and belongs in a log.** Five classes followed it and eight did not, which
 * meant a Turkish operator resolving an incident twice, approving their own
 * device change or draining a backend was answered in English — on a screen
 * that was otherwise entirely Turkish.
 *
 * This walks the classes rather than a list somebody maintains, and it
 * **calls every constructor** rather than reading the source: a reason added
 * next year is covered the day it is written, and a key with no wording
 * behind it fails here rather than printing itself at somebody.
 */
function refusalClasses(): array
{
    $found = [];

    foreach (File::allFiles(app_path('Domain')) as $file) {
        if (! str_ends_with($file->getFilename(), 'Refused.php')) {
            continue;
        }

        $class = 'App\\Domain\\'.str_replace(
            ['/', '.php'],
            ['\\', ''],
            str_replace('\\', '/', $file->getRelativePathname()),
        );

        // The base itself is not a refusal, and it is abstract: a walk that
        // treated it as one would be asking an abstract class for its
        // reasons.
        if (class_exists($class) && ! new ReflectionClass($class)->isAbstract()) {
            $found[] = $class;
        }
    }

    sort($found);

    return $found;
}

/**
 * The one class whose reasons are deliberately never shown to a caller.
 *
 * `SessionRefused` distinguishes an expired refresh token from a stolen one
 * for the audit row and for the operator, and every one of them reaches the
 * client as the same `unauthenticated` — a caller learning that a token
 * exists but has been spent knows more than a caller learning nothing.
 * Wording it would be wording something nobody reads.
 */
const UNWORDED = [
    SessionRefused::class,
];

it('finds the refusal classes at all', function (): void {
    // The guard on the guard: an audit that examined nothing would pass.
    expect(refusalClasses())->toHaveCount(14);
});

it('gives every refusal a translation key', function (): void {
    foreach (refusalClasses() as $class) {
        if (in_array($class, UNWORDED, strict: true)) {
            continue;
        }

        expect(method_exists($class, 'key'))->toBeTrue(
            class_basename($class).' has no key(), so whatever it says is English.',
        );

        expect(method_exists($class, 'worded'))->toBeTrue(
            class_basename($class).' has no worded(), so a caller has to build the sentence itself.',
        );
    }
});

it('words every reason every refusal can give, in both locales', function (): void {
    foreach (['en', 'tr'] as $locale) {
        app()->setLocale($locale);

        foreach (refusalClasses() as $class) {
            if (in_array($class, UNWORDED, strict: true)) {
                continue;
            }

            foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_STATIC) as $method) {
                if (! $method->isPublic() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $refusal = $method->invokeArgs(null, argumentsFor($method));

                expect($refusal->worded())->not->toBe(
                    $refusal->key(),
                    class_basename($class).'::'.$method->getName().' in '.$locale,
                );
            }
        }
    }

    app()->setLocale('en');
});

/**
 * Plausible arguments for a constructor we know nothing about.
 *
 * Only the shape matters: the assertion is that the key resolves to
 * *something other than itself*, so what is interpolated into it is
 * irrelevant. A parameter type this does not know about fails loudly rather
 * than being skipped, because a skipped constructor is an unworded reason
 * nobody is told about.
 */
function argumentsFor(ReflectionMethod $method): array
{
    $arguments = [];

    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

        $arguments[] = match ($name) {
            'string' => 'something',
            'int' => 1,
            'float' => 1.0,
            'bool' => true,
            'array' => [],
            default => enumOrFail($name),
        };
    }

    return $arguments;
}

function enumOrFail(string $type): mixed
{
    if (enum_exists($type)) {
        return $type::cases()[0];
    }

    throw new RuntimeException('RefusalWordingTest does not know how to build a '.$type.'.');
}
