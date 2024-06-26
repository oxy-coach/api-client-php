<?php

declare(strict_types=1);

namespace RetailCrm\Api\Component\Serializer\Generator;

use Liip\MetadataParser\Builder;
use Liip\MetadataParser\Metadata\ClassMetadata;
use Liip\MetadataParser\Metadata\PropertyMetadata;
use Liip\MetadataParser\Metadata\PropertyType;
use Liip\MetadataParser\Metadata\PropertyTypeArray;
use Liip\MetadataParser\Metadata\PropertyTypeClass;
use Liip\MetadataParser\Metadata\PropertyTypeDateTime;
use Liip\MetadataParser\Metadata\PropertyTypePrimitive;
use Liip\MetadataParser\Metadata\PropertyTypeUnknown;
use Liip\MetadataParser\Reducer\GroupReducer;
use Liip\MetadataParser\Reducer\PreferredReducer;
use Liip\MetadataParser\Reducer\TakeBestReducer;
use Liip\MetadataParser\Reducer\VersionReducer;
use Liip\Serializer\Configuration\GeneratorConfiguration;
use Liip\Serializer\Template\Serialization;
use RetailCrm\Api\Component\Serializer\Template\CustomSerialization;
use RetailCrm\Api\Component\Serializer\Type\PropertyTypeMixed;
use RetailCrm\Api\Interfaces\Orders\CustomerInterface;
use RetailCrm\Api\Model\Entity\Customers\Customer;
use RetailCrm\Api\Model\Entity\Customers\CustomerTag;
use RetailCrm\Api\Model\Entity\CustomersCorporate\CustomerCorporate;
use RetailCrm\Api\Model\Entity\CustomersCorporate\SerializedRelationAbstractCustomer;
use RetailCrm\Api\Model\Entity\Orders\SerializedRelationCustomer;
use Symfony\Component\Filesystem\Filesystem;

final class SerializerGenerator
{
    private const FILENAME_PREFIX = 'serialize';

    /** @var \Symfony\Component\Filesystem\Filesystem */
    private $filesystem;

    /** @var \Liip\Serializer\Template\Serialization */
    private $templating;

    /** @var \Liip\Serializer\Configuration\GeneratorConfiguration */
    private $configuration;

    /** @var string */
    private $cacheDirectory;

    /** @var \RetailCrm\Api\Component\Serializer\Template\CustomSerialization */
    private $customTemplating;

    /** @var \Liip\MetadataParser\Builder */
    private $metadataBuilder;

    public function __construct(
        Serialization $templating,
        CustomSerialization $customTemplating,
        GeneratorConfiguration $configuration,
        string $cacheDirectory
    ) {
        $this->cacheDirectory = $cacheDirectory;
        $this->configuration = $configuration;
        $this->templating = $templating;
        $this->customTemplating = $customTemplating;
        $this->filesystem = new Filesystem();
    }

    /**
     * @param list<string> $serializerGroups
     */
    public static function buildSerializerFunctionName(string $className, ?string $apiVersion, array $serializerGroups): string
    {
        $functionName = self::FILENAME_PREFIX.'_'.$className;
        if (\count($serializerGroups)) {
            $functionName .= '_'.implode('_', $serializerGroups);
        }
        if (null !== $apiVersion) {
            $functionName .= '_'.$apiVersion;
        }

        return preg_replace('/[^a-zA-Z0-9_]/', '_', $functionName);
    }

    public function generate(Builder $metadataBuilder): void
    {
        $this->metadataBuilder = $metadataBuilder;
        $this->filesystem->mkdir($this->cacheDirectory);

        foreach ($this->configuration as $classToGenerate) {
            foreach ($classToGenerate as $groupCombination) {
                $className = $classToGenerate->getClassName();
                foreach ($groupCombination->getVersions() as $version) {
                    $groups = $groupCombination->getGroups();
                    if ('' === $version) {
                        if ([] === $groups) {
                            $metadata = $metadataBuilder->build($className, [
                                new PreferredReducer(),
                                new TakeBestReducer(),
                            ]);
                            $this->writeFile($className, null, [], $metadata);
                        } else {
                            $metadata = $metadataBuilder->build($className, [
                                new GroupReducer($groups),
                                new PreferredReducer(),
                                new TakeBestReducer(),
                            ]);
                            $this->writeFile($className, null, $groups, $metadata);
                        }
                    } else {
                        $metadata = $metadataBuilder->build($className, [
                            new VersionReducer($version),
                            new GroupReducer($groups),
                            new TakeBestReducer(),
                        ]);
                        $this->writeFile($className, $version, $groups, $metadata);
                    }
                }
            }
        }
    }

