<?php

// 
// -wwinception-
// version 0.1
// 
// interface to receive or get WoodWing Inception articles and store them in wordpress
//
// required wordpress plugin:
// - https://wordpress.org/plugins/iframe/ 
// 
// --------------------------------------------------

// ----------------------
// Configuration settings
// ----------------------


// tempdir required to download zipfile , folder must be RW to Apache/IIs 
// 
define( 'TEMPDIR'  , __DIR__ . '/temp/');

// if you want logging, specify path to writable log folder
define( 'LOGPATH'  , dirname(__FILE__) . '/wwlog/'); // including ending '/'
define( 'LOGPATHWITHIP', false);

// if you want to run from local server, specify the URL to the 
// AWSSNS subserver, leave empty to disable subserver functionality
define( 'AWSSNSURL' , 'http://ec2-52-15-147-67.us-east-2.compute.amazonaws.com/subserver/subserver.php' );
define( 'SUBKEY'	, 'wvr-localhost');

// --------------------------------------------------
// if you want to finetune the logging,
// you might consider to play with the settings below
// Normally no changes are required
// --------------------------------------------------

// specify the name of the logfile, normally this is the name of the script
define( 'LOGNAME', basename(__FILE__) );


define( 'LOG_ALL',true); // if true, everything will be logged, 
                        // if false, only IP's listed will be logged
                       


// define IP-addresses to log, only the specified IP-addresses will be logged
define( 'LOG_IP', serialize( array('localhost',
                                   )
                            ) );   


// see : http://php.net/manual/en/timezones.asia.php
ini_set( 'date.timezone', 'Europe/Amsterdam');



// ========================================
// take care the php problems are reported
// ========================================
error_reporting(E_ALL);
ini_set('display_errors','On');
ini_set ('error_log', LOGPATH . 'php.log');
set_error_handler( 'ErrorHandler' );

function ErrorHandler( $errno, $errmsg, $file, $line, $debug )
{
   MyLog("ERROR in PHP: Errno:$errno  errMsg[$errmsg] $file:$line");
}


// -----------------------------
// load the wordpress frame work
// -----------------------------
require_once(dirname(__FILE__) . '/wp-config.php');
$wp->init();
$wp->parse_request();
$wp->query_posts();
$wp->register_globals();
$wp->send_headers();
require_once( ABSPATH . 'wp-admin/includes/file.php' );
require_once( ABSPATH . 'wp-admin/includes/post.php');
// -----------------------------




// this script can run in two modes:
// mode-1)
// if no POST data, it wil (try to) connect to the server specified on AWSSNSURL
// and load the file data from there, this is usefull if this wordpress can not be 
// reached by the AWS-SNS service (local machine)
//
// mode-2)
// if postdata is received, it is expected to be in the AWS-SNS message format
// the sns message data will be used to update wordpress.


// main check to see if any postdata is available
// Input socket
$inputSocket = fopen('php://input','rb');
$rawrequest = stream_get_contents($inputSocket);
fclose($inputSocket);

MyLog('=================================');
MyLog('=================================');
// see if there are GET parameters
MyLog('GET parameters:' . print_r($_GET,1));
MyLog('=================================');

// chck if we run in 'test-config' mode
if (isset ($_GET['testconfig']))
{
	define( 'EOL' , "<br>");
	MyLog("test-mode");
	checkSetup();
	exit;
}






//print ('rawrequest [' . print_r($rawrequest,1) . ']');
if ( $rawrequest == '' )
{
	define( 'EOL' , "<br>");
	MyLog ( "No POST-request data found, running in sub-server mode");
	MyLog ( "SubServer [" . AWSSNSURL . "]");
	if ( AWSSNSURL == '' ){
		MyLog( "No subserver defined, nothing to do");
	}
	else
	{	
		// get the files , if passing a true, then ALL files will be loaded
		// otherwise only newer files
		getFiles(false);
	}	
} else {
	define( 'EOL' , "\n");
	MyLog( "POST-request received, attempt to parse.." );
	handleAWSSNSmessage($rawrequest);
}

