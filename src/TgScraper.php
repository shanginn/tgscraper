<?php

namespace TgScraper;

use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use TgScraper\Common\OpenApiGenerator;
use TgScraper\Common\SchemaExtractor;
use TgScraper\Common\StubCreator;
use TgScraper\Constants\Versions;

/**
 * Class TgScraper.
 */
class TgScraper
{
    /**
     * Path to templates directory.
     */
    public const TEMPLATES_DIRECTORY = __DIR__ . '/../templates';

    private string $version;

    private array $types;

    private array $methods;

    /**
     * TgScraper constructor.
     */
    public function __construct(private LoggerInterface $logger, array $schema)
    {
        if (!self::validateSchema($schema)) {
            throw new \InvalidArgumentException('Invalid schema provided');
        }

        $this->version = $schema['version'] ?? '1.0.0';
        $this->types = $schema['types'];
        $this->methods = $schema['methods'];
    }

    /**
     * @throws \Throwable
     */
    public static function fromUrl(LoggerInterface $logger, string $url): self
    {
        $extractor = SchemaExtractor::fromUrl($logger, $url);
        $schema = $extractor->extract();

        return new self($logger, $schema);
    }

    /**
     * @throws \Exception
     * @throws GuzzleException
     */
    public static function fromVersion(LoggerInterface $logger, string $version = Versions::LATEST): self
    {
        $extractor = SchemaExtractor::fromVersion($logger, $version);
        $schema = $extractor->extract();

        return new self($logger, $schema);
    }

    public static function validateSchema(array $schema): bool
    {
        return array_key_exists('version', $schema) and is_string($schema['version'])
            and array_key_exists('types', $schema) and is_array($schema['types'])
            and array_key_exists('methods', $schema) and is_array($schema['methods']);
    }

    public static function fromYaml(LoggerInterface $logger, string $yaml): self
    {
        $data = Yaml::parse($yaml);

        return new self($logger, schema: $data);
    }

    /**
     * @throws \JsonException
     */
    public static function fromJson(LoggerInterface $logger, string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self($logger, schema: $data);
    }

    /**
     * @throws \Exception
     */
    public function toStubs(string $directory = '', string $namespace = 'TelegramApi'): void
    {
        try {
            $directory = self::getTargetDirectory($directory);
        } catch (\Exception $e) {
            $this->logger->critical(
                'An exception occurred while trying to get the target directory: ' . $e->getMessage()
            );
            throw $e;
        }

        $typesDir = $directory . '/Types';
        if (!file_exists($typesDir)) {
            mkdir($typesDir, 0755);
        }

        try {
            $creator = new StubCreator($this->toArray(), $namespace);
        } catch (\InvalidArgumentException $e) {
            $this->logger->critical(
                'An exception occurred while trying to parse the schema: ' . $e->getMessage()
            );
            throw $e;
        }

        [
            'types' => $types,
            'files' => $files,
        ] = $creator->generateCode();

        foreach ($types as $className => $type) {
            $this->logger->info('Generating class for Type: ' . $className);
            $filename = sprintf('%s/Types/%s.php', $directory, $className);
            file_put_contents($filename, $type);
        }

        foreach ($files as $filePath => $file) {
            $this->logger->info('Generating file: ' . $filePath);
            $filename = sprintf('%s/%s.php', $directory, $filePath);

            file_put_contents($filename, $file);
        }
    }

    /**
     * @throws \Exception
     */
    public static function getTargetDirectory(string $path): string
    {
        $result = realpath($path);
        if (false === $result) {
            if (!mkdir($path, 0755, true)) {
                $path = getcwd() . '/gen';
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }
            }
        }

        $result = realpath($path);
        if (false === $result) {
            throw new \Exception('Could not create target directory');
        }

        return $result;
    }

    #[ArrayShape([
        'version' => 'string',
        'types' => 'array',
        'methods' => 'array',
    ])]
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'types' => $this->types,
            'methods' => $this->methods,
        ];
    }

    public function toOpenApi(): array
    {
        $openapiTemplate = file_get_contents(self::TEMPLATES_DIRECTORY . '/openapi.json');
        $openapiData = json_decode($openapiTemplate, true);
        $responsesTemplate = file_get_contents(self::TEMPLATES_DIRECTORY . '/responses.json');
        $responses = json_decode($responsesTemplate, true);
        $openapi = new OpenApiGenerator($responses, $openapiData, $this->types, $this->methods);
        $openapi->setVersion($this->version);

        return $openapi->getData();
    }

    /**
     * Thanks to davtur19 (https://github.com/davtur19/TuriBotGen/blob/master/postman.php).
     */
    #[ArrayShape(['info' => 'string[]', 'variable' => 'string[]', 'item' => 'array[]'])]
    public function toPostman(): array
    {
        $template = file_get_contents(self::TEMPLATES_DIRECTORY . '/postman.json');
        $result = json_decode($template, true);
        $result['info']['version'] = $this->version;
        foreach ($this->methods as $method) {
            $formData = [];
            if (!empty($method['fields'])) {
                foreach ($method['fields'] as $field) {
                    $data = [
                        'key' => $field['name'],
                        'disabled' => $field['optional'],
                        'description' => sprintf(
                            '%s. %s',
                            $field['optional'] ? 'Optional' : 'Required',
                            $field['description']
                        ),
                        'type' => 'text',
                    ];
                    $default = $field['default'] ?? null;
                    if (!empty($default)) {
                        $data['value'] = (string) $default;
                    }

                    $formData[] = $data;
                }
            }

            $result['item'][] = [
                'name' => $method['name'],
                'request' => [
                    'method' => 'POST',
                    'body' => [
                        'mode' => 'formdata',
                        'formdata' => $formData,
                    ],
                    'url' => [
                        'raw' => 'https://api.telegram.org/bot{{token}}/' . $method['name'],
                        'protocol' => 'https',
                        'host' => [
                            'api',
                            'telegram',
                            'org',
                        ],
                        'path' => [
                            'bot{{token}}',
                            $method['name'],
                        ],
                    ],
                    'description' => $method['description'],
                ],
            ];
        }

        return $result;
    }
}
