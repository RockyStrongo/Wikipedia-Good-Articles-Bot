<?php
$start = microtime(true);
ini_set('max_execution_time', 0);

//include the Oauth library - https://twitteroauth.com/
require "twitteroauth/autoload.php";

use Abraham\TwitterOAuth\TwitterOAuth;

//Keys and Access Tokens for Twitter
include ("twitter_credentials.php");

//Keys and Access Tokens for Google API
include ("google_credentials.php");

//password
include ("password_check.php");

//my personal email adress
include ("email_address.php");

//get search results from Wikipedia API for good articles
$endPoint = "https://en.wikipedia.org/w/api.php";
$params = ["action" => "query", "format" => "json", "list" => "embeddedin", "eititle" => "Template:Good article", "eilimit" => "max", "eidir" => "ascending"];

$url = $endPoint . "?" . http_build_query($params);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$output = curl_exec($ch);
curl_close($ch);

//transform json result in php array
$result = json_decode($output, true);

//parameter to get the next page of results
//echo "<br>ei continue: ";
$eicontinue = $result["continue"]["eicontinue"];
//put pages result in array
$pages = $result["query"]["embeddedin"];

//get live tweets existing to avoid duplicate posting
$connection = new TwitterOAuth($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);
//increase timeout limit
$connection->setTimeouts(10, 100);
$content = $connection->get("account/verify_credentials");

//get number of tweets
$parameters = ["screen_name" => "wikigoodarticle"];

$userarray = $connection->get("users/show", $parameters);

$numberoftweets = $userarray->statuses_count;
echo "<br>Number of tweets: " . $numberoftweets;

//limit of twitter API to get tweets is 200
//divide the number of tweets by 200 to know how many times we need to get tweets
$numberoftweetsdivided = $numberoftweets / 200;
echo "<br>Number of tweets divided by 200: " . $numberoftweetsdivided;
//round to down number
$iterationsneeded = floor($numberoftweetsdivided);
echo "<br>Iterations needed: " . $iterationsneeded;

//daily twitter API limit= 3200 - 200 limit for 1 get
$dailyAPIlimit = 3200 / 200;

//Get first 200 tweets
$parameters = ["screen_name" => "wikigoodarticle", "count" => "200"];

$statuses = $connection->get("statuses/user_timeline", $parameters);

//get last tweet ID
$lastKey = key(array_slice($statuses, -1, 1, true));

//echo "<br>Last Key: " . $lastKey;
//echo "<br>Last key id: " . $statuses[$lastKey]->id;
$lastkeyID = $statuses[$lastKey]->id;

echo "<br>";
echo "<br>Size of tweets array before loop to get more tweets: " . sizeof($statuses);

//maxid = returns tweets older than this ID
$i = 0;
while ($i < $iterationsneeded && $i < $dailyAPIlimit)
{
	echo "<br>while loop to get more tweets";
	$parameters = ["screen_name" => "wikigoodarticle", "count" => "200", "max_id" => $lastkeyID];

	$newstatuses = $connection->get("statuses/user_timeline", $parameters);

	foreach ($newstatuses as $k => $v)
	{
		array_push($statuses, $v);
	}
	$lastkeyID = $newstatuses[$lastKey]->id;
	$i++;
}

echo "<br>Size of tweets array after loop to get more tweets: " . sizeof($statuses);
echo "<br>";

//put each tweet in an array
$mywikitweets = array();
$i = 0;

foreach ($statuses as $k => $v)
{
	$mywikitweetwithurl = $v->text;
	$regex = "@(https?://([-\w\.]+[-\w])+(:\d+)?(/([\w/_\.#-]*(\?\S+)?[^\.\s])?).*$)@";
	$mywikitweets[$i] = preg_replace($regex, ' ', $mywikitweetwithurl);
	$i++;
}