print "OK, see logfile for more info<br>";


// --------------------------------------------
// -- functions below this line ---------------
// --------------------------------------------

function checkSetup()
{
	print "WW-inception connector" .EOL;
	print "----------------------" .EOL;
	print "Checking setup" . EOL;
	print " - Check temp folder...";
	
	if (!file_exists( TEMPDIR)) 
	{ print "failed: TempFolder [" . TEMPDIR . "] does not exists, please create" . EOL; exit;}
	
	if (! is_writable( TEMPDIR )) 
	{ print "failed: TempFolder [" . TEMPDIR . "] is not writable, please add correct access rights" . EOL; exit;}
	
	print "OK" . EOL . EOL;
	print " - Check Log folder...";
	if (!file_exists( LOGPATH)) 
	{ print "failed: Log Folder [" . LOGPATH . "] does not exists, please create" . EOL; exit;}
	
	if (! is_writable( LOGPATH )) 
	{ print "failed: Log Folder [" . LOGPATH . "] is not writable, please add correct access rights" . EOL; exit;}
	
	print "OK" . EOL . EOL;
	
	
	print "Check SubServer..." .EOL;;
	if ( AWSSNSURL == '' )
	{ print " - Setting for SUBSERVER (AWSSNSURL) is empty, this configuration can only receive push from Inception".EOL;;}
	else
	{ print " - Setting for SUBSERVER (AWSSNSURL) found, run this script to collect articles from [" . AWSSNSURL . "]".EOL;;}
	
}




function handleAWSSNSmessage($rawrequest)
{
	$request = json_decode( $rawrequest, true );
	MyLog ('request [' . print_r($request,1) . ']');
  
	if ( $request['Type'] == 'SubscriptionConfirmation')
	{
	  MyLog ('Handle SubscriptionConfirmation' );
	  $filedata = file_get_contents( $request['SubscribeURL'] );
	  MyLog ('data:' . $filedata );
	}

	if ( $request['Type'] == 'Notification' )
	{
 
	  $topicARN = $request['TopicArn'];
	  MyLog ('Handle Notification from ARN:' . $topicARN );
	  if ( strpos($topicARN, 'inception') > 0 )
	  {	
		  MyLog ('Found inception ARN');
		  $message = json_decode($request['Message']);
		  
		  if (isset($_GET['iframe'])){
			  MyLog('Iframe option detected');
			  $message->iframe = 'true';
		  }
		  
		  MyLog (' handling message:' . print_r($message,1));
	  		
	  	  if (	isset($message->iframe) &&
				$message->iframe == 'true')
			{
				upsertWPfolder( $message );
			}
			else
			{	
				upsertWPArticle( $message );
			}   	
		 
		  MyLog ( "done" );
	  
	  }
	}
}




function getFiles($allFiles = false)
{
   
    if ($allFiles){
    	 MyLog ( "Getting All files" );
		$files = getDataFromUrl( AWSSNSURL , json_encode(array('Type'=>'getAllFiles','Caller' => SUBKEY )));
	}else{
		 MyLog ( "Getting New files" );
		$files = getDataFromUrl( AWSSNSURL , json_encode(array('Type'=>'getNewFiles','Caller' => SUBKEY )));
	}
	
	//print "files:" . print_r($files,1) .'<br>';
	$files = json_decode($files);
	if (count($files) == 0 )
	{
		MyLog ( "No (new) files found" );
		print "No (new) files found<br>\n" ;
		return;
	}
	
	MyLog ( "Files loaded:" . count($files) );
	print "Files loaded:" . count($files) . "<br>\n" ;
	foreach ( $files as $name => $data )
	{
		print("-------------<br>\n");
		print("Handling file:$name<br>\n");
		MyLog("-------------");
		MyLog("Handling file:$name");
		MyLog("-------------");
		$message = json_decode($data);
		MyLog("message:" . print_r($message,1));
		if (isset($message->iframe) &&
			$message->iframe == 'true')
		{
			upsertWPfolder( $message );
		}
		else
		{	
		    upsertWPArticle( $message );
		}    
	}
}


