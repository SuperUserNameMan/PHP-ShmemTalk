<?php

require_once("include/ShmemTalk.php");

$master = new ShmemTalk();

// -------------

file_put_contents( "worker_start.".time().".txt", print_r( $master, true ) );

$tend = microtime( true ) + 15.0 ;



while( $tend > microtime( true ) )
{
	//usleep( 1_000 );

//	file_put_contents( "worker_loop.".time().".txt", print_r( $master, true ) );


//	sleep( 1 );

	if ( $master->Synch() )
	{
//		file_put_contents( "worker_synch.".time().".txt", "".time() );

		$rep = $master->Get( 0 );

		if ( $rep !== null )
		{
			//file_put_contents( "worker_synch.".time().".txt", print_r( $rep , true ) );
			switch( $rep )
			{ 
				case "quit" : file_put_contents( "worker_quit.".time().".txt", "".time() ); exit( $rep );
				case "Ping" : $master->Set( 1 , "Pong" ); break;
				case "Pong" : $master->Set( 1 , "Ping" ); break;
				default : $master->Set( 1 , "What ?????" ); break;
			}
		}	
	}

//	file_put_contents( "worker_loop.".time().".txt", "".time() );

	
}

//EOF
