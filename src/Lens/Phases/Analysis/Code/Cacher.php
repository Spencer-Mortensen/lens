<?php

/**
 * Copyright (C) 2017 Spencer Mortensen
 *
 * This file is part of Lens.
 *
 * Lens is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Lens is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Lens. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Spencer Mortensen <spencer@lens.guide>
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL-3.0
 * @copyright 2017 Spencer Mortensen
 */

namespace _Lens\Lens\Phases\Analysis\Code;

use _Lens\Lens\JsonFile;
use _Lens\Lens\Phases\Analysis\Code\Generators\FileGenerator;
use _Lens\Lens\Phases\Analysis\Code\Parsers\FileParser;
use _Lens\Lens\Phases\Analysis\Watcher;
use _Lens\Lens\Php\Lexer;
use _Lens\SpencerMortensen\Filesystem\Directory;
use _Lens\SpencerMortensen\Filesystem\File;
use _Lens\SpencerMortensen\Filesystem\Path;

class Cacher
{
	/** @var Path */
	private $srcPath;

	/** @var Path */
	private $indexPath;

	/** @var Path */
	private $livePath;

	/** @var Path */
	private $mockPath;

	/** @var Lexer */
	private $lexer;

	/** @var Deflator */
	private $deflator;

	/** @var FileParser */
	private $fileParser;

	/** @var FileGenerator */
	private $fileGenerator;

	public function cache(Path $projectPath, Path $srcPath, Path $cachePath)
	{
		$codePath = $cachePath->add('code');
		$indexPath = $codePath->add('index');
		$livePath = $codePath->add('live');
		$mockPath = $codePath->add('mock');
		$watcherPath = $indexPath->add('modified.json');
		$liveRelativePath = $projectPath->getRelativePath($srcPath);

		$lexer = new Lexer();
		$deflator = new Deflator();
		$fileParser = new FileParser();
		$fileGenerator = new FileGenerator();

		$this->srcPath = $srcPath;
		$this->indexPath = $indexPath->add($liveRelativePath);
		$this->livePath = $livePath;
		$this->mockPath = $mockPath;
		$this->lexer = $lexer;
		$this->deflator = $deflator;
		$this->fileParser = $fileParser;
		$this->fileGenerator = $fileGenerator;

		$this->updateCache($watcherPath);
	}

	private function updateCache(Path $watcherPath)
	{
		$watcher = new Watcher();
		$srcDirectory = new Directory($this->srcPath);
		$watcherFile = new JsonFile($watcherPath);

		$changes = $watcher->watch($srcDirectory, $watcherFile);
		$this->updateDirectory([], $changes);
	}

	private function updateDirectory(array $trail, array $changes)
	{
		foreach ($changes as $name => $type) {
			if (is_array($type)) {
				$childTrail = $trail;
				$childTrail[] = $name;

				$this->updateDirectory($childTrail, $type);
			} else {
				$this->updateFile($trail, $type);
			}
		}
	}

	private function updateFile(array $trail, $type)
	{
		$indexFile = $this->getIndexFile($trail);

		switch ($type) {
			case Watcher::TYPE_REMOVED:
				$this->remove($indexFile);
				return;

			case Watcher::TYPE_MODIFIED:
				$srcFile = $this->getSrcFile($trail);
				$this->remove($indexFile);
				$this->add($srcFile, $indexFile);
				return;

			case Watcher::TYPE_ADDED;
				$srcFile = $this->getSrcFile($trail);
				$this->add($srcFile, $indexFile);
				return;
		}
	}

	private function remove(JsonFile $indexFile)
	{
		$contents = $indexFile->read();
		$indexFile->delete();

		if (!is_array($contents) || !is_array($contents['classes']) || !is_array($contents['functions'])) {
			return;
		}

		foreach ($contents['classes'] as $class) {
			$liveFile = $this->getClassFile($this->livePath, $class);
			$liveFile->delete();

			$mockFile = $this->getClassFile($this->mockPath, $class);
			$mockFile->delete();
		}

		foreach ($contents['functions'] as $function) {
			$liveFile = $this->getFunctionFile($this->livePath, $function);
			$liveFile->delete();

			$mockFile = $this->getFunctionFile($this->mockPath, $function);
			$mockFile->delete();
		}

		// TODO: clean up the "data" directory?
	}

	private function getSrcFile(array $trail)
	{
		$path = call_user_func_array([$this->srcPath, 'add'], $trail);
		return new File($path);
	}

	private function getIndexFile(array $trail)
	{
		$path = call_user_func_array([$this->indexPath, 'add'], $trail);
		return new JsonFile($path);
	}

	private function getClassFile(Path $basePath, $class)
	{
		$components = explode('\\', "{$class}.php");
		$path = call_user_func_array([$basePath, 'add'], $components);
		return new File($path);
	}

	private function getFunctionFile(Path $basePath, $function)
	{
		$components = explode('\\', "{$function}.function.php");
		$path = call_user_func_array([$basePath, 'add'], $components);
		return new File($path);
	}

	private function add(File $srcFile, JsonFile $indexFile)
	{
		$filePhp = $srcFile->read();

		if ($filePhp === null) {
			return;
		}

		$inflatedTokens = $this->lexer->lex($filePhp);
		$this->deflator->deflate($inflatedTokens, $deflatedTokens, $map);
		$input = new Input($deflatedTokens);

		if (!$this->fileParser->parse($input, $sections)) {
			return;
		}

		$filesPhp = $this->fileGenerator->generate($sections, $deflatedTokens, $inflatedTokens, $map);

		foreach ($filesPhp['live']['classes'] as $name => $filePhp) {
			$this->writeClass($this->livePath, $name, $filePhp);
		}

		foreach ($filesPhp['live']['functions'] as $name => $filePhp) {
			$this->writeFunction($this->livePath, $name, $filePhp);
		}

		foreach ($filesPhp['mock']['classes'] as $name => $filePhp) {
			$this->writeClass($this->mockPath, $name, $filePhp);
		}

		foreach ($filesPhp['mock']['functions'] as $name => $filePhp) {
			$this->writeFunction($this->mockPath, $name, $filePhp);
		}

		$indexData = [
			'classes' => array_keys($filesPhp['live']['classes']),
			'functions' => array_keys($filesPhp['live']['functions'])
		];

		$indexFile->write($indexData);
	}

	private function writeClass(Path $basePath, $name, $php)
	{
		$file = $this->getClassFile($basePath, $name);
		$file->write($php);
	}

	private function writeFunction(Path $basePath, $name, $php)
	{
		$file = $this->getFunctionFile($basePath, $name);
		$file->write($php);
	}
}
