<?php

class ShmemTalk
{
	const WORKER_TIME_LIMIT  = 30 ; // Set the number of seconds the worker is allowed to run without calling Synch().

	const NON_BLOCKING = true ;
	const BLOCKING = false ;

	const INDEX_MASTER_WROTE_SOMETHING = 0 ;
	const INDEX_WORKER_WROTE_SOMETHING = 1 ;
	const INDEX_SHARED_DATA            = 2 ;

	public bool $is_master = false;
	public bool $is_worker = false;
	
// -----------

	private int    $shmem_key   ; 
	private ?object $shmem       ;
	private int    $shmem_size  ;
	
	private $_tmp_keys = [];

	private $shared_size ; // size of the shmem_size minus OFFSET_SHARED

	public $master_pid = null; // PID
	
	private $worker_proc = false ; // file handler from popen()

	private int $sem_access_key = 0 ;
	private     $sem_access     = null;
	
	private int $sem_master_key = 0 ;
	private     $sem_master     = null;
	
	private int $sem_worker_key = 0 ;
	private     $sem_worker     = null;
	
	
	public array $data = [] ; 
	public bool  $data_needs_to_be_sent = false;



	
// ----- PUBLIC API ----------------------------------------

	/// Master constructor : ShmemeTalk( "./worker.php" );
	///
	/// Worker constructor : ShmemTalk();
	///
	/// Master will create a shmem using a unique key that will be passed to the worker as `$argv[2]`.
	/// `$argv[1]` will contain the PID of Master so the Worker can check if Master still working, and close itself if not.
	///
	/// Also, the Worker will shutdown itself if it spends too much time between two Synch(); 

