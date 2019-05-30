<?php

namespace Phpro\SoapClient\CodeGenerator\Assembler;

use Phpro\SoapClient\Client;
use Phpro\SoapClient\CodeGenerator\Context\ClientMethodContext;
use Phpro\SoapClient\CodeGenerator\Context\ContextInterface;
use Phpro\SoapClient\CodeGenerator\Util\Normalizer;
use Phpro\SoapClient\CodeGenerator\ZendCodeFactory\DocBlockGeneratorFactory;
use Phpro\SoapClient\Exception\AssemblerException;
use Phpro\SoapClient\Exception\SoapException;
use Phpro\SoapClient\Type\MultiArgumentRequest;
use Phpro\SoapClient\Type\RequestInterface;
use Phpro\SoapClient\Type\ResultInterface;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;

class ClientMethodAssembler implements AssemblerInterface
{
    /**
     * {@inheritdoc}
     */
    public function canAssemble(ContextInterface $context): bool
    {
        return $context instanceof ClientMethodContext;
    }

    /**
     * @param ContextInterface|ClientMethodContext $context
     *
     * @return bool
     * @throws AssemblerException
     */
    public function assemble(ContextInterface $context): bool
    {
        $class = $context->getClass();
        $class->setExtendedClass(Client::class);
        $method = $context->getMethod();
        try {
            $phpMethodName = Normalizer::normalizeMethodName($method->getMethodName());
            $class->removeMethod($phpMethodName);

            $genArray = [
                'name' => $phpMethodName,
                'visibility' => MethodGenerator::VISIBILITY_PUBLIC,
                'body' => sprintf('return $this->call(\'%s\');', $method->getMethodName()),
                'returntype' => $method->getNamespacedReturnType(),
            ];

            if ($context->hasArguments()) {
                $param = $this->createParamsFromContext($context);

                $genArray['parameters'] = [ $param ];

                $genArray['body'] = sprintf(
                    'return $this->call(\'%s\', $%s);',
                    $method->getMethodName(),
                    $param->getName()
                );

                $genArray['docblock'] = $context->isMultiArgument() ?
                    $this->generateMultiArgumentDocblock($context) :
                    $this->generateSingleArgumentDocblock($context);
            } else {
                $genArray['docblock'] = $this->generateNoArgumentDocblock($context);
            }

            $class->addMethodFromGenerator(
                MethodGenerator::fromArray($genArray)
            );
        } catch (\Exception $e) {
            throw AssemblerException::fromException($e);
        }

        return true;
    }

    /**
     * @param ClientMethodContext $context
     *
     * @return ParameterGenerator
     */
    private function createParamsFromContext(ClientMethodContext $context): ParameterGenerator
    {
        if (!$context->isMultiArgument()) {
            $param = current($context->getMethod()->getParameters());

            return ParameterGenerator::fromArray($param->toArray());
        }

        return ParameterGenerator::fromArray(
            [
                'name' => 'multiArgumentRequest',
                'type' => MultiArgumentRequest::class,
            ]
        );
    }

    /**
     * @param ClientMethodContext $context
     *
     * @return DocBlockGenerator
     */
    private function generateMultiArgumentDocblock(ClientMethodContext $context): DocBlockGenerator
    {
        $class = $context->getClass();
        $method = $context->getMethod();
        $description = ['MultiArgumentRequest with following params:'.PHP_EOL];
        foreach ($context->getMethod()->getParameters() as $parameter) {
            $description[] = $parameter->getType().' $'.$parameter->getName();
        }

        return DocBlockGeneratorFactory::fromArray(
            [
                'longdescription' => implode(PHP_EOL, $description),
                'tags' => [
                    ['name' => 'param', 'description' => MultiArgumentRequest::class],
                    [
                        'name' => 'return',
                        'description' => sprintf(
                            '%s|%s',
                            $this->generateClassNameAndAddImport(ResultInterface::class, $class),
                            $this->generateClassNameAndAddImport(
                                $method->getNamespacedReturnType(),
                                $class,
                                true
                            )
                        ),
                    ],
                ],
            ]
        );
    }

    /**
     * @param ClientMethodContext $context
     *
     * @return DocBlockGenerator
     */
    private function generateSingleArgumentDocblock(ClientMethodContext $context): DocBlockGenerator
    {
        $method = $context->getMethod();
        $class = $context->getClass();
        $param = current($method->getParameters());

        return DocBlockGeneratorFactory::fromArray(
            [
                'tags' => [
                    [
                        'name' => 'param',
                        'description' => sprintf(
                            '%s|%s $%s',
                            $this->generateClassNameAndAddImport(RequestInterface::class, $class),
                            $this->generateClassNameAndAddImport($param->getType(), $class, true),
                            $param->getName()
                        ),
                    ],
                    [
                        'name' => 'return',
                        'description' => sprintf(
                            '%s|%s',
                            $this->generateClassNameAndAddImport(ResultInterface::class, $class),
                            $this->generateClassNameAndAddImport(
                                $method->getNamespacedReturnType(),
                                $class,
                                true
                            )
                        ),

                    ],
                    [
                        'name' => 'throws',
                        'description' => $this->generateClassNameAndAddImport(
                            SoapException::class,
                            $class
                        ),
                    ],
                ],
            ]
        )->setWordWrap(false);
    }

    /**
     * @param ClientMethodContext $context
     *
     * @return DocBlockGenerator
     */
    private function generateNoArgumentDocblock(ClientMethodContext $context): DocBlockGenerator
    {
        $method = $context->getMethod();
        $class = $context->getClass();

        return DocBlockGeneratorFactory::fromArray(
            [
                'tags' => [
                    [
                        'name' => 'return',
                        'description' => sprintf(
                            '%s|%s',
                            $this->generateClassNameAndAddImport(ResultInterface::class, $class),
                            $this->generateClassNameAndAddImport(
                                $method->getNamespacedReturnType(),
                                $class,
                                true
                            )
                        ),

                    ],
                    [
                        'name' => 'throws',
                        'description' => $this->generateClassNameAndAddImport(
                            SoapException::class,
                            $class
                        ),
                    ],
                ],
            ]
        )->setWordWrap(false);
    }

    /**
     * @param string $fqcn Fully qualified class name.
     * @param ClassGenerator $class Class generator object.
     * @param bool $prefixed
     *
     * @return string
     */
    protected function generateClassNameAndAddImport(string $fqcn, ClassGenerator $class, $prefixed = false): string
    {
        $prefix = '';
        $fqcn = ltrim($fqcn, '\\');
        $parts = explode('\\', $fqcn);
        $className = array_pop($parts);
        if ($prefixed) {
            $prefix = array_pop($parts);
        }
        $classNamespace = implode('\\', $parts);
        $currentNamespace = (string)$class->getNamespaceName();
        if ($prefixed) {
            $className = $prefix.'\\'.$className;
            $fqcn = $classNamespace.'\\'.$prefix;
        }
        if ($classNamespace !== $currentNamespace || !\in_array($fqcn, $class->getUses(), true)) {
            $class->addUse($fqcn);
        }

        return $className;
    }
}
