<?php

require 'dist.php';

$test=function()
{
	sleep(4);
	return time();
};

task::run(function()use($test)
{
	echo "sync here";
	return function()use($test)
	{
		echo "hello async ";
		sleep(1);
		echo "world";
		file_put_contents('/tmp/1',$test());
		sleep(2);
		echo "next 10";
		sleep(10);
	};
},'/tmp/log',4);
