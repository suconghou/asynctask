# async php task


run php async task


```
task::run($fun,$logfile,$timeout);
```

$fun will be called sync and should return a closure which will be called async

$logfile default value `/dev/null`  . your closure output and errors will be send to this file

$timeout default value `3600`

```

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

```

can work in php-fpm cli and php build-in cli server



see `/tmp/log` for task stdout


set php bin path
```
task::bin('/usr/local/bin/php')
```


use closure based on https://github.com/opis/closure  version 3.0.7