//function to remove from wiki pages array the titles that exists in the live tweets array
function removealreadytweeted($tweetarray, $wikipagesarray)
{
	$wikipagesarray['alreadytweeted'] = array();


	foreach ($tweetarray as $k => $v)
	{
		$tweettitle = $v;
		$tweettitle = htmlspecialchars_decode($tweettitle);
		$i = 1;
		//slice array to 100 for performance
		foreach (array_slice($wikipagesarray, 100) as $key => $value)
		{
			
			if (isset($value["title"])) {
				$wikipagetitle = $value["title"];
				$wikipagetitle = htmlspecialchars_decode($wikipagetitle);
				if (strpos($tweettitle, $wikipagetitle) !== false && is_null($wikipagesarray['alreadytweeted']) == false)
				{
					echo "<br> match found in already posted tweets - remove this article from pages array : " . $wikipagetitle;
					unset($wikipagesarray[$key]);
					array_push($wikipagesarray['alreadytweeted'], $wikipagetitle);
				}
			}

		}
	}
	return $wikipagesarray;
}

//remove from wiki pages array the titles that exists in the live tweets array
$pages = removealreadytweeted($mywikitweets, $pages);
$alreadytweetedarticles = $pages['alreadytweeted'];

//function to get article category from Google knowledge graph API
function getarticlecategory($articletitlefunct)
{

	include ("google_credentials.php");

	$params = array(
		'query' => $articletitlefunct,
		'limit' => 1,
		'indent' => true,
		'key' => $api_key
	);
	$url = $service_url . '?' . http_build_query($params);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$response = json_decode(curl_exec($ch) , true);
	curl_close($ch);

	foreach ($response['itemListElement'] as $element)
	{
		$thiscategory = $element['result']['description'];
		$thisscore = $element['resultScore'];
	}

	if ($thiscategory == NULL or $thisscore < 100)
	{
		$articlecategoryfunct = "no category";
	}
	else
	{
		$articlecategoryfunct = $element['result']['description'];
	}

	return $articlecategoryfunct;

}


//function to use wikidata to get information: is article a human? what gender? get description
function wikidatainfo($myarticletitle)
{

	$wikidatainfo = array();

	$endPoint = "https://en.wikipedia.org/w/api.php";
	$params = ["action" => "query", "format" => "json", "prop" => "pageprops", "ppprop" => "wikibase_item", "redirects" => "1", "titles" => $myarticletitle];

	$url = $endPoint . "?" . http_build_query($params);

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$output = curl_exec($ch);
	curl_close($ch);

	$result = json_decode($output, true);
	$articlesarray = $result["query"]["pages"];
	foreach ($articlesarray as $cle => $valeur)
	{
		$wikidataID = $valeur["pageprops"]["wikibase_item"];
	}

	$url = "https://www.wikidata.org/wiki/Special:EntityData/" . $wikidataID . ".json";

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$output = curl_exec($ch);
	curl_close($ch);

	$result = json_decode($output, true);

	$instanceof = $result["entities"]["$wikidataID"]["claims"]["P31"]["0"]["mainsnak"]["datavalue"]["value"]["id"];

	$wikidatainfo["title"] = $myarticletitle;

	$wikidatadescription = $result["entities"]["$wikidataID"]["descriptions"]["en"]["value"];
	$wikidatainfo["description"] = $wikidatadescription;

	if ($instanceof == "Q5")
	{
		$wikidatainfo["ishuman"] = "Yes";
    $wikidatainfo["ischemicalelement"] = "No";
		$wikidatainfogenderID = $result["entities"]["$wikidataID"]["claims"]["P21"]["0"]["mainsnak"]["datavalue"]["value"]["id"];
		if ($wikidatainfogenderID == "Q6581097")
		{
			$wikidatainfo["gender"] = "Male";
		}
		else if ($wikidatainfogenderID == "Q6581072")
		{
			$wikidatainfo["gender"] = "Female";
		}
		else
		{
			$wikidatainfo["gender"] = "other";
		}
	}
	else
	{
		$wikidatainfo["ishuman"] = "No";
		$wikidatainfo["gender"] = "Not Applicable";
		if ($instanceof == "Q11344")
	{
		$wikidatainfo["ischemicalelement"] = "Yes";
	} else {
    $wikidatainfo["ischemicalelement"] = "No";
  }

	}

	return $wikidatainfo;
}


function removechemicalelements($wikipagesarray) {
	foreach ($wikipagesarray as $key => $value)
		{
			if($value["ischemicalelement"] == "Yes") {
				echo "<br>".$value["title"]." is a chemical element, remove from pages array";
				unset($wikipagesarray[$key]);
			}
		}
		return $wikipagesarray;
	}

