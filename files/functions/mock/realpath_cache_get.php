<?php

namespace Lens;

use Lens_0_0_57\Lens\Tests\Agent;

function realpath_cache_get()
{
	return eval(Agent::call(null, __FUNCTION__, func_get_args()));
}
