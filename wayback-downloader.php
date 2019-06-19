<?php

define('API_URL', 'http://web.archive.org/cdx/search/cdx?url=%s&matchType=host&fl=timestamp,original,urlkey&filter=statuscode:200&from=%s&to=%s');
define('DOWNLOAD_URL', 'http://web.archive.org/web/%sid_/%s');
define('USER_AGENT', 'WayBack PHP Downloader');
define('CURL_TIMEOUT', 3660);

if (!extension_loaded('curl')) {
    fputs(STDERR, 'CURL extension not loaded ...' . PHP_EOL);
    exit();
}

$curl = curl_init();

function curlRequest($url)
{
    global $curl;
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_TIMEOUT, CURL_TIMEOUT);
    curl_setopt($curl, CURLOPT_USERAGENT, USER_AGENT);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_FORBID_REUSE, false);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_URL, $url);

    $result = curl_exec($curl);
    if (false === $result) {
        throw new Exception('CURL Error: ' . curl_error($curl));
    }
    return $result;
}

function createFolderIfNotExist($path)
{
    if (file_exists($path)) {
        if (!is_writable($path)) {
            throw new Exception(sprintf('Folder "%s" is not writable ...', $path));
        }
    } else {
        if (false === mkdir($path, 0777, true)) {
            throw new Exception(sprintf('Cannot create folder "%s" ...', $path));
        }
    }
}

function createSubFolder($folder, $host)
{
    if (!file_exists($folder . '\\' . $host)) {
		mkdir($folder . '\\' . $host, 0777, true);
	}
}

function getUrl($host) {
	$parse = parse_url($host);
	return $parse['host'];	
}

$indexHandle = null;

try {
	
    // Checking command-line arguments
    if (2 > $argc) {
        throw new Exception('<folder> was not specified.');
    } elseif (3 > $argc) {
        throw new Exception('<host> was not specified.');
    }

    $folder = $argv[1];

    $host = $argv[2];

    $datetimeFrom = (4 <= $argc)
        ? $argv[3]
        : null;

    $datetimeTo = (5 === $argc)
        ? $argv[4]
        : null;
		
	// Use the domain as a sub folder
    $subFolderToCreate = getUrl($host);	

    if (!filter_var($host, FILTER_VALIDATE_URL)) {
        throw new Exception('<host> is not a valid URL.');
    }

    if ($datetimeFrom && !ctype_digit($datetimeFrom)) {
        throw new Exception('<datetime-from> is not a correct datetime value.');
    }

    if ($datetimeTo && !ctype_digit($datetimeTo)) {
        throw new Exception('<datetime-to> is not a correct datetime value.');
    }

    // Checking folder
    createFolderIfNotExist($folder);
	createSubFolder($folder, $subFolderToCreate);

    // Downloading index
    $url = sprintf(API_URL, $host, $datetimeFrom, $datetimeTo);

    fputs(STDERR, 'Downloading index ...');
    $result = curlRequest($url);
    fputs(STDERR, ' Completed.' . PHP_EOL);

    // Writing index file
    if (false === file_put_contents($folder . '\\' . $subFolderToCreate . DIRECTORY_SEPARATOR . 'index', $result)) {
        throw new Exception('Cannot save index file ...');
    }

    // Reading index file
    $indexHandle = fopen($folder . '\\' . $subFolderToCreate . DIRECTORY_SEPARATOR . 'index', 'r');
    if (!$indexHandle) {
        throw new Exception('Cannot read index file ...');
    }

    // Process index
    while ($indexLine = fgets($indexHandle)) {
        list($timestamp, $original, $key) = explode(' ', $indexLine);

        $parts = explode(')', $key);
        $urlKey = trim($parts[count($parts) - 1]);
        if ($urlKey == '/') {
            $urlKey = '/index.html';
        } else {
            //$urlKey .= '.html';
			//$urlKey .= $urlKey;
        }

        try {
            $contents = curlRequest(sprintf(DOWNLOAD_URL, $timestamp, $original));
            $path = $folder . '\\' . $subFolderToCreate . DIRECTORY_SEPARATOR . preg_replace('/^_/', '/', preg_replace('/\//', '_', $urlKey));
            if (!file_put_contents($path, $contents)) {
                fputs(STDERR, 'ERROR: Can\'t process ' . $original . PHP_EOL);
            } else {
                fputs(STDERR, $original . PHP_EOL);
            }
        } catch (Exception $e) {
            fputs(STDERR, $e->getMessage() . PHP_EOL);
        }
    }
} catch (Exception $e) {
    fputs(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    fputs(STDERR, sprintf('USAGE: php %s <folder> <host> [<datetime-from>] [<datetime-to>]', $argv[0]) . PHP_EOL);
    fputs(STDERR, 'datetime format is YYYYMMDD[HHMMSS]' . PHP_EOL);
}

if ($indexHandle) {
    fclose($indexHandle);
}