//reduce number of article to 10 to avoid perf issues
//if already less than 10, keep array size
if (sizeof($pages) <= 10)
{
	$numberofpages = sizeof($pages);
}
else
{
	$numberofpages = 10;
}

$randIndexes = array_rand($pages, $numberofpages);


$newarray = array();
foreach($randIndexes as $key => $value){
    array_push($newarray, $pages[$value]);

}

$pages = $newarray;




//get wikidata info for the articles
$pageswithinfo = array();

foreach ($pages as $key => $value)
{
  $wikipagetitle = $value["title"];
  $wikipagetitle = htmlspecialchars_decode($wikipagetitle);
  $wikiinfo = wikidatainfo($wikipagetitle);
  array_push($pageswithinfo, $wikiinfo);
}

$pages = $pageswithinfo;

$pages = removechemicalelements($pages);


//function to get wikipedia next page results
function getnextpage($eicontinueid)
{
	$endPoint = "https://en.wikipedia.org/w/api.php";
	$params = ["action" => "query", "format" => "json", "list" => "embeddedin", "eititle" => "Template:Good article", "eilimit" => "max", "eidir" => "ascending", "eicontinue" => $eicontinueid];

	$url = $endPoint . "?" . http_build_query($params);

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$output = curl_exec($ch);
	curl_close($ch);

	//transform json result in php array
	$result = json_decode($output, true);

	return $result;
}


//check size of pages array - if empty, go to next page

if (sizeof($pages) == 0)
{
	while (sizeof($pages) == 0)
	{
		echo "<br>array of pages empty go to next wiki results page";
		$pages = getnextpage($eicontinue) ["query"]["embeddedin"];
		$neweicontinue = getnextpage($eicontinue) ["continue"]["eicontinue"];
		$eicontinue = $neweicontinue;
		echo "<br>new eicontinue:" . $eicontinue;
		$newpages = removealreadytweeted($mywikitweets, $pages);
		$newpages = removechemicalelements($newpages);
		echo "<br>size of new pages array:" . sizeof($newpages);
		$pages = $newpages;
		$pages_without_already_tweeted = $newpages;
	}
}


//get categories of already tweeted articles from Google Knowledge Graph API
//reduce number already tweeted articles to last 100 to avoid perf issues
//if already less than 50, keep array size
if (sizeof($alreadytweetedarticles) <= 50)
{
	$numberofpages = sizeof($alreadytweetedarticles);
}
else
{
	$numberofpages = 50;
}

$alreadytweetedarticles = array_slice($alreadytweetedarticles, 0, $numberofpages);

$alreadytweetedcateg = array();


foreach ($alreadytweetedarticles as $k => $v)
{
	$tweettitle = $v;
	$params = array(
		'query' => $tweettitle,
		'limit' => 1,
		'indent' => true,
		'key' => $api_key
	);
	$url = $service_url . '?' . http_build_query($params);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$response = json_decode(curl_exec($ch) , true);
	curl_close($ch);

	//put already tweeted categories in an array
	foreach ($response['itemListElement'] as $element)
	{

		$category = $element['result']['description'];
		$score = $element['resultScore'];
		if ($category == NULL or $score < 100)
		{
			echo "<br> there is no matching category in Google knowledge graph API for " . $tweettitle;
		}
		else
		{
			//array of categories already tweeted
			echo "<br>" . $tweettitle . " is this: " . $element['result']['description'] . ' - Score: ' . $element['resultScore'];
			array_push($alreadytweetedcateg, $category);
		}
	}
}

$allrandomtitles = array();
$humanmale = array();
$notgender = array();
$humanfemale = array();

	foreach ($pages as $k => $v)
	{

		$thistitle = $v;
		array_push($allrandomtitles, $thistitle);

		if ($pages["gender"] == "Male")
		{
			echo "<br>" . $thistitle['title'] . " is a human male";
			array_push($humanmale, $thistitle);
		}
		else if ($pages["gender"] == "Female")
		{
			echo "<br>" . $thistitle['title'] . " is a human female";
			array_push($humanfemale, $thistitle);
		}
		else
		{
			echo "<br>" . $thistitle['title'] . " is not applicable to gender";
			array_push($notgender, $thistitle);
		}
	}


