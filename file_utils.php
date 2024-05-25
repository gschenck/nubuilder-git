<?php
namespace nuFileSystemSync;
function ensure_exists($folder) {
    if (!folder_exist($folder)) {
        mkdir($folder, 0777, true);
        chmod($folder, 0777);
    }
}


/**
 * Checks if a folder exist and return canonicalized absolute pathname (sort version)
 * @param string $folder the path being checked.
 * @return mixed returns the canonicalized absolute pathname on success otherwise FALSE is returned
 */
function folder_exist($folder)
{
    // Get canonicalized absolute pathname
    $path = realpath($folder);

    // If it exist, check if it's a directory
    return ($path !== false AND is_dir($path)) ? $path : false;
}

/**
 *  clean up folder recursively
 */
function empty_dir($src, $remove = false) {
    $dir = opendir($src);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            $full = $src . '/' . $file;
            if ( is_dir($full) ) {
                empty_dir($full, true);
            }
            else {
                unlink($full);
            }
        }
    }
    closedir($dir);
    if ($remove) rmdir($src);
}

/**
 * Merge several parts of URL or filesystem path in one path
 * Examples:
 *  echo merge_paths('stackoverflow.com', 'questions');           // 'stackoverflow.com/questions' (slash added between parts)
 *  echo merge_paths('usr/bin/', '/perl/');                       // 'usr/bin/perl/' (double slashes are removed)
 *  echo merge_paths('en.wikipedia.org/', '/wiki', ' Sega_32X');  // 'en.wikipedia.org/wiki/Sega_32X' (accidental space fixed)
 *  echo merge_paths('etc/apache/', '', '/php');                  // 'etc/apache/php' (empty path element is removed)
 *  echo merge_paths('/', '/webapp/api');                         // '/webapp/api' slash is preserved at the beginnnig
 *  echo merge_paths('http://google.com', '/', '/');              // 'http://google.com/' slash is preserved at the end
 * @param string $path1
 * @param string $path2
 */
function merge_paths($path1, $path2){
    $paths = func_get_args();
    $last_key = func_num_args() - 1;
    array_walk($paths, function(&$val, $key) use ($last_key) {
        switch ($key) {
            case 0:
                $val = rtrim($val, '/ ');
                break;
            case $last_key:
                $val = ltrim($val, '/ ');
                break;
            default:
                $val = trim($val, '/ ');
                break;
        }
    });
    $first = array_shift($paths);
    $last = array_pop($paths);
    $paths = array_filter($paths); // clean empty elements to prevent double slashes
    array_unshift($paths, $first);
    $paths[] = $last;
    return implode('/', $paths);
}