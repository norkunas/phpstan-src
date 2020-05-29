<?php declare(strict_types = 1);

namespace PHPStan\Reflection\BetterReflection\SourceLocator;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use Roave\BetterReflection\Identifier\Identifier;
use Roave\BetterReflection\Identifier\IdentifierType;
use Roave\BetterReflection\Reflection\Reflection;
use Roave\BetterReflection\Reflection\ReflectionConstant;
use Roave\BetterReflection\Reflector\Reflector;
use Roave\BetterReflection\SourceLocator\Ast\Exception\ParseToAstFailure;
use Roave\BetterReflection\SourceLocator\Ast\Strategy\NodeToReflection;
use Roave\BetterReflection\SourceLocator\Located\LocatedSource;
use Roave\BetterReflection\SourceLocator\Type\SourceLocator;
use function array_key_exists;
use function file_exists;
use function restore_error_handler;

/**
 * Use PHP's built in autoloader to locate a class, without actually loading.
 *
 * There are some prerequisites...
 *   - we expect the autoloader to load classes from a file (i.e. using require/include)
 *
 * Modified code from Roave/BetterReflection, Copyright (c) 2017 Roave, LLC.
 */
class AutoloadSourceLocator implements SourceLocator
{

	private FileNodesFetcher $fileNodesFetcher;

	/** @var array<string, FetchedNode<\PhpParser\Node\Stmt\ClassLike>> */
	private array $classNodes = [];

	/** @var array<string, FetchedNode<\PhpParser\Node\Stmt\Function_>> */
	private array $functionNodes = [];

	/** @var array<int, FetchedNode<\PhpParser\Node\Stmt\Const_|\PhpParser\Node\Expr\FuncCall>> */
	private array $constantNodes = [];

	/** @var array<string, \Roave\BetterReflection\SourceLocator\Located\LocatedSource> */
	private array $locatedSourcesByFile = [];