    /**
     * @param list<string> $serializerGroups
     */
    private function writeFile(
        string $className,
        ?string $apiVersion,
        array $serializerGroups,
        ClassMetadata $classMetadata
    ): void {
        $functionName = self::buildSerializerFunctionName($className, $apiVersion, $serializerGroups);

        $code = $this->templating->renderFunction(
            $functionName,
            $className,
            $this->generateCodeForClass($classMetadata, $apiVersion, $serializerGroups, '', '$model')
        );

        $this->filesystem->dumpFile(sprintf('%s/%s.php', $this->cacheDirectory, $functionName), $code);
    }

    /**
     * @param list<string>                $serializerGroups
     * @param array<string, positive-int> $stack
     */
    private function generateCodeForClass(
        ClassMetadata $classMetadata,
        ?string $apiVersion,
        array $serializerGroups,
        string $arrayPath,
        string $modelPath,
        array $stack = []
    ): string {
        if ($classMetadata->getClassName() === CustomerInterface::class) {
            return $this->generateForCustomerInterface(
                $classMetadata,
                $apiVersion,
                $serializerGroups,
                $arrayPath,
                $modelPath,
                $stack
            );
        }

        if ($classMetadata->getClassName() === CustomerTag::class) {
            return $this->generateForCustomerTag($arrayPath, $modelPath);
        }

        $stack[$classMetadata->getClassName()] = ($stack[$classMetadata->getClassName()] ?? 0) + 1;

        $code = '';
        foreach ($classMetadata->getProperties() as $propertyMetadata) {
            $code .= $this->generateCodeForField($propertyMetadata, $apiVersion, $serializerGroups, $arrayPath, $modelPath, $stack);
        }

        return $this->templating->renderClass($arrayPath, $code);
    }

    /**
     * @param \Liip\MetadataParser\Metadata\ClassMetadata $classMetadata
     * @param string|null                                 $apiVersion
     * @param array<mixed>                                $serializerGroups
     * @param string                                      $arrayPath
     * @param string                                      $modelPath
     * @param array<mixed>                                $stack
     *
     * @return string
     * @throws \Exception
     */
    private function generateForCustomerInterface(
        ClassMetadata $classMetadata,
        ?string $apiVersion,
        array $serializerGroups,
        string $arrayPath,
        string $modelPath,
        array $stack = []
    ): string {
        $customerMetadata = $this->metadataBuilder->build(Customer::class, [
            new PreferredReducer(),
            new TakeBestReducer(),
        ]);
        $corporateMetadata = $this->metadataBuilder->build(CustomerCorporate::class, [
            new PreferredReducer(),
            new TakeBestReducer(),
        ]);
        $serializedRelationAbstract = $this->metadataBuilder->build(
            SerializedRelationAbstractCustomer::class,
            [
                new PreferredReducer(),
                new TakeBestReducer(),
            ]
        );
        $serializedRelation = $this->metadataBuilder->build(
            SerializedRelationCustomer::class,
            [
                new PreferredReducer(),
                new TakeBestReducer(),
            ]
        );
        $customerCode = $this->generateCodeForClass(
            $customerMetadata,
            $apiVersion,
            $serializerGroups,
            $arrayPath,
            $modelPath
        );
        $corporateCode = $this->generateCodeForClass(
            $corporateMetadata,
            $apiVersion,
            $serializerGroups,
            $arrayPath,
            $modelPath
        );
        $serializedRelationAbstractCode = $this->generateCodeForClass(
            $serializedRelationAbstract,
            $apiVersion,
            $serializerGroups,
            $arrayPath,
            $modelPath
        );
        $serializedRelationCode = $this->generateCodeForClass(
            $serializedRelation,
            $apiVersion,
            $serializerGroups,
            $arrayPath,
            $modelPath
        );

        return $this->customTemplating->renderCustomerInterface(
            $arrayPath,
            $modelPath,
            $customerCode,
            $corporateCode,
            $serializedRelationAbstractCode,
            $serializedRelationCode
        );
    }

    /**
     * @param string $arrayPath
     * @param string $modelPath
     *
     * @return string
     */
    private function generateForCustomerTag(string $arrayPath, string $modelPath): string
    {
        return $this->templating->renderAssign($arrayPath, $modelPath . '->name');
    }