echo "<br>";
echo "<br>Size of my random articles array : " . sizeof($allrandomtitles);
echo "<br>Size of human male array : " . sizeof($humanmale);
echo "<br>Size of human female array : " . sizeof($humanfemale);
echo "<br>Size of not applicable to gender array : " . sizeof($notgender);
echo "<br>";


//if there are more articles with human male than human female, remove the number of additional males to get equality
if (sizeof($humanmale) > sizeof($humanfemale) && sizeof($notgender) != 0)
{

	$numbertoremove = (sizeof($humanmale) - sizeof($humanfemale))/2;
	$numbertoremove = round($numbertoremove);
	echo "<br>number of males to remove: " . $numbertoremove;
	$randIndexesremove = array_rand($humanmale, $numbertoremove);

	foreach ($randIndexesremove as $k => $v)
	{
		$thistitle = $humanmale[$v];
		echo "<br>" . $thistitle["title"] . " is removed from array for equality because there were too many males randomly selected";

		foreach ($allrandomtitles as $kk => $vv)
		{
			if ($vv['title'] == $thistitle['title'])
			{
				unset($allrandomtitles[$kk]);
			}
		}
	}
}


//get categories of random articles, check if the category was already tweeted
//create 1 array for articles with already posted category
//create 1 array for articles with not yet posted category
$articleswithalreadypostedcategory = array();
$articleswithnotyetpostedcategory = array();
$articleswithchemicalelementcategory = array();


foreach ($allrandomtitles as $k => $v)
{
	$thistitle = $v['title'];
	$thistitlecategory = getarticlecategory($thistitle);
	//echo "<br>".$thistitle." is this category: ".$thistitlecategory;
	$alreadytwitted = '0';

	foreach ($alreadytweetedcateg as $key => $value)
	{
		$thistweetedcateg = $value;
		if (strpos($thistitlecategory, $thistweetedcateg) !== false)
		{
				echo "<br> the category: " . $thistitlecategory . " was already tweeted";
				$alreadytwitted = 'Y';
		}
	}
	if ($alreadytwitted == 'Y')
	{
		array_push($articleswithalreadypostedcategory, $v);
	}
	else
	{
		array_push($articleswithnotyetpostedcategory, $v);
	}
}


//if there are articles with category not yet tweeted, chose a random one, if not choose a random one from already tweeted category
if (sizeof($articleswithnotyetpostedcategory) != 0)
{
	echo "<br>there are some articles available with not yet tweeted categories";
	// get random index from array
	$randIndex = array_rand($articleswithnotyetpostedcategory);
	//get title from random index
	$titlerandom = $articleswithnotyetpostedcategory[$randIndex];
}
else if (sizeof($articleswithalreadypostedcategory) != 0)
{
	echo "<br>there are no articles available with not yet tweeted categories";
	$randIndex = array_rand($articleswithalreadypostedcategory);
	$titlerandom = $articleswithalreadypostedcategory[$randIndex];
}

// show the title value for the random index
echo "<br>";
echo "<br> random title: ";
echo $titlerandom['title'];
echo "<br>";

echo "<pre>";
print_r($titlerandom);
echo "<pre>";


//article description
$titlerandomdescription = $titlerandom['description'];
//upper case for first letter
$titlerandomdescription = ucfirst($titlerandomdescription);
echo "<br>Random title description: " . $titlerandomdescription . "<br><br>";


//get URL of article
$endPointinfo = "https://en.wikipedia.org/w/api.php";
$paramsinfo = ["action" => "query", "format" => "json", "titles" => $titlerandom['title'], "prop" => "info", "inprop" => "url|talkid"];

$urlinfo = $endPointinfo . "?" . http_build_query($paramsinfo);

$ch = curl_init($urlinfo);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$outputinfo = curl_exec($ch);
curl_close($ch);

$resultinfo = json_decode($outputinfo, true);

foreach ($resultinfo["query"]["pages"] as $k => $v)
{
	$pageurl = $v["fullurl"];
	echo ("corresponding URL: " . $v["fullurl"]);
}

