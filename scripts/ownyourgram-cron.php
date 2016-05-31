<?php
chdir(dirname(__FILE__).'/..');
require 'vendor/autoload.php';
require 'lib/Savant.php';
require 'lib/config.php';
require 'lib/helpers.php';
require 'lib/markdown.php';
require 'lib/instagram.php';

echo "========================================\n";
echo date('Y-m-d H:i:s') . "\n";

$users = ORM::for_table('users')
  ->where('micropub_success', 1)
  ->where_not_null('instagram_username')
  ->find_many();
foreach($users as $user) {

  echo "---------------------------------\n";
  echo $user->url . "\n";

  try {

    $feed = IG\get_user_photos($user->instagram_username);

    if($feed) {
      foreach($feed['items'] as $url) {

        // Check if this photo has already been imported
        $photo = ORM::for_table('photos')
          ->where('user_id', $user->id)
          ->where('instagram_url', $url)
          ->find_one();

        if(!$photo) {
          $photo = ORM::for_table('photos')->create();
          $photo->user_id = $user->id;
          $photo->instagram_url = $url;

          $entry = h_entry_from_photo($url);

          $photo->instagram_data = json_encode($entry);
          $photo->instagram_img = $entry['photo'];
          $photo->save();

          // Post to the Micropub endpoint
          $filename = download_file($entry['photo']);

          if(isset($entry['video'])) {
            $video_filename = download_file($entry['video']);
          } else {
            $video_filename = false;
          }

          echo date('Y-m-d H:i:s ')."Sending ".($video_filename ? 'video' : 'photo')." ".$url." to micropub endpoint: ".$user->micropub_endpoint."\n";

          // Collapse category to a comma-separated list if they haven't upgraded yet
          if($user->send_category_as_array != 1) {
            if($entry['category'] && is_array($entry['category']) && count($entry['category'])) {
              $entry['category'] = implode(',', $entry['category']);
            }
          }

          $response = micropub_post($user->micropub_endpoint, $user->micropub_access_token, $entry, $filename, $video_filename);
          unlink($filename);

          $user->last_micropub_response = json_encode($response);
          $user->last_instagram_photo = $photo->id;
          $user->last_photo_date = date('Y-m-d H:i:s');

          if($response && preg_match('/Location: (.+)/', $response['response'], $match)) {
            $user->last_micropub_url = $match[1];
            $user->last_instagram_img_url = $url;
            $user->photo_count = $user->photo_count + 1;
            $user->photo_count_this_week = $user->photo_count_this_week + 1;
            echo date('Y-m-d H:i:s ')."Posted to ".$match[1]."\n";
          } else {
            // Their micropub endpoint didn't return a location, notify them there's a problem somehow
            echo date('Y-m-d H:i:s ')."This user's endpoint did not return a location header\n";
          }

          $user->save();

        }

      }
    } else {
      echo date('Y-m-d H:i:s ')."Error retrieving user's Instagram feed\n";
    }

  } catch(Exception $e) {
    echo date('Y-m-d H:i:s ')."Error processing user\n";
  }
}