// this function is an alternative for upsertWPArticles
// in this case the inception article-structure is uploaded to 
// the wordpress upload folder,
// then an article in wp is created, containing a iframe that points to the
// article structure

function upsertWPfolder( $data )
{
	$url = $data->url;
	$ID  = $data->id;
	MyLog ( "Loading data from:$url " );
	$zipdata = file_get_contents( $url);
	// save zipfile to our tempfolder
	$zipname = basename($url);
	$dirname = basename($zipname,'.article');
	MyLog ( "zipname:" . $zipname  );
	file_put_contents( TEMPDIR . $zipname, $zipdata);
	
	// prepare the wp-side
	$upload_dir = wp_upload_dir();
	//MyLog ( print_r($upload_dir,1));
	$articleDirName =  $ID . '-' . $dirname;
	$articleDir = $upload_dir['path'] . '/' . $articleDirName;
	$articleUrl = $upload_dir['url']  . '/' . $articleDirName . '/output.html';
	
	if ( ! file_exists($articleDir) )
	{
		MyLog ( "Creating folder [$articleDir]");
		if ( wp_mkdir_p( $articleDir ) ){
			chmod($articleDir, 0755);
		}	
		else
		{
			MyLog ( "failed to create upload dir [$articleDir], no further processing" );
			return false;
		}
	}
	
	// now unzip the zipfile to our folder
	$zip = new ZipArchive;
	if ($zip->open(TEMPDIR . $zipname) === TRUE) {
    	$zip->extractTo($articleDir);
		$zip->close();
		
		// create the article pointing to this folder
		// use format required for iframe plugin
		$articleHTML = '[iframe src="' . $articleUrl . '"  width="800" height="600" frameborder="0"]';
		$post_id = post_exists($data->name);
		MyLog ( "existing post found with ID:$post_id" );
		
		$wp_error = false;
		if ( $post_id > 0 ){
			MyLog ( 'updating post' );
			$postarr = get_post( $post_id , 'ARRAY_A');
			
			$postarr['post_content'] = $articleHTML;
			// switch off the versioning for this post
			remove_action( 'post_updated', 'wp_save_post_revision' );
			$post_id = wp_update_post( $postarr,  $wp_error  ); //
			MyLog ("wp_error:" . print_r($wp_error,1));
			// switch on the versioning for this post
			add_action( 'post_updated', 'wp_save_post_revision' ); 
			
		}else{
			MyLog ( 'insert new post' );
			$postarr = array(
			 'ID'		=> $post_id, // does not seem to work
			 'post_title'    => wp_strip_all_tags( $data->name ),
			 'post_content'  => $articleHTML,
			 'post_status'   => 'publish',
			 'post_author'   => 1,
			 'post_category' => array( 8,39 )
			);
			$post_id = wp_insert_post( $postarr,  $wp_error  );
			MyLog ("wp_error:" . print_r($wp_error,1));
		}
		MyLog ( 'Created or updated post with ID:' . $post_id );
		
		
	}	

}




// this function will upload the related images to the upload folder
// replace the url in the article to point to the uploaded article
// and store the article as new article
// this will cause some display problems bcause of javasript and styling not working correctly