	public function __construct(FileNodesFetcher $fileNodesFetcher)
	{
		$this->fileNodesFetcher = $fileNodesFetcher;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ParseToAstFailure
	 */
	public function locateIdentifier(Reflector $reflector, Identifier $identifier): ?Reflection
	{
		if ($identifier->isFunction()) {
			$functionName = $identifier->getName();
			$loweredFunctionName = strtolower($functionName);
			if (array_key_exists($loweredFunctionName, $this->functionNodes)) {
				$nodeToReflection = new NodeToReflection();
				return $nodeToReflection->__invoke(
					$reflector,
					$this->functionNodes[$loweredFunctionName]->getNode(),
					$this->locatedSourcesByFile[$this->functionNodes[$loweredFunctionName]->getFileName()],
					$this->functionNodes[$loweredFunctionName]->getNamespace()
				);
			}
			if (!function_exists($functionName)) {
				return null;
			}

			$reflection = new ReflectionFunction($functionName);
			$reflectionFileName = $reflection->getFileName();

			if (!is_string($reflectionFileName)) {
				return null;
			}

			return $this->findReflection($reflector, $reflectionFileName, $identifier);
		}

		if ($identifier->isConstant()) {
			$constantName = $identifier->getName();
			$nodeToReflection = new NodeToReflection();
			foreach ($this->constantNodes as $stmtConst) {
				if ($stmtConst->getNode() instanceof FuncCall) {
					$constantReflection = $nodeToReflection->__invoke(
						$reflector,
						$stmtConst->getNode(),
						$this->locatedSourcesByFile[$stmtConst->getFileName()],
						$stmtConst->getNamespace()
					);
					if ($constantReflection === null) {
						continue;
					}
					if (!$constantReflection instanceof ReflectionConstant) {
						throw new \PHPStan\ShouldNotHappenException();
					}
					if ($constantReflection->getName() !== $identifier->getName()) {
						continue;
					}

					return $constantReflection;
				}

				foreach (array_keys($stmtConst->getNode()->consts) as $i) {
					$constantReflection = $nodeToReflection->__invoke(
						$reflector,
						$stmtConst->getNode(),
						$this->locatedSourcesByFile[$stmtConst->getFileName()],
						$stmtConst->getNamespace(),
						$i
					);
					if ($constantReflection === null) {
						continue;
					}
					if (!$constantReflection instanceof ReflectionConstant) {
						throw new \PHPStan\ShouldNotHappenException();
					}
					if ($constantReflection->getName() !== $identifier->getName()) {
						continue;
					}

					return $constantReflection;
				}
			}

			if (!defined($constantName)) {
				return null;
			}

			$reflection = ReflectionConstant::createFromNode(
				$reflector,
				new FuncCall(new Name('define'), [
					new Arg(new String_($constantName)),
					new Arg(new String_('')), // not actually used
				]),
				new LocatedSource('', null),
				null,
				null
			);
			$reflection->populateValue(constant($constantName));

			return $reflection;
		}

		if (!$identifier->isClass()) {
			return null;
		}

		$loweredClassName = strtolower($identifier->getName());
		if (array_key_exists($loweredClassName, $this->classNodes)) {
			$nodeToReflection = new NodeToReflection();
			return $nodeToReflection->__invoke(
				$reflector,
				$this->classNodes[$loweredClassName]->getNode(),
				$this->locatedSourcesByFile[$this->classNodes[$loweredClassName]->getFileName()],
				$this->classNodes[$loweredClassName]->getNamespace()
			);
		}

		$locateResult = $this->locateClassByName($identifier->getName());
		if ($locateResult === null) {
			return null;
		}
		[$potentiallyLocatedFile, $className] = $locateResult;

		return $this->findReflection($reflector, $potentiallyLocatedFile, new Identifier($className, $identifier->getType()));
	}

	private function findReflection(Reflector $reflector, string $file, Identifier $identifier): ?Reflection
	{
		if (!array_key_exists($file, $this->locatedSourcesByFile)) {
			$result = $this->fileNodesFetcher->fetchNodes($file);
			$this->locatedSourcesByFile[$file] = $result->getLocatedSource();
			foreach ($result->getClassNodes() as $className => $fetchedClassNode) {
				$this->classNodes[$className] = $fetchedClassNode;
			}
			foreach ($result->getFunctionNodes() as $functionName => $fetchedFunctionNode) {
				$this->functionNodes[$functionName] = $fetchedFunctionNode;
			}
			foreach ($result->getConstantNodes() as $fetchedConstantNode) {
				$this->constantNodes[] = $fetchedConstantNode;
			}
			$locatedSource = $result->getLocatedSource();
		} else {
			$locatedSource = $this->locatedSourcesByFile[$file];
		}

		$nodeToReflection = new NodeToReflection();
		if ($identifier->isClass()) {
			$identifierName = strtolower($identifier->getName());
			if (!array_key_exists($identifierName, $this->classNodes)) {
				return null;
			}

			return $nodeToReflection->__invoke(
				$reflector,
				$this->classNodes[$identifierName]->getNode(),
				$locatedSource,
				$this->classNodes[$identifierName]->getNamespace()
			);
		}
		if ($identifier->isFunction()) {
			$identifierName = strtolower($identifier->getName());
			if (!array_key_exists($identifierName, $this->functionNodes)) {
				return null;
			}

			return $nodeToReflection->__invoke(
				$reflector,
				$this->functionNodes[$identifierName]->getNode(),
				$locatedSource,
				$this->functionNodes[$identifierName]->getNamespace()
			);
		}

		return null;
	}

	public function locateIdentifiersByType(Reflector $reflector, IdentifierType $identifierType): array
	{
		return []; // todo
	}

	/**
	 * Attempt to locate a class by name.
	 *
	 * If class already exists, simply use internal reflection API to get the
	 * filename and store it.
	 *
	 * If class does not exist, we make an assumption that whatever autoloaders
	 * that are registered will be loading a file. We then override the file://
	 * protocol stream wrapper to "capture" the filename we expect the class to
	 * be in, and then restore it. Note that class_exists will cause an error
	 * that it cannot find the file, so we squelch the errors by overriding the
	 * error handler temporarily.
	 *
	 * @throws ReflectionException
	 * @return array{string, string}|null
	 */
	private function locateClassByName(string $className): ?array
	{
		if (class_exists($className, false) || interface_exists($className, false) || trait_exists($className, false)) {
			$reflection = new ReflectionClass($className);
			$filename = $reflection->getFileName();

			if (!is_string($filename)) {
				return null;
			}

			if (!file_exists($filename)) {
				return null;
			}

			return [$filename, $reflection->getName()];
		}

		$this->silenceErrors();

		try {
			/** @var array{string, string}|null */
			return FileReadTrapStreamWrapper::withStreamWrapperOverride(
				static function () use ($className): ?array {
					$functions = spl_autoload_functions();
					if ($functions === false) {
						return null;
					}

					foreach ($functions as $preExistingAutoloader) {
						$preExistingAutoloader($className);

						/**
						 * This static variable is populated by the side-effect of the stream wrapper
						 * trying to read the file path when `include()` is used by an autoloader.
						 *
						 * This will not be `null` when the autoloader tried to read a file.
						 */
						if (FileReadTrapStreamWrapper::$autoloadLocatedFile !== null) {
							return [FileReadTrapStreamWrapper::$autoloadLocatedFile, $className];
						}
					}

					return null;
				}
			);
		} finally {
			restore_error_handler();
		}
	}

	private function silenceErrors(): void
	{
		set_error_handler(static function (): bool {
			return true;
		});
	}

}