//if title is empty stop here and send an error email
if (strlen($titlerandom['title']) == 0 )	{

	$to = $mypersonalemail;
	$subject = "Wikigoodarticles error while posting tweet";
	$txt = "Title empty, tweet not sent - exit program";
	$headers = "From: Wikigoodarticles";

	mail($to, $subject, $txt, $headers);
	exit("title empty");
}

//if title + description <= 257, tweet it, else tweet only title (URL is always counted as 23 char by Twitter 280-23 = 257)
$mywikitweet = $titlerandom['title'] . " - " . $titlerandomdescription;

if (strlen($mywikitweet) <= 257)
{
	$mywikitweet = $titlerandom['title'] . " - " . $titlerandomdescription . " " . $pageurl;
} else if (strlen($titlerandomdescription) == 0)
{
	$mywikitweet = $titlerandom['title'] . " " . $pageurl;
}
else
{
	$mywikitweet = $titlerandom['title'] . " " . $pageurl;
}

//get images for this wikipedia article
$endPoint = "https://en.wikipedia.org/w/api.php";
$params = ["action" => "query", "prop" => "images", "titles" => $titlerandom['title'], "format" => "json"];

$url = $endPoint . "?" . http_build_query($params);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$output = curl_exec($ch);
curl_close($ch);

$result = json_decode($output, true);

$imgpages = $result["query"]["pages"];

$firstpage = array_slice($imgpages, 0, 1) [0];

$images = $firstpage["images"];

//function to get image details (size, url or mime)
function getwikiimagedetails($imagevar, $info)
{
	$endPoint = "https://en.wikipedia.org/w/api.php";
	$params = ["action" => "query", "titles" => $imagevar, "format" => "json", "prop" => "imageinfo", "iiprop" => "url|size|mime"];

	$url = $endPoint . "?" . http_build_query($params);

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$output = curl_exec($ch);
	curl_close($ch);

	$result = json_decode($output, true);

	foreach ($result["query"]["pages"] as $k => $v)
	{
		foreach ($v['imageinfo'] as $k => $v)
		{
			if ($info == 'size')
			{
				$output = $v["size"];
			}
			else if ($info == 'url')
			{
				$output = $v["url"];
			}
			else if ($info == 'mime')
			{
				$output = $v["mime"];
			}
			return $output;
		}
		return $output;
	}
	return $output;
}

//for each image if mime incorrect, remove from array
//Supported image media types: JPG, PNG, GIF, WEBP

$includesgif = 0;
$gifimagesarray = array();

foreach ($images as $k => $v)
{
	$myimagemime = getwikiimagedetails($v['title'], 'mime');

	if ($myimagemime != 'image/png' && $myimagemime != 'image/jpeg' && $myimagemime != 'image/gif' && $myimagemime != 'image/webp')
	{
		echo "<br>the image " . $v['title'] . "has incorrect format: " . $myimagemime . " . remove from array";
		unset($images[$k]);
	}
	else
	{
		echo "<br>the image " . $v['title'] . "has correct format: " . $myimagemime . " . keep in array";
	}

//if there is one gif, note it via includesgif tag and store the last gif in gifimage variable

	if ($myimagemime == 'image/gif')
	{
		$includesgif = 1;
		$gifimage = $images[$k];
	}

}

echo "<br><br>";

//for each image if size is too big, remove from array
foreach ($images as $k => $v)
{
	$myimagesize = getwikiimagedetails($v['title'], 'size');
	if ($myimagesize >= 5000000)
	{
		echo "<br>the image " . $v['title'] . "is too big: " . $myimagesize . " bytes. remove from array";
		unset($images[$k]);
	}
	else
	{
		echo "<br>the image " . $v['title'] . "is ok for size: " . $myimagesize . " bytes. keep in array";
	}
}

//if there is a gif in the image array, keep only the gif
if 	($includesgif == 1) {
	echo "<br>there is a gif, let's keep only a gif in the image array<br>";
	//empty images array
	$images = array();
	//put only the last gif image
	array_push($images, $gifimage);
}

