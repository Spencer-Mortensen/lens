<?php

namespace Lens;

use _Lens\Lens\Tests\Agent;

function fopen($filename, $mode, $use_include_path = null, $context = null)
{
	return eval(Agent::call(null, __FUNCTION__, func_get_args()));
}
