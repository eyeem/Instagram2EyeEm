<?php

require_once( APP_DIR . '/api/foursquare/src/FoursquareAPI.class.php' );

$foursquare = new FoursquareAPI(
					$auth_config[ 'foursquare' ][ 'client_id' ],
					$auth_config[ 'foursquare' ][ 'client_secret' ]
					);

function return_json( $mediaList = array(), $max_id = null, $error = null ) {
	return json_encode( array( 'media' => $mediaList, 'error' => $error, 'max_id' => $max_id ) );
}

$error            = null;
$mediaList        = array();

//	Instagram userid to look for in the RegEx to parse $instagram_id
$instagram_userid = ( ! empty( $_REQUEST[ 'igers_user' ] ) ? $_REQUEST[ 'igers_user' ] : '[0-9]+' );

//	Original photo ID on Instagram
$instagram_id     = ( ! empty( $_REQUEST[ 'igers_id' ] )   ? $_REQUEST[ 'igers_id' ]   : null );

//	Is this photo already on EyeEm?
$isDuplicated     = false;

//	Return empty JSON object if an access token session isnt present
if ( empty( $_SESSION[ 'eyeem_access_token' ][ 'access_token' ] ) ) {
    exit( return_json( array(), null, 'You are not authenticated in EyeEm!' ) );
} else {
	$eyeem->setAccessToken( $_SESSION[ 'eyeem_access_token' ][ 'access_token' ] );

}

//	Let's get the list of photos with an Instagram ID
try {

	$igList = $eyeem->request(
	    '/experimental/instagramIds',
	    'GET',
	    array(),
	    true
	);

	$igList = $igList[ 'instagramIds' ];

	$canWeCheck4Duplicates = true;

} catch( Exception $e ) {
	$canWeCheck4Duplicates = false;
}

if( $canWeCheck4Duplicates ) {

	//	Split $instagram_id in pieces ( POHOTOID_USERID)
	//	and check if already exists in EyeEm such a photo
	if( preg_match( '~^([0-9]+)_' . $instagram_userid . '$~i', $instagram_id, $match ) ) {
		//	Make sure match[ 1 ] is an integer
		$match[ 1 ] = intval( $match[ 1 ] );
	
		//	Check if we have such an Id in the $igList
		if( isset( $igList[ $match[ 1 ] ] ) &&
		  ! empty( $igList[ $match[ 1 ] ] ) ) {
			$igPhotoId = $match[ 1 ];
			$isDuplicated = true;
		}
	}
}

