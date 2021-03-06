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

namespace _Lens\Lens\Reports;

use _Lens\Lens\LensException;
use _Lens\Lens\Reports\Coverage\CoverageReportBuilder;
use _Lens\SpencerMortensen\Filesystem\File;
use _Lens\SpencerMortensen\Filesystem\Filesystem;
use _Lens\SpencerMortensen\Filesystem\Path;

class ReportsBuilder
{
	/** @var Path */
	private $core;

	/** @var Path|null */
	private $project;

	/** @var Path|null */
	private $autoload;

	/** @var Path|null */
	private $cache;

	/** @var Filesystem */
	private $filesystem;

	public function __construct(Path $core, Path $project = null, Path $autoload = null, Path $cache = null, Filesystem $filesystem)
	{
		$this->core = $core;
		$this->project = $project;
		$this->autoload = $autoload;
		$this->cache = $cache;
		$this->filesystem = $filesystem;
	}


	public function run(array $options, array $results, array $executableStatements = null, $isUpdateAvailable)
	{
		$stdout = null;
		$stderr = null;

		// clover
		$this->getCoverageReport($options['coverage'], $results, $executableStatements);
		// crap4j
		$this->getIssuesReport($options['issues'], $results, $isUpdateAvailable, $stdout);
		$this->getTapReport($options['tap'], $results, $isUpdateAvailable, $stdout);
		$this->getXunitReport($options['xunit'], $results, $isUpdateAvailable, $stdout);

		if ($this->isSuccessful($results)) {
			$exitCode = 0;
		} else {
			$exitCode = LensException::CODE_FAILURES;
		}

		return [$stdout, $stderr, $exitCode];
	}

	private function getCoverageReport(&$destination, array $results, array $executableStatements = null)
	{
		if (($destination === null) || ($executableStatements === null)) {
			return;
		}

		$coverage = $this->project->add($destination);
		$builder = new CoverageReportBuilder($this->core, $this->cache, $coverage, $this->filesystem);
		$builder->build($executableStatements, $results);
	}

	private function getTapReport(&$destination, array $results, $isUpdateAvailable, &$stdout)
	{
		if ($destination === null) {
			return;
		}

		$caseText = new CaseText();
		$caseText->setAutoload($this->autoload);
		$report = new TapReport($caseText);
		$output = $report->getReport($results, $isUpdateAvailable);

		if ($destination === 'stdout') {
			$stdout = $output;
		} else {
			$path = $this->project->add($destination);
			$file = new File($path);
			$file->write($output);
		}
	}

	private function getIssuesReport(&$destination, array $results, $isUpdateAvailable, &$stdout)
	{
		if ($destination === null) {
			return;
		}

		$caseText = new CaseText();
		$caseText->setAutoload($this->autoload);

		$report = new IssuesReport($caseText);
		$output = $report->getReport($results, $isUpdateAvailable);

		if ($destination === 'stdout') {
			$stdout = $output;
		} else {
			$path = $this->project->add($destination);
			$file = new File($path);
			$file->write($output);
		}
	}

	private function getXunitReport(&$destination, array $results, $isUpdateAvailable, &$stdout)
	{
		if ($destination === null) {
			return;
		}

		$caseText = new CaseText();
		$caseText->setAutoload($this->autoload);
		$report = new XUnitReport($caseText);
		$output = $report->getReport($results, $isUpdateAvailable);

		if ($destination === 'stdout') {
			$stdout = $output;
		} else {
			$path = $this->project->add($destination);
			$file = new File($path);
			$file->write($output);
		}
	}

	// TODO: limit this to just the tests that were specified by the user
	private function isSuccessful(array $project)
	{
		foreach ($project['suites'] as $suite) {
			foreach ($suite['tests'] as $test) {
				foreach ($test['cases'] as $case) {
					if (!$this->isPassing($case['issues'])) {
						return false;
					}
				}
			}
		}

		return true;
	}

	// TODO: this is duplicated elsewhere
	private function isPassing(array $issues)
	{
		foreach ($issues as $issue) {
			if (is_array($issue)) {
				return false;
			}
		}

		return true;
	}
}
