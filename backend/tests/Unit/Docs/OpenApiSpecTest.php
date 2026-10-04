<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Спецификация API разложена по файлам (docs/openapi.yaml + docs/openapi/**)
 * и не генерируется из кода, поэтому нужно гарантировать её соответствие
 * реальным маршрутам: забытый эндпоинт или описанный, но несуществующий путь
 * ломают контракт. Заодно проверяется, что все внешние $ref разрешаются.
 */
/**
 * @phpstan-type SpecShape array{
 *     openapi: string,
 *     info: array<string, mixed>,
 *     paths: array<string, array<string, array<string, mixed>>>,
 *     components: array{securitySchemes: array<string, array<string, mixed>>},
 * }
 */
final class OpenApiSpecTest extends TestCase
{
    private const SPEC_PATH = __DIR__ . '/../../../../docs/openapi.yaml';
    private const SPEC_SOURCE = 'docs/openapi.yaml';
    private const SPEC_DIR = __DIR__ . '/../../../../docs';
    private const ROUTING_PATH = __DIR__ . '/../../../src/Core/Bootstrap/Routing.php';
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * Спецификация со всеми разрешёнными $ref.
     *
     * @var SpecShape
     */
    private array $spec;

    protected function setUp(): void
    {
        $this->spec = self::bundleSpec();
    }

    public function testSpecFileExistsAndParses(): void
    {
        self::assertFileExists(self::SPEC_PATH, self::SPEC_SOURCE . ' должен лежать в репозитории');
        self::assertSame('3.0.3', $this->spec['openapi']);
        self::assertArrayHasKey('info', $this->spec);
        self::assertNotSame([], $this->spec['paths']);
    }

    public function testNoOrphanFragmentFiles(): void
    {
        $onDisk = self::allSpecFiles();
        $referenced = self::referencedFiles();

        self::assertNotSame([], $onDisk);
        self::assertSame(
            [],
            array_keys(array_diff_key($onDisk, $referenced)),
            'файлы спецификации, на которые никто не ссылается',
        );
    }

    public function testSpecDeclaresBearerSecurityScheme(): void
    {
        $scheme = $this->spec['components']['securitySchemes']['bearerAuth'];

        self::assertSame('http', $scheme['type']);
        self::assertSame('bearer', $scheme['scheme']);
        self::assertSame('JWT', $scheme['bearerFormat']);
    }

    public function testNoUnresolvedReferences(): void
    {
        $leftovers = self::collectRefs($this->spec);

        self::assertSame([], $leftovers, 'в собранной спецификации остались неразрешённые $ref');
    }

    #[DataProvider('applicationRouteProvider')]
    public function testEveryRouteIsDocumented(string $method, string $path): void
    {
        self::assertArrayHasKey($path, $this->spec['paths'], $path . ' отсутствует в openapi.yaml');
        self::assertArrayHasKey(
            $method,
            $this->spec['paths'][$path],
            sprintf('%s %s не описан в openapi.yaml', strtoupper($method), $path),
        );
    }

    public function testSpecHasNoGhostRoutes(): void
    {
        $registered = [];
        foreach (self::applicationRoutes() as [$method, $path]) {
            $registered[strtoupper($method) . ' ' . $path] = true;
        }

        $documented = [];
        foreach ($this->spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[strtoupper($method) . ' ' . $path] = true;
            }
        }

        self::assertSame(
            [],
            array_keys(array_diff_key($documented, $registered)),
            'в openapi.yaml есть пути, которых нет в Routing',
        );
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[DataProvider('documentedOperationProvider')]
    public function testDocumentedOperationDescribesItself(
        string $path,
        string $method,
        array $operation,
    ): void {
        $context = strtoupper($method) . ' ' . $path;

        self::assertArrayHasKey('summary', $operation, $context . ' без summary');
        self::assertNotEmpty($operation['summary'], $context . ' с пустым summary');
        self::assertArrayHasKey('tags', $operation, $context . ' без tags');

        $responses = $operation['responses'] ?? null;
        self::assertIsArray($responses, $context . ' без описания ответов');
        self::assertNotSame([], $responses, $context . ' без описания ответов');
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[DataProvider('documentedOperationProvider')]
    public function testResponsesUseThreeDigitCodes(string $path, string $method, array $operation): void
    {
        $responses = $operation['responses'] ?? null;
        self::assertIsArray($responses);

        foreach (array_keys($responses) as $code) {
            self::assertMatchesRegularExpression(
                '/^[1-5]\d\d$/',
                (string) $code,
                sprintf('%s %s: код ответа %s', strtoupper($method), $path, (string) $code),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function applicationRouteProvider(): iterable
    {
        foreach (self::applicationRoutes() as [$method, $path]) {
            yield strtoupper($method) . ' ' . $path => [$method, $path];
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function documentedOperationProvider(): iterable
    {
        foreach (self::bundleSpec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                yield $path . ' ' . $method => [(string) $path, (string) $method, $operation];
            }
        }
    }

    /**
     * @return SpecShape
     */
    private static function bundleSpec(): array
    {
        /** @var SpecShape|null $bundle */
        static $bundle = null;

        if ($bundle === null) {
            /** @var SpecShape $bundle */
            $bundle = self::bundleFile(self::SPEC_PATH, []);
        }

        return $bundle;
    }

    /**
     * @param list<string> $stack
     *
     * @return array<string, mixed>
     */
    private static function bundleFile(string $path, array $stack): array
    {
        self::assertFileExists($path);

        $parsed = Yaml::parseFile($path);
        self::assertIsArray($parsed, $path . ' должен содержать YAML-карту');

        $bundled = self::inline($parsed, $path, $stack);
        self::assertIsArray($bundled, $path . ' должен содержать YAML-карту');

        /** @var SpecShape $bundled */
        return $bundled;
    }

    /**
     * @param list<string> $stack
     */
    private static function inline(mixed $node, string $currentFile, array $stack): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $ref = $node['$ref'] ?? null;

        if (is_string($ref)) {
            if (in_array($ref, $stack, true)) {
                return $node;
            }

            [$file, $fragment] = self::splitRef($ref, $currentFile);
            $resolved = self::resolveFragment(self::bundleFile($file, [...$stack, $ref]), $fragment);

            self::assertNotNull($resolved, sprintf('ссылка %s (из %s) ведёт в никуда', $ref, $currentFile));

            return $resolved;
        }

        $result = [];
        foreach ($node as $key => $value) {
            $result[$key] = self::inline($value, $currentFile, $stack);
        }

        return $result;
    }

    /**
     * @return array{string, string}
     */
    private static function splitRef(string $ref, string $currentFile): array
    {
        [$file, $fragment] = array_pad(explode('#', $ref, 2), 2, '');

        $target = $file === '' ? $currentFile : dirname($currentFile) . '/' . $file;

        return [self::normalizePath($target), $fragment === '' ? '' : substr($fragment, 1)];
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function resolveFragment(array $document, string $fragment): mixed
    {
        if ($fragment === '') {
            return $document;
        }

        $node = $document;

        foreach (explode('/', $fragment) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    private static function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * @return array<string, true>
     */
    private static function referencedFiles(): array
    {
        $files = [];

        $files = [self::normalizePath((string) realpath(self::SPEC_PATH)) => true] + self::allSpecFiles();

        foreach (array_keys($files) as $path) {
            $parsed = Yaml::parseFile($path);

            if (!is_array($parsed)) {
                continue;
            }

            $refs = self::collectRefs($parsed);

            foreach ($refs as $ref) {
                [$target, $fragment] = array_pad(explode('#', $ref, 2), 2, '');

                if ($target === '') {
                    continue;
                }

                $files[self::normalizePath(dirname($path) . '/' . $target)] = true;
            }
        }

        return $files;
    }

    /**
     * @return array<string, true>
     */
    private static function allSpecFiles(): array
    {
        $files = [];

        foreach (['paths', 'components', 'components/schemas'] as $dir) {
            foreach (glob(self::SPEC_DIR . '/openapi/' . $dir . '/*.yaml') ?: [] as $file) {
                $real = self::normalizePath((string) realpath($file));
                $files[$real] = true;
            }
        }

        return $files;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return list<string>
     */
    private static function collectRefs(array $node): array
    {
        $refs = [];

        array_walk_recursive($node, static function (mixed $value, mixed $key) use (&$refs): void {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            }
        });

        return $refs;
    }

    /**
     * @return list<array{string, string}>
     */
    private static function applicationRoutes(): array
    {
        $source = (string) file_get_contents(self::ROUTING_PATH);

        preg_match_all(
            '/\$app->(' . implode('|', self::HTTP_METHODS) . ")\(\s*'([^']+)'/",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $routes = [];
        foreach ($matches as [, $method, $path]) {
            $routes[] = [strtolower($method), $path];
        }

        return $routes;
    }
}