	function __construct( string $_worker_path = null , int $_size = 8192 )
	{		
		if ( ! extension_loaded( 'sysvshm' ) ) 
		{ 
			trigger_error( __CLASS__."() : Extension `sysvshm` is required." , E_USER_ERROR ); 
		}
		
		if ( ! function_exists( 'sem_get' ) ) //!\ We don't use `extension_loaded('sysvsem')` because its API might be emulated on Windows 
		{
			trigger_error( __CLASS__."() : Extension `sysvsem` is required." , E_USER_ERROR );
		}
		
		if ( $_worker_path === null )
		{
			$this->is_worker = true ;		
			
			global $argv;
			
			$this->master_pid = intval( $argv[1] ) ;
			
			$this->shmem_key  = intval( $argv[2] ) ;

			$this->sem_access_key = intval( $argv[3] );
			$this->sem_master_key = intval( $argv[4] );
			$this->sem_worker_key = intval( $argv[5] );

			$this->shmem = shm_attach( $this->shmem_key ) ;		
		}
		else
		{
			$this->is_master = true ;
			
			if ( ! file_exists( $_worker_path ) )
			{
				trigger_error( __CLASS__."() can't find worker : `$_worker_path`." , E_USER_ERROR );
			}

			$this->master_pid = getmypid();

			$this->shmem_key = $this->gen_unikey();
			
			$this->sem_access_key = $this->gen_unikey();
			$this->sem_master_key = $this->gen_unikey();
			$this->sem_worker_key = $this->gen_unikey();
						
			$this->shmem = shm_attach( $this->shmem_key , $_size );
		}

		if ( $this->shmem === false )
		{
			trigger_error( __CLASS__."() : failed to shmem_attach('".$this->shmem_key."' , $_size )." , E_USER_ERROR );
		}

		shm_put_var( $this->shmem , self::INDEX_MASTER_WROTE_SOMETHING , false );
		shm_put_var( $this->shmem , self::INDEX_WORKER_WROTE_SOMETHING , false );
		shm_put_var( $this->shmem , self::INDEX_SHARED_DATA            , []    );
		
		$this->sem_access = sem_get( $this->sem_access_key );
		$this->sem_master = sem_get( $this->sem_master_key );
		$this->sem_worker = sem_get( $this->sem_worker_key );

		if ( $this->is_master )
		{
			/// We're going to launch a worker instance of PHP.
			
			$_launcher_path = PHP_BINARY ; // default
						
			if ( defined( 'PHP_LAUNCHER' ) )
			{
				// If the actual PHP binary was launched using a custom launcher (script or program), then
				// PHP_LAUNCHER must contain the filename of the executable utilized to launch the actual 
				// PHP binary.
				// It must be located into the same directory of PHP_BINARY or in its parent directory.
				
				$_launcher_dir  = dirname( PHP_BINARY );
				$_launcher_path = $_launcher_dir.'/'.PHP_LAUNCHER;
				
				if ( ! file_exists( $_launcher_path ) )
				{
					$_launcher_dir  = dirname( $_launcher_dir );
					$_launcher_path = $_launcher_dir.'/'.PHP_LAUNCHER;
					
					if ( ! file_exists( $_launcher_path ) )
					{
						trigger_error( __CLASS__."() : launcher not found." , E_USER_ERROR );
					}
				}
				
			}
			
			// Under Linux, ext `sysvsem` does not allow to release a semaphore that was
			// acquired by an other process, which makes things slightly more complicated.
			// Thus here is the algorithme :
			// 1) master acquire sem_master
			// 2) master launches worker
			// 3) master wait for worker to acquire sem_worker
			// 4) master release sem_master to acknowledge worker


			// 1) ↑↑↑

			sem_acquire( $this->sem_master , self::NON_BLOCKING ); 


			// 2) ↑↑↑
			
			$_launcher_args = [
				$_launcher_path       ,
				$_worker_path         , // 0
				getmypid()            , // 1
				$this->shmem_key      , // 2
				$this->sem_access_key , // 3
				$this->sem_master_key , // 4
				$this->sem_worker_key , // 5
			];
			
			$this->worker_proc = popen( implode( ' ' , $_launcher_args ) , 'r' );

			if ( $this->worker_proc === false )
			{
				trigger_error( __CLASS__."() : failed to popen('$_worker_path')." , E_USER_ERROR );
			}		

			// 3) wait for worker to acquire sem_worker :

			$timeout = microtime( true ) + 10.0 ; // TODO FIXME : when the OS is overloaded, it might not be ennough

			while( $timeout > microtime( true ) )
			{
				echo ".";
				if ( ! sem_acquire( $this->sem_worker , self::NON_BLOCKING ) )
				{
					echo "OK".PHP_EOL;
					$timeout = false;
					break;
				}
				sem_release( $this->sem_worker );
				usleep( 10_000 ); 
			}

			if ( $timeout !== false )
			{
				trigger_error( __CLASS__."() : worker timeout !".PHP_EOL." - '$_worker_path' did not reply in time !" , E_USER_ERROR );
			}
			
			// 4) tell the worker we've got its signal :

			sem_release( $this->sem_master );
		}
		else
		if ( $this->is_worker )
		{
			// Activate watch-dog timer that will be reset by Synch() :

			set_time_limit( self::WORKER_TIME_LIMIT );

			// Before launching us, the master has acquired its own semaphore :

			if ( sem_acquire( $this->sem_master , self::NON_BLOCKING ) )
			{
				sem_release( $this->sem_master );

				trigger_error( __CLASS__."() : master's semaphore should be locked." , E_USER_ERROR );
			}

			// Master is wating for our signal :

			sem_acquire( $this->sem_worker , self::BLOCKING );

			// Master should acknowledge by releasing its semaphore :

			$timeout = microtime( true ) + 10.0 ; // TODO FIXME : when the OS is overloaded, it might not be ennough

			while( $timeout > microtime( true ) )
			{
				echo ".";
				if ( sem_acquire( $this->sem_master , self::NON_BLOCKING ) )
				{
					sem_release( $this->sem_master );				
					echo "OK".PHP_EOL;
					$timeout = false;
					break;
				}
				usleep( 10_000 ); 
			}

			if ( $timeout !== false )
			{
				trigger_error( __CLASS__."() : master acknowledgement timeout !".PHP_EOL , E_USER_ERROR );
			}
		}
	}