function upsertWPArticle( $data )
{
	$url = $data->url;
	$ID  = $data->id;
	MyLog ( "Loading data from:$url ");
	$zipdata = file_get_contents( $url);
	// save to disk
	$zipname = basename($url);
	MyLog ("zipname:" . $zipname  );
	file_put_contents( TEMPDIR . $zipname, $zipdata);
	
	$dirname = basename($zipname,'.article');
	if ( $dirname != '')
	{
		//echo "dirname:" . $dirname . '<br>';
		if (file_exists(TEMPDIR . $dirname)) { deleteDir (TEMPDIR . $dirname); }
		if ( ! file_exists( TEMPDIR . $dirname) )
		{
		  mkdir(TEMPDIR . $dirname,0777);
		  chmod(TEMPDIR . $dirname,0777);
		}   
	}
	else
	{
		MyLog ( "no dirname found, exit" );
		return;
	}	
	$zip = new ZipArchive;
	if ($zip->open(TEMPDIR . $zipname) === TRUE) {
    	$zip->extractTo(TEMPDIR . $dirname);
		$zip->close();
		
		// parse the content in the temp folder
		$images = getImagesFromPath( TEMPDIR . $dirname . '/img');
		$articleHTML = file_get_contents(TEMPDIR . $dirname .'/output.html');
		$upload_dir = wp_upload_dir();
		
		// upload the template/design.css
		$wpname = $ID . '-design.css';
		$uploadFile = $upload_dir['path'] . '/' . $wpname;
		if ( ! file_exists($uploadFile))
		{
			MyLog ("upload $uploadFile" );
			$wpfile = wp_upload_bits($wpname, null,file_get_contents(TEMPDIR . $dirname . '/template/design.css') );
		}else{
			MyLog ( "skipping $uploadFile" );
			$wpfile['url'] =  $upload_dir['url'] . '/' . $wpname;
		}
		$articleHTML = str_replace ("template/design.css",  $wpfile['url'] ,$articleHTML);
		
		// upload the template/design.css
		$wpname = $ID . '-vendor.js';
		$uploadFile = $upload_dir['path'] . '/' . $wpname;
		if ( ! file_exists($uploadFile))
		{
			MyLog ( "upload $uploadFile" );
			$wpfile = wp_upload_bits($wpname, null,file_get_contents(TEMPDIR . $dirname . '/template/vendor.js') );
		}else{
			MyLog ( "skipping $uploadFile" );
			$wpfile['url'] =  $upload_dir['url'] . '/' . $wpname;
		}
		$articleHTML = str_replace ("template/vendor.js",  $wpfile['url'] ,$articleHTML);
		
		
		
		// upload the images and replace the links in the article
		foreach( $images as $image )
		{
			// check if file exists
			$fname = $ID . '-' . basename($image);
			MyLog('upload_dir :' . print_r($upload_dir,1) );
			$uploadFile = $upload_dir['path'] . '/' . $fname;
			
			MyLog("Checking for file [$uploadFile]");
			if ( ! file_exists($uploadFile))
			{
				MyLog ( "uploading file" );
				$wpfile = wp_upload_bits($fname, null,file_get_contents($image) );
			}else{
				MyLog ( "skipping upload, file already there !" );
				$wpfile['url'] =  $upload_dir['url'] . '/' . $fname;
			}
			
			MyLog ("wpfile:" . print_r( $wpfile,1) );
			//Update the image url in the html
       		MyLog ("Replacing [" .  basename($image) . "]  with [" . $wpfile['url'] . "]" );
       		
       		$articleHTML = str_replace ("&quot;img/" . basename($image) . "&quot;", "'". $wpfile['url'] . "'",$articleHTML);
	   		$articleHTML = str_replace ("img/" . basename($image) . "", "". $wpfile['url'] . "",$articleHTML);
        	
		}
		
		file_put_contents( TEMPDIR . '/article.txt', $articleHTML );
		// Create post object
		$post_id = post_exists($data->name);
		MyLog ( "existing post found with ID:$post_id");
		
		
		
		
		$wp_error = false;
		if ( $post_id > 0 ){
			MyLog ( 'update post' );
			$postarr = get_post( $post_id , 'ARRAY_A');
			
			$postarr['post_content'] = $articleHTML;
			// switch off the versioning for this post
			remove_action( 'post_updated', 'wp_save_post_revision' );
			$post_id = wp_update_post( $postarr,  $wp_error  ); //
			MyLog ("error:" . print_r($wp_error,1));
			// switch on the versioning for this post
			add_action( 'post_updated', 'wp_save_post_revision' ); 
		}else{
			MyLog ( 'insert new post' );
			$postarr = array(
			 'ID'		=> $post_id, // does not seem to work
			 'post_title'    => wp_strip_all_tags( $data->name ),
			 'post_content'  => $articleHTML,
			 'post_status'   => 'publish',
			 'post_author'   => 1,
			 'post_category' => array( 8,39 )
			);
			wp_insert_post( $postarr,  $wp_error  );
		}
		MyLog ( 'result:' . print_r($wp_error,1)  );
		
		
		if (file_exists(TEMPDIR . $dirname)) { deleteDir (TEMPDIR . $dirname); }
		
	} else {
    	MyLog ( 'failed to retrieve/unzip from url [' . $url . ']');
	}
}


