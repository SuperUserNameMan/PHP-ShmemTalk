<?php

echo "Worker PID : ".getmypid().PHP_EOL;


require_once("include/ShmemTalk.php");

$worker = new ShmemTalk( "worker.php" );

$worker->Set( 0 , "Ping" );

// -------------

$count = 0 ;

$tend = microtime( true ) + 10.0 ;

while( $tend > microtime( true ) )
{
	//usleep( 10_000 );

	if ( $worker->Synch() )
	{	
		//echo "synch".PHP_EOL;
		$rep = $worker->Get( 1 );

		if ( $rep !== null )
		{
			//echo "Worker replied $rep !".PHP_EOL;

			$worker->Set( 0 , $rep );

			$count++;
		}
	}
}

echo "IPC : ".($count/10.0)." Hz".PHP_EOL; // ~10 kHz max Linux

echo "Quit signal @".time().PHP_EOL;
$worker->Set( 0 , "quit" , true ); //usleep( 1000 );


echo "Closing worker ...";
$worker->Close();
echo "Ok".PHP_EOL;
