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
 * However, this would be best done in a database where we store the first
 * and last picture seen in any set. Then upon scanning each new picture, we
 * would be able to tell if that fit in a time range of another set and
 * suggest it. That would be incredibly useful for obtaining pictures from
 * other people's cameras and adding to your already existing events!
*/

# Your picture directories
$unsorted_dir = getcwd(); // Could be a path...
$archive_dir = '/space/Pictures/Archive/';

# Archive structure: $archive_dir/ARCHIVE_STRUCTURE
# Assume array key names from the date_parse() method
define("ARCHIVE_STRUCTURE", 'year/month');

# Event length
define("EVENT_LENGTH", (60 * 60 * 2));

# An image's date should line up with an event name plus or minus this amount
# Should use a working value in strtotime, sans the +/-
define("EVENT_RANGE", "8 hours");

# Supported files
$supported_files = array('jpg', 'jpeg', 'gif');

# Database and table name
define("PICSORT_TABLE", 'e7i.picsort');

# Connect to MySQL
$db = mysql_connect("localhost", "picsort", "die38slow") or die(mysql_error());


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
dir_walk('sort_photo_file', $unsorted_dir, $supported_files, TRUE, $unsorted_dir);





/**
 * Performs exif_read_data() on a given file to determine the date that the
 * picture was taken. From there, it will sort it into the archive directory
 * given the structure you have defined above.
 * 
 * @return bool
 */
function sort_photo_file($file)
{
	global $db;
	global $archive_dir;
	global $iterate;
	
	// Sanity checks to ensure it's a file
	if ( ! is_file($file))
	{
		echo "!!! $file not a file.\n";
		return FALSE;
	}
	// Sanity checks to ensure we see some EXIF data - otherwise who knows what this is?
	else
	{
		$exif = exif_read_data($file, 0, false);
		
		if ( ! ($exif || is_array($exif)) )
		{
			echo "!!! Could not find dates in EXIF data of $file\n";
			return FALSE;
		}
		
		if ( ! (trim($exif['DateTime']) != "" && trim($exif['DateTimeOriginal']) != "") )
		{
			echo "!!! Could not find dates in EXIF data of $file\n";
			return FALSE;
		}
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
		
		if ($difference >= EVENT_LENGTH)
		{
			echo "!!! NEW EVENT: OVER TWO HOURS SINCE LAST PICTURE!\n";
		}
	}
	$iterate['last_photo_timestamp'] = $photo_timestamp;
	
	// Check the database to see if there's an existing event
	$event = fetch_picsort_event($photo_timestamp);
	
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
	
	// Update the event in the database
	update_picsort_event_range($event_name, $photo_timestamp);
	
	return TRUE;
}

/**
 * Queries the picsort database to see if this photo's timestamp coincides with
 * an event we have already created in the database.
 */
function fetch_picsort_event($timestamp)
{
	global $db;

	// Check the database to see if there's an existing event
	$event_qry = mysql_query("
		SELECT id, begin, end, name
		FROM " . PICSORT_TABLE . "
		WHERE 1
			AND begin >= FROM_UNIXTIME(" . $timestamp . ")
			AND end <= FROM_UNIXTIME(" . $timestamp . ")
	", $db);
	
	// Fetch the possibly existing event
	$event = FALSE;
	if ($event_qry && mysql_num_rows($event_qry) > 0)
	{
		$event = mysql_fetch_assoc($event_qry);
	}
	
	return ($event);
}

/**
 * Updates the picsort event in the database if the begin or end dates are
 * a more broad range than what we previously had. Typically, this will update
 * the end date as you sort additional pictures into an event.
 */
function update_picsort_event_range($event, $timestamp)
{
	global $db;

	// Sanity checks
	if ( ! is_array($event) && $timestamp > 0)
	{
		return FALSE;
	}

	// Check the database to see if there's an existing event
	$event_qry = mysql_query("
		SELECT id, begin, end, name
		FROM " . PICSORT_TABLE . "
		WHERE 1
			AND name = '" . mysql_real_escape_string($event['name']) . "'
		LIMIT 1
	", $db);
	
	// Fetch the possibly existing event
	$event = FALSE;
	if ($event_qry && mysql_num_rows($event_qry) > 0)
	{
		$event = mysql_fetch_assoc($event_qry);
	}
	
	return TRUE;
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
 * Parses the ARCHIVE_STRUCTURE config parameter and returns a structure
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
	$date_string = '';
	$archive_split = split('/', ARCHIVE_STRUCTURE);
	
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

