<?php

define('FILE_ENCRYPTION_BLOCKS', 10000);

function hasValue($input)
{
	$strTemp = trim($input);
	if($strTemp !== '')
	{
		return true;
	}
	return false;
}

function encrypt_decrypt_short_string($key, $filename)
{
	$encrypted = "";
	$len_filename = strlen($filename);
	$len_key = strlen($key);
	for($i = 0; $i < $len_filename;)
	{
		for($j = 0; $j < $len_key; $j++, $i++)
		{
			$encrypted .= $filename{$i} ^ $key{$j};
		}
	}
	return $encrypted;
}

/**
 * Decrypt the passed file and saves the result in a new file, removing the
 * last 4 characters from file name.
 * 
 * @param string $source Path to file that should be decrypted
 * @param string $key    The key used for the decryption (must be the same as for encryption)
 * @param string $dest   File name where the decryped file should be written to.
 * @return string|false  Returns the file name that has been created or FALSE if an error occured
 */
function decryptFile($source, $key, $dest)
{
	$key = substr(sha1($key, true), 0, 16);

	$error = false;
	if ($fpOut = fopen($dest, 'w'))
	{
		if ($fpIn = fopen($source, 'rb'))
		{
		// Get the initialzation vector from the beginning of the file
		$iv = fread($fpIn, 16);
		while (!feof($fpIn)) {
		$ciphertext = fread($fpIn, 16 * (FILE_ENCRYPTION_BLOCKS + 1)); // we have to read one block more for decrypting than for encrypting
		$plaintext = openssl_decrypt($ciphertext, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
		// Use the first 16 bytes of the ciphertext as the next initialization vector
		$iv = substr($ciphertext, 0, 16);
		fwrite($fpOut, $plaintext);
	}
	fclose($fpIn);
	}
	else
	{
		$error = true;
	}
	fclose($fpOut);
	}
	else
	{
		$error = true;
	}

	return $error ? false : $dest;
}

$encrypted_filename = $_GET['file'];
$key = $_GET['x'];
$password = $_GET['password'];

if(time() - intval($key) > 86400)
{
	echo "Sorry, you are no longer have access to this file or it has been removed. Please contact the file owner.<br/><br/>";
	exit;
}

$uploaded_filename = encrypt_decrypt_short_string($key, urldecode($encrypted_filename));
$full_filename = '';
if(hasValue($password))
{
	$full_filename = $password . '_' . $uploaded_filename;
}
else
{
	$full_filename = '_' . $uploaded_filename;
}

$path = '/var/www/html/uploads/';
$file_path = $path . $full_filename;

if(!file_exists($file_path))
{
	echo "Sorry, you are no longer have access to this file or it has been removed. Please contact the file owner.<br/><br/>";
	exit;
}

$unencrypted_tmp_file = $file_path . ".tmp";
if(!decryptFile($file_path, $key, $unencrypted_tmp_file))
{
	echo "There was an error decrypting your file on the server. Please contact the file owner.<br/><br/>";
	exit;
}
else
{
	$fp = fopen($unencrypted_tmp_file, 'rb');
	header("Content-Type: application/octet-stream");
	header("Content-Transfer-Encoding: Binary");
	header("Content-disposition: attachment; filename=\"" . $uploaded_filename . "\""); 
	fpassthru($fp);
	fclose($fp);
	unlink($unencrypted_tmp_file);
	exit;
}
?>
