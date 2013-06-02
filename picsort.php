<?
/**
 * Photo sorter that organizes files into a chronological date structure
 # appropriately with events, based on the EXIF data attached to the photo file.
 * 
 * The goal at the end of the day is to have the ability to connect your camera,
 * copy the latest pictures from your memory card into a directory, and run this
 * script on that directory. From there, it should be very efficient to delete
 * the unwanted photos from within Picasa.
 * 
 * This script should be intelligent about 'events' by keeping note of
 * the date of the last picture that was scanned. That would however assume
 * that we are always going to get the next picture in the set, which is most
 * likely true the majority of the time.
 *
 * @todo
 * This would be best done in a database where we store the first and last
 # picture seen in any set. Then upon scanning each new picture, we
 * would be able to tell if that fit in a time range of another set and
 * suggest it. That would be incredibly useful for obtaining pictures from
 * other people's cameras and adding to your already existing events!
 *
 * @todo
 * - Sort video files by timestamp instead of EXIM data (prompt for Event name)
 * - Warn if previous directory already exists
*/


# Your picture directories
$unsorted_dir = getcwd(); # Could be a path...
$archive_dir = '/space/Pictures/Archive/';

# Archive structure: $archive_dir/$archive_structure
# Assume array key names from the date_parse() method
$archive_structure = 'year/month';

# Event length breakpoint since last picture - default 2 hours
$event_length = (60 * 60 * 2);

# Supported file types - case insensitive
$image_files = array('jpg', 'jpeg', 'gif');
$video_files = array('mov', 'mpg', '3g2', 'mp4', '3gp', 'mts');
$supported_files = array_merge($image_files, $video_files);


# Some quick OCD sanity checks
if ( ! preg_match("|\/$|", $unsorted_dir)) {
	$unsorted_dir = $unsorted_dir . '/';
}
if (preg_match("|\/$|", $archive_dir)) {
	$archive_dir = substr($archive_dir, 0, -1);
}

# A few globals defined for iteration purposes
$last_successful = array(
	'cwd' => '',
	'event_name' => '',
	'archive_location' => '',
	'photo_timestamp' => '',
);

# Recursively go through the Pictures folder and run the callback on every file
dir_walk('sort_file', $unsorted_dir, $supported_files, TRUE, $unsorted_dir);





function sort_file($file) {
	global $image_files;
	global $video_files;
	global $archive_dir;
	global $event_length;
	global $last_successful;
	$pretty_date = '';
	
	$filename = array_pop(preg_split('|/|', $file));
	
	if ( ! is_file($file)) {
		echo "[01;31m!!! $file not a file.[01;0m\n";
		return FALSE;
	} elseif (preg_match('/(' . implode('|', $image_files) . ')/i', $file)) {
		$exif = exif_read_data($file, 0, false);
		
		if ( ! ($exif || is_array($exif)) ) {
			echo "[01;31m!!! $file has no EXIF data.[01;0m\n";
			return FALSE;
		}
		
		if ( ! (trim($exif['DateTime']) != "" && trim($exif['DateTimeOriginal']) != "") ) {
			echo "[01;31m!!! Could not find dates in EXIF data of $file[01;0m\n";
			return FALSE;
		}

		# @todo: Add logic here to use the file created date as a fallback (internet files etc)
	
		if (trim($exif['DateTime']) != "") {
			$picture_date = date_parse($exif['DateTime']);
		}
		if (trim($exif['DateTimeOriginal']) != "") {
			$picture_date = date_parse($exif['DateTimeOriginal']);
		}

		# How much time has passed since the last picture we saw?
		$photo_timestamp = mktime(
			$picture_date['hour'],
			$picture_date['minute'],
			$picture_date['second'],
			$picture_date['month'],
			$picture_date['day'],
			$picture_date['year']
		);

		if ($last_successful['photo_timestamp'] > 0) {
			$difference = ((int) $photo_timestamp - (int) $last_successful['photo_timestamp']);
			
			if ($difference >= $event_length) {
				echo "[01;31m!!! BEEN OVER TWO HOURS SINCE LAST PICTURE![01;0m\n";
			}
		}
		$last_successful['photo_timestamp'] = $photo_timestamp;

		$archive_date = archive_structure_date_string($picture_date);
		
		$pretty_date = "{$picture_date['year']}/{$picture_date['month']}/{$picture_date['day']} ";
		$pretty_date .= $picture_date['hour'] . ":";
		$pretty_date .= str_pad($picture_date['minute'], 2, '0') . ":";
		$pretty_date .= str_pad($picture_date['second'], 2, '0');
	} elseif (preg_match('/(' . implode('|', $video_files) . ')/', $file)) {
		# Use $last_successful for sorting the movie file UNLESS the cwd has changed since then
	}
	
	echo "\n---- {$pretty_date} {$file}\n";
	$event_name = get_event_name($file);

	# GO!
	if ( ! is_dir("{$archive_dir}/{$archive_date}/{$event_name}") ) {
		mkdir("{$archive_dir}/{$archive_date}/{$event_name}", 0755, TRUE) or die("FAIL: Permission denied?");
	}
	echo " -> {$archive_dir}/{$archive_date}/{$event_name}\n";
	rename($file, "{$archive_dir}/{$archive_date}/{$event_name}/{$filename}");
	
	return TRUE;
}


function get_event_name($loc) {
	global $last_successful;
	
	$loc_split = preg_split('|/|', $loc);
	if (preg_match('|\.|', $loc_split[ (count($loc_split) - 1) ])) {
		$loc = '';
		array_pop($loc_split);
		foreach ($loc_split as $dir) {
			$loc .= $dir . '/';
		}
		$loc = substr($loc, 0, -1);
	}
	
	$event_name = $loc_split[ (count($loc_split) - 1) ];
	
	if ($loc == $last_successful['archive_location']) {
		if ($last_successful['event_name'] != "" && $event_name != $last_successful['event_name']) {
			$event_name = $last_successful['event_name'];
		}
	}
	
	echo "{$event_name}> ";
	$event_name_input = trim(fgets(STDIN));
	if (trim($event_name_input) != "") {
		$event_name = $event_name_input;
	}
	
	$last_successful['archive_location'] = $loc;
	$last_successful['event_name'] = $event_name;
	
	return $event_name;
}


function archive_structure_date_string($date) {
	global $archive_structure;
	
	$date_string = '';
	$archive_split = split('/', $archive_structure);
	
	foreach ($archive_split as $element) {
		if ($date[$element] <= 9) {
			$date_string .= '/' . "0{$date[$element]}";
		} else {
			$date_string .= '/' . $date[$element] ;
		}
	}
	
	$date_string = substr($date_string, 1);
	
	return $date_string;
}


function dir_walk($callback, $dir, $types = null, $recursive = false, $baseDir = '')
{
    if ($dh = opendir($dir))
	{
		$items = array();
        while (($item = readdir($dh)) !== false)
		{
            if ($item === '.' || $item === '..')
			{
                continue;
            }

			array_push($items, $item);
		}
		sort($items);
        closedir($dh);

		foreach ($items as $item)
		{
            if (is_file($dir . $item))
			{
                if (is_array($types))
				{
                    if ( ! in_array(strtolower(pathinfo($dir . $item, PATHINFO_EXTENSION)), $types, true))
					{
                        continue;
                    }
                }

				$callback($baseDir . $item);
            }
			elseif($recursive && is_dir($dir . $item))
			{
                dir_walk($callback, $dir . $item . DIRECTORY_SEPARATOR, $types, $recursive, $baseDir . $item . DIRECTORY_SEPARATOR);
            }
        }
    }
}

