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

namespace Lens_0_0_56\Lens\Jobs;

use Lens_0_0_56\Lens\Evaluator\Autoloader;
use Lens_0_0_56\Lens\Evaluator\StatementsExtractor;
use Lens_0_0_56\Lens\Filesystem;

class CoverageJob implements Job
{
	/** @var string */
	private $executable;

	/** @var string */
	private $lensCoreDirectory;

	/** @var string */
	private $cacheDirectory;

	/** @var string */
	private $filePath;

	/** @var array|null */
	private $lineNumbers;

	public function __construct($executable, $lensCoreDirectory, $cacheDirectory, $filePath, array &$lineNumbers = null)
	{
		$this->executable = $executable;
		$this->lensCoreDirectory = $lensCoreDirectory;
		$this->cacheDirectory = $cacheDirectory;
		$this->filePath = $filePath;

		$this->lineNumbers = &$lineNumbers;
	}

	public function getCommand()
	{
		$arguments = array($this->lensCoreDirectory, $this->cacheDirectory, $this->filePath);
		$serialized = serialize($arguments);
		$compressed = gzdeflate($serialized, -1);
		$encoded = base64_encode($compressed);

		return "{$this->executable} --internal-coverage={$encoded}";
	}

	public function start()
	{
		$mockClasses = array();

		$filesystem = new Filesystem();
		$autoloader = new Autoloader($this->lensCoreDirectory, $this->cacheDirectory, $mockClasses);
		$extractor = new StatementsExtractor($filesystem, $autoloader);

		return $extractor->getLineNumbers($this->filePath);
	}

	public function stop($message)
	{
		$this->lineNumbers = $message;
	}
}
