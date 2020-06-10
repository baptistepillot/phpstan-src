<?php declare(strict_types = 1);

namespace PHPStan\Rules\Methods;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\TrinaryLogic;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPStan\Type\VoidType;

/**
 * @implements \PHPStan\Rules\Rule<InClassMethodNode>
 */
class MethodSignatureRule implements \PHPStan\Rules\Rule
{

	private bool $reportMaybes;

	private bool $reportStatic;

	public function __construct(
		bool $reportMaybes,
		bool $reportStatic
	)
	{
		$this->reportMaybes = $reportMaybes;
		$this->reportStatic = $reportStatic;
	}

	public function getNodeType(): string
	{
		return InClassMethodNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$method = $scope->getFunction();
		if (!$method instanceof MethodReflection) {
			return [];
		}

		$methodName = $method->getName();
		if ($methodName === '__construct') {
			return [];
		}
		if (!$this->reportStatic && $method->isStatic()) {
			return [];
		}
		if ($method->isPrivate()) {
			return [];
		}
		$parameters = ParametersAcceptorSelector::selectSingle($method->getVariants());

		$errors = [];
		foreach ($this->collectParentMethods($methodName, $method->getDeclaringClass(), $scope) as $parentMethod) {
			$parentParameters = ParametersAcceptorSelector::selectFromTypes(array_map(static function (ParameterReflection $parameter): Type {
				return $parameter->getType();
			}, $parameters->getParameters()), $parentMethod->getVariants(), false);

			$returnTypeCompatibility = $this->checkReturnTypeCompatibility($parameters->getReturnType(), $parentParameters->getReturnType());
			if ($returnTypeCompatibility->no() || (!$returnTypeCompatibility->yes() && $this->reportMaybes)) {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Return type (%s) of method %s::%s() should be %s with return type (%s) of method %s::%s()',
					$parameters->getReturnType()->describe(VerbosityLevel::value()),
					$method->getDeclaringClass()->getDisplayName(),
					$method->getName(),
					$returnTypeCompatibility->no() ? 'compatible' : 'covariant',
					$parentParameters->getReturnType()->describe(VerbosityLevel::value()),
					$parentMethod->getDeclaringClass()->getDisplayName(),
					$parentMethod->getName()
				))->build();
			}

			$parameterResults = $this->checkParameterTypeCompatibility($parameters->getParameters(), $parentParameters->getParameters());
			foreach ($parameterResults as $parameterIndex => $parameterResult) {
				if ($parameterResult->yes()) {
					continue;
				}
				if (!$parameterResult->no() && !$this->reportMaybes) {
					continue;
				}
				$parameter = $parameters->getParameters()[$parameterIndex];
				$parentParameter = $parentParameters->getParameters()[$parameterIndex];
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Parameter #%d $%s (%s) of method %s::%s() should be %s with parameter $%s (%s) of method %s::%s()',
					$parameterIndex + 1,
					$parameter->getName(),
					$parameter->getType()->describe(VerbosityLevel::value()),
					$method->getDeclaringClass()->getDisplayName(),
					$method->getName(),
					$parameterResult->no() ? 'compatible' : 'contravariant',
					$parentParameter->getName(),
					$parentParameter->getType()->describe(VerbosityLevel::value()),
					$parentMethod->getDeclaringClass()->getDisplayName(),
					$parentMethod->getName()
				))->build();
			}
		}

		return $errors;
	}

	/**
	 * @param string $methodName
	 * @param \PHPStan\Reflection\ClassReflection $class
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return \PHPStan\Reflection\MethodReflection[]
	 */
	private function collectParentMethods(string $methodName, ClassReflection $class, Scope $scope): array
	{
		$parentMethods = [];

		$parentClass = $class->getParentClass();
		if ($parentClass !== false && $parentClass->hasMethod($methodName)) {
			$parentMethod = $parentClass->getMethod($methodName, $scope);
			if (!$parentMethod->isPrivate()) {
				$parentMethods[] = $parentMethod;
			}
		}

		foreach ($class->getInterfaces() as $interface) {
			if (!$interface->hasMethod($methodName)) {
				continue;
			}

			$parentMethods[] = $interface->getMethod($methodName, $scope);
		}

		return $parentMethods;
	}

	private function checkReturnTypeCompatibility(
		Type $returnType,
		Type $parentReturnType
	): TrinaryLogic
	{
		// Allow adding `void` return type hints when the parent defines no return type
		if ($returnType instanceof VoidType && $parentReturnType instanceof MixedType) {
			return TrinaryLogic::createYes();
		}

		// We can return anything
		if ($parentReturnType instanceof VoidType) {
			return TrinaryLogic::createYes();
		}

		return $parentReturnType->isSuperTypeOf($returnType);
	}

	/**
	 * @param \PHPStan\Reflection\ParameterReflection[] $parameters
	 * @param \PHPStan\Reflection\ParameterReflection[] $parentParameters
	 * @return array<int, TrinaryLogic>
	 */
	private function checkParameterTypeCompatibility(
		array $parameters,
		array $parentParameters
	): array
	{
		$parameterResults = [];

		$numberOfParameters = min(count($parameters), count($parentParameters));
		for ($i = 0; $i < $numberOfParameters; $i++) {
			$parameter = $parameters[$i];
			$parentParameter = $parentParameters[$i];

			$parameterType = $parameter->getType();
			$parentParameterType = $parentParameter->getType();

			$parameterResults[] = $parameterType->isSuperTypeOf($parentParameterType);
		}

		return $parameterResults;
	}

}
