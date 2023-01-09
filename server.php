<?php
$null = NULL; //null var
//Create TCP/IP sream socket
// $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
// socket_bind($socket, 0, $port);
// socket_listen($socket);

// set some variables
$host = '127.0.0.1';
$port = 65522;
// don't timeout!
set_time_limit(0);
// create socket
$socket = socket_create(AF_INET, SOCK_STREAM, 0) or die("Could not create socket\n");
// bind socket to port
$result = socket_bind($socket, $host, $port) or die("Could not bind to socket\n");
// start listening for connections
$result = socket_listen($socket, 3) or die("Could not set up socket listener\n");


//create & add listning socket to the list
$clients = array($socket);
$side = 0 ;
//start endless loop, so that our script doesn't stop
while (true) {
	//manage multipal connections
	$changed = $clients;
	//returns the socket resources in $changed array
	socket_select($changed, $null, $null, 0, 10);
	
	//check for new socket
	if (in_array($socket, $changed)) {
		$socket_new = socket_accept($socket); //accept new socket
		$clients[] = $socket_new; //add socket to client array
		
		$header = socket_read($socket_new, 1024); //read data sent by the socket
		perform_handshaking($header, $socket_new, $host, $port); //perform websocket handshake
		
		socket_getpeername($socket_new, $ip); //get ip address of connected socket
		if($ip==null)
            $ip='error';

		$side++;
		if($side%2==0)
			$turn = 'left';
		else
            $turn = 'right';

		if($side==2)
			$ok=1;
		else $ok=0;


		$response = mask(json_encode(array('type'=>'system', 'name'=>$turn , 'end_of_game'=>$ok)));
		//prepare json data
		send_message($response); //notify all users about new connection
		
		//make room for new socket
		$found_socket = array_search($socket, $changed);
		unset($changed[$found_socket]);
	}
	
	//loop through all connected sockets
	foreach ($changed as $changed_socket) {	
		
		//check for any incomming data
		while(socket_recv($changed_socket, $buf, 1024, 0) >= 1)
		{
			$received_text = unmask($buf); //unmask data
			$tst_msg = json_decode($received_text); //json decode 
			$user_name = $tst_msg->name; //sender name
			$user_message = $tst_msg->message; //message text
			
			$response_text = mask(json_encode(array('type'=>'usermsg', 'name'=>$user_name, 'message'=>$user_message)));
			send_message($response_text); //send data

			break 2; //exist this loop
		}
		
		$buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
		if ($buf === false) { // check disconnected client
			// remove client for $clients array
			$found_socket = array_search($changed_socket, $clients);
			socket_getpeername($changed_socket, $ip);
			unset($clients[$found_socket]);

			if($ip==null)
                $ip='127.0.0.1';
			$side--;
			//notify all users about disconnected connection
			$response = mask(json_encode(array('type'=>'system', 'message'=>$ip.'went offline')));
			send_message($response);
		}
	}
}
// close the listening socket
socket_close($sock);
function send_message($msg)
{
	global $clients;
	foreach($clients as $changed_socket)
	{
		@socket_write($changed_socket,$msg,strlen($msg));
	}
	return true;
}
//Unmask incoming framed message
function unmask($text) {
	$length = ord($text[1]) & 127;
	if($length == 126) {
		$masks = substr($text, 4, 4);
		$data = substr($text, 8);
	}
	elseif($length == 127) {
		$masks = substr($text, 10, 4);
		$data = substr($text, 14);
	}
	else {
		$masks = substr($text, 2, 4);
		$data = substr($text, 6);
	}
	$text = "";
	for ($i = 0; $i < strlen($data); ++$i) {
		$text .= $data[$i] ^ $masks[$i%4];
	}
	return $text;
}
//Encode message for transfer to client.
function mask($text)
{
	$b1 = 0x80 | (0x1 & 0x0f);
	$length = strlen($text);
	
	if($length <= 125)
		$header = pack('CC', $b1, $length);
	elseif($length > 125 && $length < 65536)
		$header = pack('CCn', $b1, 126, $length);
	elseif($length >= 65536)
		$header = pack('CCNN', $b1, 127, $length);
	return $header.$text;
}
// При подключении посредством вебсокетов
// происходит обмен заголовками наподобие заголовков HTTP,
// так называемый handshake
function perform_handshaking($received_header, $client_conn, $host, $port)
{
	$headers = array();
    // Разбиваем строку по регулярному выражению
    // переход на новую строку в крайнее левое положение
    $lines = preg_split("/\r\n/", $received_header);
	foreach($lines as $line)
	{
        //удаляем пробелы и другие предопределенные символы
		$line = chop($line);
		if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
		{
			$headers[$matches[1]] = $matches[2];
		}
	}
    //займемся установкой обновленного соединения со специальным ключем
	$secKey = $headers['Sec-WebSocket-Key'];
    //Конкатенация ключа клиента и предустановленного GUID.
    // По документации GUID является следующей строкой: «258EAFA5-E914-47DA-95CA-C5AB0DC85B11»
    //вычисляем sha1 этой строки и кодирование хэша по base64
	$secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
	//hand shaking header
	$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
	"Upgrade: websocket\r\n" .
	"Connection: Upgrade\r\n" .
	"WebSocket-Origin: $host\r\n" .
	"WebSocket-Location: ws://$host:$port/pong/shout.php\r\n".
	"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
	socket_write($client_conn,$upgrade,strlen($upgrade));
}