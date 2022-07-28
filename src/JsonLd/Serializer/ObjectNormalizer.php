<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\JsonLd\Serializer;

use ApiPlatform\Api\IriConverterInterface;
use ApiPlatform\Exception\InvalidArgumentException;
use ApiPlatform\JsonLd\AnonymousContextBuilderInterface;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Decorates the output with JSON-LD metadata when appropriate, but otherwise just
 * passes through to the decorated normalizer.
 */
final class ObjectNormalizer implements NormalizerInterface, CacheableSupportsMethodInterface
{
    use JsonLdContextTrait;

    public const FORMAT = 'jsonld';

    private $decorated;
    private $iriConverter;
    private $anonymousContextBuilder;

    public function __construct(NormalizerInterface $decorated, IriConverterInterface $iriConverter, AnonymousContextBuilderInterface $anonymousContextBuilder)
    {
        $this->decorated = $decorated;
        $this->iriConverter = $iriConverter;
        $this->anonymousContextBuilder = $anonymousContextBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization(mixed $data, string $format = null, array $context = []): bool
    {
        return self::FORMAT === $format && $this->decorated->supportsNormalization($data, $format, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function hasCacheableSupportsMethod(): bool
    {
        return $this->decorated instanceof CacheableSupportsMethodInterface && $this->decorated->hasCacheableSupportsMethod();
    }

    /**
     * {@inheritdoc}
     */
    public function normalize(mixed $object, string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        if (isset($context['api_resource'])) {
            $originalResource = $context['api_resource'];
            unset($context['api_resource']);
        }

        /*
         * Converts the normalized data array of a resource into an IRI, if the
         * normalized data array is empty.
         *
         * This is useful when traversing from a non-resource towards an attribute
         * which is a resource, as we do not have the benefit of {@see ApiProperty::isReadableLink}.
         *
         * It must not be propagated to resources, as {@see ApiProperty::isReadableLink}
         * should take effect.
         */
        $context['api_empty_resource_as_iri'] = true;

        $data = $this->decorated->normalize($object, $format, $context);
        if (!\is_array($data) || !$data) {
            return $data;
        }

        if (isset($originalResource)) {
            try {
                $context['output']['iri'] = $this->iriConverter->getIriFromResource($originalResource);
            } catch (InvalidArgumentException $e) {
                // The original resource has no identifiers
            }
            $context['api_resource'] = $originalResource;
        }

        $metadata = $this->createJsonLdContext($this->anonymousContextBuilder, $object, $context);

        return $metadata + $data;
    }
}