	/// Check if the master process is alive :
	public function master_is_alive() : bool
	{	
		return $this->is_master or $this->this_process_is_alive( $this->master_pid );
	}


	/// This function has to be called in the main loops of each process.
	/// It synchronizes the data array using the shared memory.
	/// And it reset the WatchDog Timer of the worker.
	public function Synch() : bool
	{
		if ( $this->is_worker )
		{
			// Reset worker's watchdog timer :
			set_time_limit( self::WORKER_TIME_LIMIT ); 
			// TODO FIXME DETERMINE : is this really necessary ?
		}
		
		$we_received_the_update = false ;
		
		if ( sem_acquire( $this->sem_access , self::BLOCKING ) )
		{
			$INDEX_THE_OTHER_WROTE_SOMETHING = $this->is_master ? self::INDEX_WORKER_WROTE_SOMETHING : self::INDEX_MASTER_WROTE_SOMETHING ;

			if ( shm_get_var( $this->shmem , $INDEX_THE_OTHER_WROTE_SOMETHING ) )
			{
				//echo "shm_get_var".PHP_EOL;
				$_data = shm_get_var( $this->shmem , self::INDEX_SHARED_DATA );

				$this->data = array_replace( $this->data , $_data );
				
				$we_received_the_update = true ;
			}

			if ( $this->data_needs_to_be_sent )
			{
				//echo "shm_put_var".PHP_EOL; 
				shm_put_var( $this->shmem , self::INDEX_SHARED_DATA , $this->data );
				$this->data_needs_to_be_sent = false ;
				
				$INDEX_WE_WROTE_SOMETHING = $this->is_master ? self::INDEX_MASTER_WROTE_SOMETHING : self::INDEX_WORKER_WROTE_SOMETHING ;
				shm_put_var( $this->shmem , $INDEX_WE_WROTE_SOMETHING , true );
			}
			
			// Tell the other end we're finished reading their update  :
			if ( $we_received_the_update )
			{
				shm_put_var( $this->shmem , $INDEX_THE_OTHER_WROTE_SOMETHING , false );
			}

			sem_release( $this->sem_access );
		}
		
		return $we_received_the_update;
	}
	

	public function Set( $key , $val )
	{
		$this->data[ $key ] = $val ;
		$this->data_needs_to_be_sent = true ;
	}
	
	public function Get( $key ) : mixed
	{
		return $this->data[ $key ] ?? null ;
	}

	public function Close()
	{
		if ( $this->is_master )
		{
			if ( $this->worker_proc !== null ) { pclose( $this->worker_proc ); }
 
			if ( $this->shmem !== null ) 
			{ 
				shm_remove( $this->shmem );				
				shm_detach( $this->shmem ); 
								
				sem_remove( $this->sem_access );
				sem_remove( $this->sem_master );
				sem_remove( $this->sem_worker );
			}

			$this->worker_proc = null;
			$this->shmem = null; 
			
			$this->sem_access = null;
			$this->sem_master = null;
			$this->sem_worker = null;
			
		}
	}

// --------- PRIVATE API ----------------

	/// Create unique key :
	private function gen_unikey( string $project_id = '_' ) : int
	{
		$_instance_tmp = tempnam( sys_get_temp_dir() , 'php_'.$this->master_pid.'_' );
		
		$this->_tmp_keys[] = $_instance_tmp;

		return ftok( $_instance_tmp , $project_id );
	}

	private function this_process_is_alive( $pid ) : bool
	{
		if ( PHP_OS_FAMILY == 'Windows' )
		{
			exec( "tasklist /FI \"PID eq $pid\"" , $_res );
			return count( $_res ) > 1 ;
		}

		return file_exists( "/proc/$pid" );
	}

	function __destruct()
	{
		foreach( $this->_tmp_keys as $_ => $_key_path )
		{
			@unlink( $_key_path );
		}
		
		$this->Close();
	}

};
