<?php

namespace Lens;

use _Lens\Lens\Tests\Agent;

function readdir($dir_handle = null)
{
	return eval(Agent::call(null, __FUNCTION__, func_get_args()));
}
