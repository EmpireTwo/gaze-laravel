<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class NerManifest
{
    private const URL_BASE = 'https://huggingface.co/onnx-community/bert-base-multilingual-cased-ner-hrl-ONNX/resolve/cfe67b1c1c4c91c1b26ac192955fc0971e62d8c8';

    /**
     * @var array<string, array{sourceName: string, size: int, source?: string}>
     */
    private const FILES = [
        'model.onnx' => ['sourceName' => 'onnx/model_int8.onnx', 'size' => 178451275],
        'tokenizer.json' => ['sourceName' => 'tokenizer.json', 'size' => 2919362],
        'tokenizer_config.json' => ['sourceName' => 'tokenizer_config.json', 'size' => 1273],
        'config.json' => ['sourceName' => 'config.json', 'size' => 1275],
        'special_tokens_map.json' => ['sourceName' => 'special_tokens_map.json', 'size' => 125],
        'vocab.txt' => ['sourceName' => 'vocab.txt', 'size' => 995526],
        'labels.json' => ['sourceName' => 'labels.davlan-mbert.json', 'size' => 196, 'source' => 'package'],
    ];

    /**
     * @param  array<string, string>  $checksums
     */
    private function __construct(
        private readonly array $checksums,
        private readonly string $body,
    ) {}

    public static function fromUrl(string $url, HttpClientInterface $client): self
    {
        if (! str_starts_with($url, 'https://')) {
            throw new NerTransportException("refusing non-HTTPS NER manifest URL: {$url}");
        }

        try {
            $response = $client->request('GET', $url);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new NerTransportException("NER manifest fetch failed with HTTP {$status}: {$url}");
            }
            $body = $response->getContent();
        } catch (TransportExceptionInterface $e) {
            throw new NerTransportException("NER manifest fetch transport failed: {$url}: ".$e->getMessage(), previous: $e);
        }

        return self::fromString($body);
    }

    public static function fromString(string $body): self
    {
        $checksums = [];
        $lineNumber = 0;

        foreach (explode("\n", $body) as $line) {
            $lineNumber++;
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^([a-f0-9]{64})\s+(.+)$/', $line, $matches) !== 1) {
                throw new NerManifestInvalidException("invalid SHA256SUMS line {$lineNumber}");
            }

            $name = $matches[2];
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                throw new NerManifestInvalidException("unsafe NER manifest path: {$name}");
            }

            if (array_key_exists($name, $checksums)) {
                throw new NerManifestInvalidException("duplicate NER manifest entry: {$name}");
            }

            $checksums[$name] = $matches[1];
        }

        foreach (array_keys(self::FILES) as $required) {
            if (! array_key_exists($required, $checksums)) {
                throw new NerManifestInvalidException("missing NER manifest entry: {$required}");
            }
        }

        return new self($checksums, $body);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function resolve(string $variant): NerArtifactSet
    {
        if ($variant !== 'int8') {
            throw new NerVariantUnknownException($variant, ['int8']);
        }

        $files = [];
        foreach (self::FILES as $destName => $metadata) {
            $entry = [
                'sha' => $this->checksums[$destName],
                'size' => $metadata['size'],
                'sourceName' => $metadata['sourceName'],
            ];

            if (($metadata['source'] ?? null) === 'package') {
                $entry['source'] = 'package';
            }

            $files[$destName] = $entry;
        }

        return new NerArtifactSet(self::URL_BASE, $files);
    }
}
