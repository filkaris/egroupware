<?php
	/**************************************************************************\
	* phpGroupWare                                                             *
	* http://www.phpgroupware.org                                              *
	* The file written by Miles Lott <milosch@phpgroupware.org>                *
	* --------------------------------------------                             *
	*  This program is free software; you can redistribute it and/or modify it *
	*  under the terms of the GNU General Public License as published by the   *
	*  Free Software Foundation; either version 2 of the License, or (at your  *
	*  option) any later version.                                              *
	\**************************************************************************/

	/* $Id$ */

	/**************************************************************************\
	* These are the few functions needed for parsing the inline comments       *
	\**************************************************************************/

	/*!
	 @function array_print
	 @abstract output an array for HTML.
	 @syntax array_print($array);
	 @example array_print($my_array);
	*/
	function array_print($array)
	{
		if(floor(phpversion()) == 4)
		{
			ob_start(); 
			echo '<pre>'; print_r($array); echo '</pre>';
			$contents = ob_get_contents(); 
			ob_end_clean();
			echo $contents;
		}
		else
		{
			echo '<pre>'; var_dump($array); echo '</pre>';
		}
	}

	/*!
	 @function parseobject
	 @abstract Parses inline comments for a single function
	 @author seek3r
	 @syntax parseobject($input);
	 @example $return_data = parseobject($doc_data);
	*/
	function parseobject($input)
	{
		$types = array('abstract','param','example','syntax','result','description','discussion','author','copyright','package','access');
		$new = explode("@",$input);
		while (list($x,$y) = each($new))
		{
			if (!isset($object) || trim($new[0]) == $object)
			{
				$t = trim($new[0]);
				$t = trim(ereg_replace('#'.'function'.' ','',$t));
				reset($types);
				while(list($z,$type) = each($types))
				{
					if(ereg('#'.$type.' ',$y))
					{
						$xkey = $type;
						$out = $y;
						$out = trim(ereg_replace('#'.$type.' ','',$out));
						break;
					}
					else
					{
						$xkey = 'unknown';
						$out = $y;
					}
				}
				if($out != $new[0])
				{
					$output[$t][$xkey][] = $out;
				}
			}
		}
		
		if ($GLOBALS['object_type'].' '.$GLOBALS['HTTP_GET_VARS']['object'] == $t)
		{
			$GLOBALS['special_request'] = $output[$t];
		}
		return Array('name' => $t, 'value' => $output[$t]);
	}

	/*!
	 @function parsesimpleobject
	 @abstract Parses inline comments for a single function, in a more limited fashion
	 @author seek3r
	 @syntax parsesimpleobject($input);
	 @example $return_data = parsesimpleobject($simple_doc_data);
	*/
	function parsesimpleobject($input)
	{
		
		$types = array('abstract','param','example','syntax','result','description','discussion','author','copyright','package','access');
		$input = ereg_replace ("@", "@#", $input);
		$new = explode("@",$input);
		if (count($new) < 3)
		{
			return False;
		}
		unset ($new[0], $new[1]);
		while (list($x,$y) = each($new))
		{
			if (!isset($object) || trim($new[0]) == $object)
			{
				$t = trim($new[0]);
				reset($types);
				while(list($z,$type) = each($types))
				{
					if(ereg('#'.$type.' ',$y))
					{
						$xkey = $type;
						$out = $y;
						$out = trim(ereg_replace('#'.$type.' ','',$out));
						break;
					}
					else
					{
						$xkey = 'unknown';
						$out = $y;
					}
				}
				if($out != $new[0])
				{
					$output[$t][$xkey][] = $out;
				}
			}
		}
		if ($GLOBALS['object_type'].' '.$GLOBALS['HTTP_GET_VARS']['object'] == $t)
		{
			$GLOBALS['special_request'] = $output[$t];
		}
		return Array('name' => $t, 'value' => $output[$t]);
	}

	/**************************************************************************\
	* This section handles processing most of the input params for             *
	* limiting and selecting what to print                                     *
	\**************************************************************************/

	include ('../phpgwapi/inc/class.Template.inc.php');

	if (!isset($GLOBALS['HTTP_GET_VARS']['object_type']))
	{
		$GLOBALS['object_type'] = 'function';
	}
	else
	{
		$GLOBALS['object_type'] = $GLOBALS['HTTP_GET_VARS']['object_type'];
	}
	
	$app = $GLOBALS['HTTP_GET_VARS']['app'];
	$fn  = $GLOBALS['HTTP_GET_VARS']['fn'];

	if($app)
	{
		if (!preg_match("/^[a-zA-Z0-9-_]+$/i",$app))
		{
			echo 'Invalid application<br>';
			exit;
		}
	}
	else
	{
		$app = 'phpgwapi';
	}

	if ($fn)
	{
		if (preg_match("/^class\.([a-zA-Z0-9-_]*)\.inc\.php+$/",$fn) || preg_match("/^functions\.inc\.php+$/",$fn) || preg_match("/^xml_functions\.inc\.php+$/",$fn))
		{
			$files[] = $fn;
		}
		else
		{
			echo 'No valid file selected';
			exit;
		}
	}
	else
	{
		$d = dir('../'.$app.'/inc/');
		while ($x = $d->read())
		{
			if (preg_match("/^class\.([a-zA-Z0-9-_]*)\.inc\.php+$/",$x) || preg_match("/^functions\.inc\.php+$/",$x))
			{
				$files[] = $x;
			}
		}
		$d->close;

		sort($files);
	}

	/**************************************************************************\
	* Now that I have the list of files, I loop thru all of them and get the   *
	* inline comments from them and load each of them into an array            *
	\**************************************************************************/ 

	while (list($p,$fn) = each($files))
	{
		$matches = $elements = $data = $startstop = array();
		$string = $t = $out = $xkey = $new = '';
		$file = '../'.$app.'/inc/' . $fn;
//		echo 'Looking at: ' . $file . "<br>\n";
		$f = fopen($file,'r');
		while (!feof($f))
		{
			$string .= fgets($f,8000);
		}
		fclose($f);

		preg_match_all("#\*\!(.*)\*/#sUi",$string,$matches,PREG_SET_ORDER);

		/**************************************************************************\
		* Now that I have the list of found inline docs, I need to figure out      *
		* which group they belong to.                                              *
		\**************************************************************************/ 
		$idx = 0;
		$ssmatches = $matches;
		reset($ssmatches);
		while (list($sskey,$ssval) = each($ssmatches))
		{
			if (preg_match ("/@class_start/i", $ssval[1]))
			{
				$ssval[1] = ereg_replace ("@", "@#", $ssval[1]);
				$ssval[1] = explode("@",$ssval[1]);
				$ssresult = trim(ereg_replace ("#class_start", "", $ssval[1][1]));
				$sstype = 'class';
				unset($matches[$idx][1][0], $matches[$idx][1][1]);
				$matches_starts[$sstype.' '.$ssresult] = $matches[$idx][1];
				unset($matches[$idx]);
			}
			elseif (preg_match ("/@class_end $ssresult/i", $ssval[1]))
			{
				unset($ssresult);
				unset($matches[$idx]);
			}
			elseif (preg_match ("/@collection_start/i", $ssval[1]))
			{
				$ssval[1] = ereg_replace ("@", "@#", $ssval[1]);
				$ssval[1] = explode("@",$ssval[1]);
				$ssresult = trim(ereg_replace ("#collection_start", "", $ssval[1][1]));
				$sstype = 'collection';
				unset($matches[$idx][1][0], $matches[$idx][1][1]);
				$matches_starts[$sstype.' '.$ssresult] = $matches[$idx][1];
				unset($matches[$idx]);
			}
			elseif (preg_match ("/@collection_end $ssresult/i", $ssval[1]))
			{
				unset($ssresult);
				unset($matches[$idx]);
			}
			else
			{
				if (isset($ssresult))
				{
					$startstop[$idx] = $sstype.' '.$ssresult;
				}
				else
				{
					$startstop[$idx] = 'some_lame_string_that_wont_be_used_by_a_function';
				}
			}
			$idx = $idx + 1;
		}
		unset($ssmatches, $sskey, $ssval, $ssresult, $sstype, $idx);
		reset($startstop);
		
		/**************************************************************************\
		* Now that I have the list groups and which records belong in which groups *
		* its time to parse each function and stick it under the appropriate group *
		* if there is no defined group for a function, then it gets tossed under   *
		* a special group named by the file it was found in                        *
		\**************************************************************************/ 
		while (list($key,$val) = each($matches))
		{
			preg_match_all("#@(.*)$#sUi",$val[1],$data);
			$data[1][0] = ereg_replace ("@", "@#", $data[1][0]);
			$returndata = parseobject($data[1][0], $fn);
			if ($startstop[$key] == 'some_lame_string_that_wont_be_used_by_a_function')
			{
				$doc_array['file '.$fn][0]['files'][] = $fn;
				$doc_array['file '.$fn][0]['files'] = array_unique($doc_array['file '.$fn][0]['files']);
				$doc_array['file '.$fn][$returndata['name']] = $returndata['value'];
			}
			else
			{
				if (!isset($doc_array[$startstop[$key]][0]) && isset($matches_starts[$startstop[$key]]))
				{
					$returndoc = parsesimpleobject($matches_starts[$startstop[$key]]);
					if ($returndoc != False)
					{
						$returndoc['value']['files'][] = $fn;
						$returndoc['value']['files'] = array_unique($returndoc['value']['files']);
					}
					$doc_array[$startstop[$key]][0] = $returndoc['value'];
				}
				else
				{
					$doc_array[$startstop[$key]][0]['files'][] = $fn;
					$doc_array[$startstop[$key]][0]['files'] = array_unique($doc_array[$startstop[$key]][0]['files']);
				}
				$doc_array[$startstop[$key]][$returndata['name']] = $returndata['value'];
			}
		}

	}
	if(isset($GLOBALS['HTTP_GET_VARS']['object']))
	{
		$doc_array = Array($GLOBALS['HTTP_GET_VARS']['object'] => $GLOBALS['special_request']);
	}
	array_print($doc_array);
?>