if( $isDuplicated ) {
	//	This photo is already on EyeEm
	//	We just need to load data
	try {
		$photo = $eyeem->getPhoto( $igList[ $igPhotoId ] );

		//	Create the igers2eye array to pass back to the ajax caller
		$mediaList[] = array(
	    	'id'           => $photo->id,
	    	'title'        => $photo->title,
	    	'caption'      => $photo->caption,
	    	'width'        => $photo->width,
	    	'height'       => $photo->height,
	    	'thumbUrl'     => $photo->thumbUrl,
	    	'photoUrl'     => $photo->photoUrl,
	    	'webUrl'       => $photo->webUrl,
	    	'venueid'      => '',
	    	'latitude'     => $photo->latitude,
	    	'longitude'    => $photo->longitude,
	    	'topic'        => '',
	    	'updated'      => $photo->updated,
	    	'instagram_id' => $instagram_id,
	    	'timestamp'    => 0,
	    	'duplicate'    => true,
	    );
	} catch( Exception $e ) {
		exit( return_json( array(), null, 'Troubles skipping duplicated photo on EyeEm! ' . $e->getMessage() ) );
	}

} else {
	//	This photo is NEW!
	//	We have to upload and create
	//	the photo object on EyeEm
	try {
	
		//	Config parameters for the temporary upload on server
		$url     = $_REQUEST[ 'photo' ];
		$savedir = '/tmp';
	
		//	Let's find what the Instagram url is made of... ;-)
		$urlInfo    = parse_url( $url );
		$sourcename = basename(	$urlInfo[ 'path' ] );
		$targetname = $savedir . '/' . $sourcename;
	
		//	Save the Instagram image on my server so that I can upload it to EyeEm
		file_put_contents( $targetname, file_get_contents( $_REQUEST[ 'photo' ] ) );
	
		//	Upload the image to EyeEm
		$filename = $eyeem->uploadPhoto( $targetname );
	
		//	initialize the EyeEm Photo object params
		$eye_params = array(
	    	'filename'     => $filename,
	    	//	We HAVE to REMOVE @ from the title beacusa EyeEm DOES NOT LIKE them!
		   	'title'        => stripslashes( mb_ereg_replace( '@', '', $_REQUEST[ 'title' ] ) ),
		   	'timestamp'    => ( ! empty( $_REQUEST[ 'created' ] ) ? $_REQUEST[ 'created' ] : time() ),
	    );
	
		//	Save original Instagram photo ID
		if( ! empty( $instagram_id ) ) {
		   	$eye_params[ 'instagram_id' ] = $instagram_id;
		}
	
		//	Geolocate through FourSquare
		if( ! empty( $_REQUEST[ 'loc_lat' ]  ) &&
			! empty( $_REQUEST[ 'loc_lon' ]  ) &&
			! empty( $_REQUEST[ 'loc_name' ] ) ) {
	
			$lat      = $_REQUEST[ 'loc_lat'  ];
			$lon      = $_REQUEST[ 'loc_lon'  ];
			$location = $_REQUEST[ 'loc_name' ];
	
			$fs_params = array(
				'll' => $lat . ',' . $lon,
			);
	
			//	Perform a request to a public resource
			$response = $foursquare->GetPublic( 'venues/search', $fs_params );
			$venues = json_decode( $response );
	
			$igers_name = mb_convert_case( $location, MB_CASE_LOWER, 'UTF-8' );
			$venueId    = null;
	
			//	Let's try to find a matching location name near given latitude and longitude
			//	Trying to match Instagram location name and FourSquare venue Name
			foreach( $venues->response->venues as $venue ) {
				$foursquare_name = mb_convert_case( $venue->name, MB_CASE_LOWER, 'UTF-8' );
				if( $foursquare_name == $igers_name ) {
					
					//	YiPPIE KI-YAY! We found a matching location name!!!
					$venueId = $venue->id;
					break;
					
				}
			}
	
			//	If we found a venue let's include it in the EyeEm Photo object params!
			if( ! empty( $venueId ) ) {
				$eye_params[ 'venueId' ]          = $venueId;
				$eye_params[ 'venueServiceName' ] = 'foursquare';
			}
		}
	
		//	Create the EyeEm Photo object
		$photo = $eyeem->postPhoto( $eye_params	);
		
		//	Set topics to this very image
		//	Convert Igers #hashtag to EyeEm topic
		if( ! empty( $_REQUEST[ 'topic' ] ) ) {
	
			$topic = explode( ',', $_REQUEST[ 'topic' ] );
			foreach( $topic as $t ) {
				$eyeem->request(
					'/photos/' . $photo->id . '/topics',
					'POST',
					array(
						'name' => $t,
					)
				);
			}
		}
	
		//	Create the igers2eye array to pass back to the ajax caller
		$mediaList[] = array(
	    	'id'           => $photo->id,
	    	'title'        => $photo->title,
	    	'caption'      => $photo->caption,
	    	'width'        => $photo->width,
	    	'height'       => $photo->height,
	    	'thumbUrl'     => $photo->thumbUrl,
	    	'photoUrl'     => $photo->photoUrl,
	    	'webUrl'       => $photo->webUrl,
	    	'venueid'      => ( ! empty( $venueId ) ? $venueId : '' ),
	    	'latitude'     => $photo->latitude,
	    	'longitude'    => $photo->longitude,
	    	'topic'        => ( ! empty( $_REQUEST[ 'topic' ] ) ? $_REQUEST[ 'topic' ] : '' ),
	    	'updated'      => $photo->updated,
	    	'instagram_id' => ( ! empty( $_REQUEST[ 'igers_id' ] ) ? $_REQUEST[ 'igers_id' ] : '' ),
	    	'timestamp'    => $eye_params[ 'timestamp' ],
	    	'duplicate'    => false,
	    );
	
	} catch( Exception $e ) {
		exit( return_json( array(), null, 'Troubles uploading photo to EyeEm! ' . $e->getMessage() ) );
	}
	
	//	Remove the now useless uploaded file from my temp folder
	if( file_exists( $targetname ) ) {
		unlink( $targetname );
	}
}

exit( return_json( $mediaList, null, null ) );