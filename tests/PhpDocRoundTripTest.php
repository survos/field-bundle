<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ConstructorExtractor;
use Symfony\Component\PropertyInfo\Extractor\PhpStanExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class PhpDocRoundTripTest extends TestCase
{
    public function testNamedViewsHydrateAsObjects(): void
    {
        $view = new \Survos\FieldBundle\Model\EntityViewDescriptor('table', 'Table', columns: ['title', ['name' => 'id']]);
        $metadata = new \Survos\FieldBundle\Model\EntityMetaDescriptor(self::class, 'Example', 'test', 1, views: ['table' => $view]);
        $serializer = $this->serializer();
        $copy = $serializer->deserialize($serializer->serialize($metadata, 'json'), $metadata::class, 'json');
        self::assertInstanceOf(\Survos\FieldBundle\Model\EntityViewDescriptor::class, $copy->views['table']);
        self::assertEquals($metadata->views, $copy->views);
    }

    public function testMapperConditionsKeepTheirKeys(): void
    {
        $mapper = new \Survos\FieldBundle\Attribute\Mapper(when: [3 => 'museum'], except: ['excluded' => 'archive']);
        $serializer = $this->serializer();
        self::assertEquals($mapper, $serializer->deserialize($serializer->serialize($mapper, 'json'), $mapper::class, 'json'));
    }

    private function serializer(): Serializer
    {
        // Required dev dependencies make these integration checks run in standalone bundle tests.
        $phpDoc = new PhpStanExtractor();
        $reflection = new ReflectionExtractor();
        $types = new PropertyInfoExtractor([], [new ConstructorExtractor([$phpDoc]), $phpDoc, $reflection]);

        return new Serializer([
            new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()), propertyTypeExtractor: $types),
            new ArrayDenormalizer(),
        ], [new JsonEncoder()]);
    }
}
