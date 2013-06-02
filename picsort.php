<?
/**
 * Photo scanner that organizes files into a chronological date structure based
 * on the EXIF data attached to the photo file.
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
 * However, this would be best done in a database where we store the first
 * and last picture seen in any set. Then upon scanning each new picture, we
 * would be able to tell if that fit in a time range of another set and
 * suggest it. That would be incredibly useful for obtaining pictures from
 * other people's cameras and adding to your already existing events!
*/

# Your picture directories
$unsorted_dir = getcwd(); // Could be a path...
$archive_dir = '/space/Pictures/Archive/';

# Archive structure: $archive_dir/$archive_structure
# Assume array key names from the date_parse() method
$archive_structure = 'year/month';

# Event length - default 2 hours
$event_length = (60 * 60 * 2);

# Supported file types
$image_files = array('jpg', 'jpeg', 'gif');
$video_files = array('mov', 'mpg', '3g2', 'mp4', '3gp', 'mts');
$supported_files = array_merge($image_files, $video_files);


# Some quick OCD sanity checks
if ( ! preg_match("|\/$|", $unsorted_dir))
{
	$unsorted_dir = $unsorted_dir . '/';
}
if (preg_match("|\/$|", $archive_dir))
{
	$archive_dir = substr($archive_dir, 0, -1);
}

# A few globals defined for iteration purposes
$iterate = array
(
	'previous_event_name' => '',
	'previous_sort_photo_loc' => '',
	'last_photo_timestamp' => '',
);

# Recursively go through the Pictures folder and run the callback on every file
dir_walk('sort_file', $unsorted_dir, $supported_files, TRUE, $unsorted_dir);





/**
 * Performs exif_read_data() on a given file to determine the date that the
 * picture was taken.
 *
 * If it's not an image file type, it will attempt to guess the file date based
 * on time and ultimately fall back to file creation date.
 *
 * From there, it will sort it into the archive directory
 * given the structure you have defined above.
 * 
 * @return bool
 */
function sort_file($file)
{
	global $archive_dir;
	global $event_length;
	global $iterate;
	
	// Sanity checks
	if ( ! is_file($file))
	{
		echo "[01;31m!!! $file not a file.[01;0m\n";
		return FALSE;
	}
	else
	{
		$exif = exif_read_data($file, 0, false);
		
		if ( ! ($exif || is_array($exif)) )
		{
			return FALSE;
		}
		
		if ( ! (trim($exif['DateTime']) != "" && trim($exif['DateTimeOriginal']) != "") )
		{
			echo "[01;31m!!! Could not find dates in EXIF data of $file[01;0m\n";
			return FALSE;
		}

		// Should have some logic here to use the file created date as a fallback (internet files etc)
	}
	
	// Parse out the filename since we'll need it later
	$file_split = preg_split('|/|', $file);
	$filename = array_pop($file_split);
	
	// Use EXIF to get the date of the picture
	if (trim($exif['DateTime']) != "")
	{
		$picture_date = date_parse($exif['DateTime']);
	}
	if (trim($exif['DateTimeOriginal']) != "")
	{
		$picture_date = date_parse($exif['DateTimeOriginal']);
	}
	
	// Find out how much time has passed since the last picture we saw
	$photo_timestamp = mktime
	(
		$picture_date['hour'],
		$picture_date['minute'],
		$picture_date['second'],
		$picture_date['month'],
		$picture_date['day'],
		$picture_date['year']
	);
	
	// Has it been too long since the last picture we sorted?
	if ($iterate['last_photo_timestamp'] > 0)
	{
		$difference = ((int) $photo_timestamp - (int) $iterate['last_photo_timestamp']);
		
		if ($difference >= $event_length)
		{
			echo "[01;31m!!! BEEN OVER TWO HOURS SINCE LAST PICTURE![01;0m\n";
		}
	}
	$iterate['last_photo_timestamp'] = $photo_timestamp;
	
	// Get the archive directory name based on the picture date
	$archive_date = archive_structure_date_string($picture_date);
	
	// Get the Event Name
	$pretty_date = "{$picture_date['year']}/{$picture_date['month']}/{$picture_date['day']} ";
	$pretty_date .= $picture_date['hour'] . ":";
	$pretty_date .= str_pad($picture_date['minute'], 2, '0') . ":";
	$pretty_date .= str_pad($picture_date['second'], 2, '0');
	echo "\n---- {$pretty_date} {$file}\n";
	$event_name = get_event_name($file);

	// Let's make it happen!
	echo " -> {$archive_dir}/{$archive_date}/{$event_name}\n";
	
	if ( ! is_dir("{$archive_dir}/{$archive_date}/{$event_name}") )
	{
		mkdir("{$archive_dir}/{$archive_date}/{$event_name}", 0755, TRUE) or die("FAIL: Permission denied?");
	}
	rename($file, "{$archive_dir}/{$archive_date}/{$event_name}/{$filename}");
	
	return TRUE;
}


