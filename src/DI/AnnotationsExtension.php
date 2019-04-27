<?php

declare(strict_types = 1);

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Annotations\DI;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
use Kdyby\DoctrineCache\DI\Helpers;
use Nette\DI\Config\Helpers as ConfigHelpers;
use Nette\PhpGenerator\ClassType as ClassTypeGenerator;
use Nette\PhpGenerator\PhpLiteral;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Utils\Validators;

class AnnotationsExtension extends \Nette\DI\CompilerExtension
{

	/** @var bool */
	private $debugMode;

	public function __construct(bool $debugMode = false)
	{
		$this->debugMode = $debugMode;
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$reflectionReader = $builder->addDefinition($this->prefix('reflectionReader'))
			->setType(AnnotationReader::class)
			->setAutowired(false);

		Validators::assertField($config, 'ignore', 'array');
		foreach ($config['ignore'] as $annotationName) {
			$reflectionReader->addSetup('addGlobalIgnoredName', [$annotationName]);
			AnnotationReader::addGlobalIgnoredName($annotationName);
		}

		$builder->addDefinition($this->prefix('reader'))
			->setClass(Reader::class)
			->setFactory(CachedReader::class, [
				$this->prefix('@reflectionReader'),
				Helpers::processCache($this, $config['cache'], 'annotations', $config['debug']),
				$config['debug'],
			]);

		// for runtime
		AnnotationRegistry::registerUniqueLoader('class_exists');
	}

	public function getConfig(): array
	{
		$config = (array) parent::getConfig();

		// ignoredAnnotations
		$globalConfig = $this->compiler->getConfig();
		if (!empty($globalConfig['doctrine']['ignoredAnnotations'])) {
			trigger_error(sprintf("Section 'doctrine: ignoredAnnotations:' is deprecated, please use '%s: ignore:' ", $this->name), E_USER_DEPRECATED);
			/** @var array $config */
			$config = ConfigHelpers::merge($config, ['ignore' => $globalConfig['doctrine']['ignoredAnnotations']]);
		}

		return $config;
	}

	public function afterCompile(ClassTypeGenerator $class): void
	{
		$init = $class->getMethod('initialize');
		$originalInitialize = (string) $init->getBody();
		$init->setBody('?::registerUniqueLoader("class_exists");' . "\n", [new PhpLiteral(AnnotationRegistry::class)]);
		$init->addBody($originalInitialize);
	}

	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'ignore' => Expect::listOf('string')->default([
				'persistent',
				'serializationVersion',
			]),
			'cache' => Expect::string('default'),
			'debug' => Expect::bool($this->debugMode),
		]);
	}

}