//function to send error email
function sendwikierroremail($error)
{
	$to = $mypersonalemail;
	$subject = "Wikigoodarticles error while posting tweet";
	$txt = "There was an error while posting a tweet - error :" . $error;
	$headers = "From: Wikigoodarticles";

	mail($to, $subject, $txt, $headers);
}

//check if no image exist in the array
if (sizeof($images) == 0)
{
	//post only text tweet
	echo "no image with correct size or format, post only text";
	$statues = $connection->post("statuses/update", ["status" => $mywikitweet]);

	if ($connection->getLastHttpCode() == 200)
	{
		echo "<br>Tweet posted succesfully";
	}
	else
	{
		echo "error posting tweet";
		$errortwitter = $connection->getLastHttpCode();
		echo $errortwitter;
		sendwikierroremail($errortwitter);
	}

}
else
{
	//get size of image array
	$sizeimagearray = sizeof($images);
	if($sizeimagearray >= 4 ){
		$numberofimages = 4;
	} else {
		$numberofimages = $sizeimagearray;
	}

	//get random indexes in array
	$randIndeximage = array_rand($images, $numberofimages);

//check if randIndex image returns an int or array (if only one item in array, array_rand returns an int)
if (is_int($randIndeximage) == true){
	echo "randIndeximage is an int because there is only one item in image array";
	$imagesurlarray = array();
	echo "<br>random image : ". $images[$randIndeximage]["title"];
	$imagerandom1 = $images[$randIndeximage]["title"];
	$myrandomimageurl = getwikiimagedetails($imagerandom1, 'url');
	array_push($imagesurlarray, $myrandomimageurl);
}
else if (is_array($randIndeximage)== true){
	//get URLs of random images in an array
	$imagesurlarray = array();

	foreach($randIndeximage as $k => $v){
		echo "<br>random image : ". $images[$v]["title"];
		$imagerandom1 = $images[$v]["title"];
		$myrandomimageurl = getwikiimagedetails($imagerandom1, 'url');
		array_push($imagesurlarray, $myrandomimageurl);
	}

	//print_r($imagesurlarray);
}



	//save images

	$it = 1;
	$imagestotweet = array();



/*
	$agent = $_SERVER['HTTP_USER_AGENT'];
	$options = array(
		'http'=>array(
		  'method'=>"GET",
		  'header'=>"Accept-language: en\r\n" .
					"User-Agent: ".$agent
		)
	  );
*/
$agent = $_SERVER['HTTP_USER_AGENT'];
$options = array(
  'http'=>array(
    'method'=>"GET",
    'header'=>"Accept-language: en\r\n" .
        "User-Agent: ".$agent
  )
  );


foreach($imagesurlarray as $k => $v){
  //get image extension
  $thisurl = $v;
  $path_parts = pathinfo($thisurl);
  $extension = $path_parts['extension'];

  //save image
  $imgtemp = 'images/hello' . $it . '.' . $extension;
  echo "<br>".$imgtemp;
  $context = stream_context_create($options);
  file_put_contents($imgtemp, file_get_contents($thisurl, false, $context));
  $it ++;
  array_push($imagestotweet, $imgtemp);
}

//upload images
$mediaarray = array();
foreach ($imagestotweet as $k => $v){
  $media1 = $connection->upload('media/upload', ['media' => $v]);
  $mediaID =  $media1->media_id_string;
  array_push($mediaarray, $mediaID);
}

$mediaids = implode(',', $mediaarray);

//post tweet and images
$parameters = ['status' => $mywikitweet, 'media_ids' => $mediaids ];
$result = $connection->post('statuses/update', $parameters);

if ($connection->getLastHttpCode() == 200)
{
  echo "<br>Tweet posted succesfully";
}
else
{
  echo "<br>tweet variable : ".$mywikitweet;
  echo "<br>media variable : ".$mediaids;
  echo "<br>error posting tweet";
  $errortwitter = $connection->getLastHttpCode();
  $othererror = $connection->getLastXHeaders();
  echo $errortwitter;
  echo "<br> other error : <br>";
  print_r($othererror);
  sendwikierroremail($errortwitter);
}
}

$time_elapsed_secs = microtime(true) - $start;
echo "<br>Time elapsed to run the code: " . $time_elapsed_secs;

  flush();
  ob_flush();
  sleep(2);
  exit(0);

?>

?>