function getImagesFromPath($imageFolder)
{
	$images = array();
	$dh  = opendir($imageFolder);
	if ( $dh !== false)
	{
		$filename = readdir($dh);
		
		while ($filename !== false) 
		{
			if ( $filename != '.' &&
				 $filename != '..' &&
				 $filename !== false)
			{	 
				//print ('filename:' . $filename );
				//$fileContent = file_get_contents( $imageFolder .'/'. $filename );
				$images[] = $imageFolder .'/'.  $filename ;
				
			}	
			$filename = readdir($dh);
		}
	}	
	
	return $images;

}



function getDataFromUrl( $url, $postdata )
{
	//print "url:$url data:$postdata<br>";
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_POST, 1);
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata);
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt( $ch, CURLOPT_HEADER, 0);
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
	//print 'exec curl';
	$response = curl_exec( $ch );
	//print 'not:' . print_r($response,1);
	return $response;
}


function deleteDir($dirPath) {
    if (! is_dir($dirPath)) {
        throw new InvalidArgumentException("$dirPath must be a directory");
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) {
            deleteDir($file);
        } else {
        	//print "unlink:" . $file . '<br>';
            unlink($file);
        }
    }
    rmdir($dirPath);
}

// -------------------------------
// -------- LOG FUNCTIONS --------
// -------------------------------
function getRealIpAddr()
{
    $ip = '::1';
    if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
    {
      $ip=$_SERVER['HTTP_CLIENT_IP'];
    }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
    {
      $ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    elseif (!empty($_SERVER['REMOTE_ADDR']))
    {
      $ip=$_SERVER['REMOTE_ADDR'];
    }
    
    
    if ( $ip == '::1' ) { $ip = 'localhost';}
    return $ip;
}


function getLogPath()
{
   $logfolder = LOGPATH;
   $date = date('Ymd');
   
    if ( ! file_exists( $logfolder) )
    { 
       error_log (basename(__FILE__) . ' -> ERROR: Logfolder [' . $logfolder . '] does not exists, please create',0);
       print basename(__FILE__) . ' -> ERROR: Logfolder [' . $logfolder . '] does not exists, please create';
       exit;
    }
    
   $logfolder = $logfolder . $date ;
   if ( ! file_exists( $logfolder) )
   {
     mkdir($logfolder,0777);
     chmod($logfolder,0777);
   } 
      
      
   // add IPAdres if required
   if ( defined ('LOGPATHWITHIP') &&
   		LOGPATHWITHIP === true )
   {
	  $ip = getRealIpAddr();
   	  $logfolder = $logfolder . '/' . $ip;
   }	   		
   
  
   if ( ! file_exists( $logfolder) )
   {
     mkdir($logfolder,0777);
     chmod($logfolder,0777);
   }    

   return $logfolder .'/';
}

function getLogTimeStamp()
{
  list($ms, $sec) = explode(" ", microtime()); // get seconds with ms part
  $msFmt = sprintf( '%03d', round( $ms*1000, 0 ));
  return date('Y-m-d H-i-s (T)',$sec).'.'.$msFmt;
}

function mustLog()
{
   global $loggedInUser;
   $do_log = false;
  // error_log('LOG_ALL:' . LOG_ALL );
   $ip = getRealIpAddr();
   
   if ( LOG_ALL === false)
   {
    
     $logip = unserialize(LOG_IP);
    // error_log('logip:' . print_r($logip,1));
    // error_log('ip:' . print_r($ip,1));
      
     if (in_array($ip,$logip) )
     {
       $do_log = true;
     }  
   
    
   
   }
   else
   {
     $do_log = true;
   } 
   //error_log( 'do_log:' . $do_log );
   return $do_log;
}


function MyLogS( $logline )
{
   MyLog( $logline, true );
}

function MyLog( $logline , $toBrowser = false)
{ 
   global $loggedInUser, $currentCommand, $logTimeStamp, $LOGNAME, $logfilename;
   
   if ( isset($logfilename))
   {
     $LOGNAME = $logfilename;
   }
   else
   {
     $LOGNAME = LOGNAME;
   }
   
   if ( mustLog() === true )
   {
      
      $userID = 0;
      if ( isset($loggedInUser->user_id) )
      {
        $userID = $loggedInUser->user_id;
      }
      $ip = getRealIpAddr();

      $datetime = getLogTimeStamp() . "[$ip] [$userID]";
      //'[' . date("d-M-Y H:i:s") . "] [$ip] [$userID]";
      
      $logfolder = getLogPath();
      $logname = $LOGNAME;
      
      
                                        
      if ( $currentCommand != '' &&
           $logTimeStamp   != '')
      {
         $logfile = $logfolder . '/' .$logTimeStamp . '-' . $currentCommand .  '.log';
      }
      else
      {                                  
        $logfile = $logfolder . '/' . $logname . '.log';
      }
      
      $logh = fopen($logfile, 'a');
      if ( $logh !== false)
      {
         fwrite( $logh, $datetime .  $logline . "\n");
         fclose( $logh );
         chmod ( $logfile, 0777 );
      }
      else
      {
          error_log ( basename(__FILE__) . ' -> ERROR: writing to logfile [$logfile]' );
      }
    
      if ( $toBrowser )
      {
        print $logline . "<br>\n"; 
        try {while (ob_get_level() > 0) ob_end_flush();} catch( Exception $e ) {}
      }     
    }
 } 


/**
 * Places dangerous characters with "-" characters. Dangerous characters are the ones that 
 * might error at several file systems while creating files or folders. This function does
 * NOT check the platform, since the Server and Filestore can run at different platforms!
 * So it replaces all unsafe characters, no matter the OS flavor. 
 * Another advantage of doing this, is that it keeps filestores interchangable.
 * IMPORTANT: The given file name should NOT include the file path!
 *
 * @param string $fileName Base name of file. Path excluded!
 * @return string The file name, without dangerous chars.
 */
function replaceDangerousChars( $fileName )
{
    MyLog('-replaceDangerousChars');
    MyLog(" input: $fileName ");
	$dangerousChars = "`~!@#$%^*\\|;:'<>/?\"";
	$safeReplacements = str_repeat( '-', strlen($dangerousChars) );
	$fileName = strtr( $fileName, $dangerousChars, $safeReplacements );
	MyLog(" output: $fileName ");
	return $fileName;
}
	
/**
 * Encodes the given file path respecting the FILENAME_ENCODING setting.
 *
 * @param string $path2encode The file path to encode
 * @return string The encoded file path
 */
function encodePath( $path2encode )
{
  MyLog('-encodePath');
  MyLog(" input: $path2encode ");
  
  setlocale(LC_CTYPE, 'nl_NL');
  $newPath = iconv('UTF-8', "ASCII//TRANSLIT", $path2encode);
  $newPath = preg_replace('/[^A-Za-z0-9\-]/', '', $newPath);
  
  MyLog(" output: $newPath ");
  return $newPath;
}

