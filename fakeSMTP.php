<?php

namespace techdada;

use Exception;

/**
 * Implements a basic SMTP Server with the ability to add a callback to react on certain
 * line contents within the SMTP data.
 * TODO: * add tls transport security
 * * add username/password check
 */
define ( 'DEBUG_OUT', true );
if (! defined ( 'MAX_CLIENTS' ))
	define ( 'MAX_CLIENTS', 10 );
if (! defined ( 'BIND_TO' ))
	define ( 'BIND_TO', '0.0.0.0' );

$incl_path = realpath ( dirname ( __FILE__ ) ) . DIRECTORY_SEPARATOR;
require_once $incl_path . 'fakeSMTPSession.php';
require_once $incl_path . 'fakeSMTPSessionList.php';
class fakeSMTP {
	protected $sock;
	protected $port;
	protected $address;
	protected $active = true;
	protected $authentication = false;
	public function __construct($port = 25, $userlist = null, $bind_to = BIND_TO) {
		$this->port = $port;
		$this->address = $bind_to;
		$this->userlist = $userlist;
		if ($this->userlist != null)
			$this->authentication = true;
		/* pcntl_signal ( SIGTERM, function ($signal) {
			echo 'HANDLE SIGNAL ' . $signal . PHP_EOL;
			$this->close ();
		} ); */
		/*pcntl_signal ( SIGINT, function ($signal)  {
			echo 'HANDLE SIGNAL ' . $signal . PHP_EOL;
			$r = $w = $e = array ();
			$this->active = false;
		} );  */
	}
	public function listen(callable $callback) {
		if (! $this->socket_start ( $this->address, $this->port ))
			die ( "Could not start socket at $bindto : $listen \n" );
			try {
				$this->loopwait ( $callback );
			} catch (Exception $e) {
				echo $e->getMessage();
				exit(1);
			}
	}
	public function enableAuth($userlist) {
		$this->userlist = $userlist;
		if ($this->userlist)
			$this->authentication = true;
		else
			$this->authentication = false;
	}
	protected function socket_start($addr, $port) {
		$this->sock = socket_create ( AF_INET, SOCK_STREAM, SOL_TCP );
		socket_bind ( $this->sock, $addr, $port ) or die ( 'Could not listen at ' . $addr . ':' . $port . "\n" );
		if (socket_listen ( $this->sock )) {
			echo "listening at $addr : $port \n";
			return true;
		}
		return false;
	}
	protected function loopwait(callable $callback) {
		$newsock = null;
		$sessions = new fakeSMTPSessionList ( $this->sock );
		socket_set_nonblock($this->sock);
		while ( $this->active ) {
			//list of all sockets:
			$read = $sessions->getSockList ();
			
			$w = array ();
			$e = array ();
			// check how many connections with data are active
			$ready = socket_select ( $read, $w, $e, 5, 5 );
			if (! $ready > 0)
				continue; // nothing of interest
			
			$date = date ( 'Y-m-d H:i:s' );
			
			if ($sessions->full ()) {
				echo "too many clients\n";
				continue;
			}
			if (in_array ( $this->sock, $read )) {
				if ($newsock = socket_accept ( $this->sock )) {
					// add the connection to the sessions list
					$sessions->add ( $newsock );
					
					socket_write ( $newsock, "220 mosquito SMTP\n" );
					if (DEBUG_OUT)
						"Connections: " . $sessions->size () . "\nReady: $ready \n";
					socket_getpeername ( $newsock, $ip, $port );
					echo "$date connection from $ip at $port \n";
				}
			} // end if in_array
			  
			// remove the listening socket from the clients-with-data array
			$key = array_search ( $this->sock, $read );
			unset ( $read [$key] );
			
			// check for ready connections
			foreach ( $read as $read_sock ) {
				
				// read until newline or 1024 bytes
				// socket_read will throw errors when clients get disconnected.
				// suppress the error messages.
				$data = @socket_read ( $read_sock, 1024, PHP_NORMAL_READ );
				
				// check if the client is disconnected
				if ($data === false) {
					$sessions->remove ( $read_sock );
					echo "client disconnected.\n";
					continue;
				}
				$data = trim ( $data );
				if (! $data)
					continue;
				$output = "";
				if ($s = $sessions->contains ( $read_sock )) {
					$s->new_line ( $data );
				}
				
				if ($s->expect ( 'user' )) {
					if (DEBUG_OUT) echo 'expected user';
					$s->parseUser ( $data );
					$s->expect ( 'user', false );
					$s->expect ( 'pass', true );
					$output = '334 UGFzc3dvcmQ6';
				} elseif ($s->expect ( 'pass' )) {
					if (DEBUG_OUT) echo 'expected pass';
					if ($s->parsePass ( $data, $this->userlist )) {
						$output = '235 ok';
					} else {
						$output = '535 Incorrect authentication data';
					}
					$s->expect ( 'pass', false );
				} else
					if (DEBUG_OUT) echo 'handle keywords';
					switch (strtoupper ( substr ( $data, 0, 4 ) )) {
						case 'EHLO' :
						case 'HELO' :
						case 'MAIL' :
						case 'RCPT' :
							$output = '250 OK';
							break;
						case 'EXIT' :
						case 'QUIT' :
							$output = '221 closing channel';
							break;
						case 'DATA' :
							$output = '354 start mail input';
							break;
						default :
							if ($data == ".")
								$output = '250 OK';
					}
				if ($s) {
					$callback ( $data, $output, $s );
				}
				
				if (DEBUG_OUT) {
					echo $data . "\n" . $output . "\n";
				}
				if ($output)
					socket_write ( $read_sock, $output . "\n" );
				if ((substr ( $output, 0, 3 ) == '221') || (substr ( $output, 0, 3 ) == '535')) {
					$sessions->remove ( $read_sock );
					socket_close ( $read_sock );
				}
			}
		}
		socket_shutdown($this->sock,2);
		socket_close($this->sock);
	}
	public function close() {
		echo "Closing socket";
		$this->active = false;
		// socket_close($this->sock);
		// socket_shutdown($this->sock);
	}
	public function __destruct() {
		$this->close ();
	}
}