/**
 * Determines an Event Name from either the dir/file as a suggestion, or
 * by prompting the user. Assumes the suggested Event Name as a default
 * in the prompt.
 * 
 * @param string $loc Location we can use to suggest an Event Name
 * @return string $event_name Event Name
 */
function get_event_name($loc)
{
	global $iterate;
	
	// Parse out the full path from $loc if given a filename
	$loc_split = preg_split('|/|', $loc);
	if (preg_match('|\.|', $loc_split[ (count($loc_split) - 1) ]))
	{
		$loc = '';
		array_pop($loc_split);
		foreach ($loc_split as $dir)
		{
			$loc .= $dir . '/';
		}
		$loc = substr($loc, 0, -1);
	}
	
	// Guess the Event Name (we'll prompt as well)
	$event_name = $loc_split[ (count($loc_split) - 1) ];
	
	// Make an educated suggestion for the Event Name
	if ($loc == $iterate['previous_sort_photo_loc'])
	{
		if ($iterate['previous_event_name'] != "" && $event_name != $iterate['previous_event_name'])
		{
			$event_name = $iterate['previous_event_name'];
		}
	}
	
	// Now prompt the user for confirmation
	echo "{$event_name}> ";
	$event_name_input = trim(fgets(STDIN));
	if (trim($event_name_input) != "")
	{
		$event_name = $event_name_input;
	}
	
	// Set my global iterations so we know where we were for guessing purposes
	$iterate['previous_sort_photo_loc'] = $loc;
	$iterate['previous_event_name'] = $event_name;
	
	return $event_name;
}


/**
 * Parses the $archive_structure config parameter and returns a structure
 * according to its configuration based on the $date array that it is
 * passed. Always prefixes <= 9.
 * 
 * The $date array can be easily parsed from the EXIF data of a photo with
 * the date_parse() method.
 * 
 * @param array $date Date array following the format from date_parse()
 * @return string $
 */
function archive_structure_date_string($date)
{
	global $archive_structure;
	
	$date_string = '';
	$archive_split = split('/', $archive_structure);
	
	foreach ($archive_split as $element)
	{
		if ($date[$element] <= 9)
		{
			$date_string .= '/' . "0{$date[$element]}";
		}
		else
		{
			$date_string .= '/' . $date[$element] ;
		}
	}
	
	$date_string = substr($date_string, 1);
	
	return $date_string;
}


/**
 * Calls a function for every file in a folder.
 *
 * @author Vasil Rangelov a.k.a. boen_robot - Modified by Michael to add sorting
 *
 * @param string $callback The function to call. It must accept one argument that is a relative filepath of the file.
 * @param string $dir The directory to traverse.
 * @param array $types The file types to call the function for. Leave as NULL to match all types.
 * @param bool $recursive Whether to list subfolders as well.
 * @param string $baseDir String to append at the beginning of every filepath that the callback will receive.
 */
function dir_walk($callback, $dir, $types = null, $recursive = false, $baseDir = '')
{
	// Traverse the directory
    if ($dh = opendir($dir))
	{
		// Sort everything alphabetically
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