    /**
     * @param list<string>                $serializerGroups
     * @param array<string, positive-int> $stack
     */
    private function generateCodeForField(
        PropertyMetadata $propertyMetadata,
        ?string $apiVersion,
        array $serializerGroups,
        string $arrayPath,
        string $modelPath,
        array $stack
    ): string {
        if (Recursion::hasMaxDepthReached($propertyMetadata, $stack)) {
            return '';
        }

        $modelPropertyPath = $modelPath.'->'.$propertyMetadata->getName();
        $fieldPath = $arrayPath.'["'.$propertyMetadata->getSerializedName().'"]';

        if ($propertyMetadata->getAccessor()->hasGetterMethod()) {
            $tempVariable = str_replace(['->', '[', ']', '$'], '', $modelPath).ucfirst($propertyMetadata->getName());

            return $this->templating->renderConditional(
                $this->templating->renderTempVariable($tempVariable, $this->templating->renderGetter($modelPath, $propertyMetadata->getAccessor()->getGetterMethod())),
                $this->generateCodeForFieldType($propertyMetadata->getType(), $apiVersion, $serializerGroups, $fieldPath, '$'.$tempVariable, $stack)
            );
        }
        if (!$propertyMetadata->isPublic()) {
            throw new \Exception(sprintf('Property %s is not public and no getter has been defined. Stack %s', $modelPropertyPath, var_export($stack, true)));
        }

        return $this->templating->renderConditional(
            $modelPropertyPath,
            $this->generateCodeForFieldType($propertyMetadata->getType(), $apiVersion, $serializerGroups, $fieldPath, $modelPropertyPath, $stack)
        );
    }

    /**
     * @param list<string>                $serializerGroups
     * @param array<string, positive-int> $stack
     */
    private function generateCodeForFieldType(
        PropertyType $type,
        ?string $apiVersion,
        array $serializerGroups,
        string $fieldPath,
        string $modelPropertyPath,
        array $stack
    ): string {
        switch ($type) {
            case $type instanceof PropertyTypeDateTime:
                $dateFormat = $type->getFormat() ?: \DateTimeInterface::ISO8601;

                return $this->templating->renderAssign(
                    $fieldPath,
                    $this->templating->renderDateTime($modelPropertyPath, $dateFormat)
                );

            case $type instanceof PropertyTypePrimitive:
            case $type instanceof PropertyTypeUnknown:
            case $type instanceof PropertyTypeMixed:
                // for arrays of scalars, copy the field even when its an empty array
                return $this->templating->renderAssign($fieldPath, $modelPropertyPath);

            case $type instanceof PropertyTypeClass:
                return $this->generateCodeForClass($type->getClassMetadata(), $apiVersion, $serializerGroups, $fieldPath, $modelPropertyPath, $stack);

            case $type instanceof PropertyTypeArray:
                return $this->generateCodeForArray($type, $apiVersion, $serializerGroups, $fieldPath, $modelPropertyPath, $stack);

            default:
                throw new \Exception('Unexpected type '. get_class($type) .' at '.$modelPropertyPath);
        }
    }

    /**
     * @param list<string>                $serializerGroups
     * @param array<string, positive-int> $stack
     */
    private function generateCodeForArray(
        PropertyTypeArray $type,
        ?string $apiVersion,
        array $serializerGroups,
        string $arrayPath,
        string $modelPath,
        array $stack
    ): string {
        $index = '$index'.mb_strlen($arrayPath);
        $subType = $type->getSubType();

        switch ($subType) {
            case $subType instanceof PropertyTypePrimitive:
            case $subType instanceof PropertyTypeArray && self::isArrayForPrimitive($subType):
            case $subType instanceof PropertyTypeUnknown:
                return $this->templating->renderArrayAssign($arrayPath, $modelPath);

            case $subType instanceof PropertyTypeArray:
                $innerCode = $this->generateCodeForArray($subType, $apiVersion, $serializerGroups, $arrayPath.'['.$index.']', $modelPath.'['.$index.']', $stack);
                break;

            case $subType instanceof PropertyTypeClass:
                $innerCode = $this->generateCodeForClass($subType->getClassMetadata(), $apiVersion, $serializerGroups, $arrayPath.'['.$index.']', $modelPath.'['.$index.']', $stack);
                break;

            default:
                throw new \Exception('Unexpected array subtype '. get_class($subType));
        }

        if ('' === $innerCode) {
            if ($type->isHashmap()) {
                return $this->templating->renderLoopHashmapEmpty($arrayPath);
            }

            return $this->templating->renderLoopArrayEmpty($arrayPath);
        }

        if ($type->isHashmap()) {
            return $this->templating->renderLoopHashmap($arrayPath, $modelPath, $index, $innerCode);
        }

        return $this->templating->renderLoopArray($arrayPath, $modelPath, $index, $innerCode);
    }

    private static function isArrayForPrimitive(PropertyTypeArray $type): bool
    {
        do {
            $type = $type->getSubType();
            if ($type instanceof PropertyTypePrimitive) {
                return true;
            }
        } while ($type instanceof PropertyTypeArray);

        return false;
    }
}
