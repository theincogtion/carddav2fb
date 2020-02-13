<?php

namespace Andig;

use Andig\CardDav\Backend;
use Andig\CardDav\VcardFile;
use Andig\FritzBox\Converter;
use Andig\FritzBox\Api;
use Andig\FritzBox\BackgroundImage;
use Andig\FritzBox\Restorer;
use Sabre\VObject\Document;
use \SimpleXMLElement;
use Andig\FritzAdr\fritzadr;
use Andig\ReplyMail\replymail;

// see: https://avm.de/service/fritzbox/fritzbox-7490/wissensdatenbank/publication/show/300_Hintergrund-und-Anruferbilder-in-FRITZ-Fon-einrichten/
define("MAX_IMAGE_COUNT", 150);

/**
 * Initialize backend from configuration
 *
 * @param array $config
 * @return Backend
 */
function backendProvider(array $config): Backend
{
    $options = $config['server'] ?? $config;

    $backend = new Backend($options['url']);
    $backend->setAuth($options['user'], $options['password']);
    $backend->mergeClientOptions($options['http'] ?? []);

    return $backend;
}

function localProvider($fullpath)
{
    $local = new VcardFile($fullpath);
    return $local;
}

/**
 * Download vcards from CardDAV server
 *
 * @param Backend $backend
 * @param callable $callback
 * @return Document[]
 */
function download(Backend $backend, callable $callback=null): array
{
    $backend->setProgress($callback);
    return $backend->getVcards();
}

/**
 * set up a stable FTP connection to a designated destination
 *
 * @param string $url
 * @param string $user
 * @param string $password
 * @param string $directory
 * @param boolean $secure
 * @return mixed false or stream of ftp connection
 */
function getFtpConnection($url, $user, $password, $directory, $secure)
{
    $ftpserver = parse_url($url, PHP_URL_HOST) ? parse_url($url, PHP_URL_HOST) : $url;
    $connectFunc = $secure ? 'ftp_connect' : 'ftp_ssl_connect';

    if ($connectFunc == 'ftp_ssl_connect' && !function_exists('ftp_ssl_connect')) {
        throw new \Exception("PHP lacks support for 'ftp_ssl_connect', please use `plainFTP` to switch to unencrypted FTP");
    }
    if (false === ($ftp_conn = $connectFunc($ftpserver))) {
        $message = sprintf("Could not connect to ftp server %s for upload", $ftpserver);
        throw new \Exception($message);
    }
    if (!ftp_login($ftp_conn, $user, $password)) {
        $message = sprintf("Could not log in %s to ftp server %s for upload", $user, $ftpserver);
        throw new \Exception($message);
    }
    if (!ftp_pasv($ftp_conn, true)) {
        $message = sprintf("Could not switch to passive mode on ftp server %s for upload", $ftpserver);
        throw new \Exception($message);
    }
    if (!ftp_chdir($ftp_conn, $directory)) {
        $message = sprintf("Could not change to dir %s on ftp server %s for upload", $directory, $ftpserver);
        throw new \Exception($message);
    }
    return $ftp_conn;
}

/**
 * get fractions from >VERSION:4.0< mime properties which Sabre/Vobject does not deliver
 * see: https://github.com/sabre-io/vobject/issues/458
 *
 * @param string $mimeData
 * @return array parsed fractions
 */
function getMimeFractions($mimeData)
{
    @list(
        $param,
        $content,
    ) = explode(',', $mimeData);
    @list(
        $prefix,
        $data,
    ) = explode(':', $param);
    @list(
        $mimetype,
        $encoding,
    ) = explode(';', $data);
    @list(
        $qualifier,
        $type,
    ) = explode('/', $mimetype);
    return [
        'type'     => strtoupper($type),
        'encoding' => strtolower($encoding),
        'content'  => $content,
    ];
}

/**
 * convert safely a PNG to JPG with the transparency in white
 * see: https://stackoverflow.com/a/8951540/10871304
 *
 * @param string $imagePNG binary PNG data
 * @return string $imageJPG binary JPG data
 */
function convertPNGtoJPG($imagePNG)
{
    $source = imagecreatefromstring($imagePNG);
    $target = imagecreatetruecolor(imagesx($source), imagesy($source));
    imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
    imagealphablending($target, true);
    imagecopy($target, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
    imagedestroy($source);
    ob_start();
    imagejpeg($target);
    $imageJPG = ob_get_clean();
    imagedestroy($target);

    return $imageJPG;
}

/**
 * get image data from vCard
 *
 * @param document $vcard
 * @return string|bool $vcardImage binary image data or false, if no jpeg could delivered
 */
function getJPEGimage($vcard)
{
    if ((string)$vcard->VERSION == '3.0') {
        if (strtoupper($vcard->PHOTO['VALUE']) == 'URI') {
            $fraction = getMimeFractions((string)$vcard->PHOTO);
            $mimeType = $fraction['type'];
            if ($fraction['encoding'] == 'base64') {
                $imageData = base64_decode($fraction['content']);
            } elseif (empty($fraction['encoding'])) {       // RFC 2426: "default encoding of 8bit is used and no explicit ENCODING parameter is needed"
                $imageData = quoted_printable_decode($fraction['content']);     // that might be wrong - but have no data to test it
            } else {
                return false;
            }
        } else {                                            // according to RFC 2426 this should be the usual case
            $mimeType = strtoupper($vcard->PHOTO['TYPE']);
            $imageData = (string)$vcard->PHOTO;
        }
    } elseif ((string)$vcard->VERSION == '4.0') {
        $fraction = getMimeFractions((string)$vcard->PHOTO);
        $mimeType = $fraction['type'];
        if ($fraction['encoding'] == 'base64') {
            $imageData = base64_decode($fraction['content']);
        } else {
            return false;
        }
    } else {
        return false;
    }
    switch ($mimeType) {
        case 'JPEG':
            $vcardImage = $imageData;
            break;

        case 'PNG':
            $vcardImage = convertPNGtoJPG($imageData);
            break;

        /*case: 'WEBP':                 further extensions if CardDAV servers use other image formats
            $vcardImage = convertWEBPtoJPG($imageData);
            break;  */

        default:
            return false;
    }

    return $vcardImage;
}

/**
 * upload image files via ftp to the fritzbox fonpix directory
 *
 * @param Document[] $vcards downloaded vCards
 * @param array $config
 * @param array $phonebook
 * @param callable $callback
 * @return mixed false or [number of uploaded images, number of total found images]
 */
function uploadImages(array $vcards, array $config, array $phonebook, callable $callback=null)
{
    $countUploadedImages = 0;
    $countAllImages = 0;
    $vcardImage = '';
    $mapFTPUIDtoFTPImageName = [];                      // "9e40f1f9-33df-495d-90fe-3a1e23374762" => "9e40f1f9-33df-495d-90fe-3a1e23374762_190106123906.jpg"
    $timestampPostfix = substr(date("YmdHis"), 2);      // timestamp, e.g., 190106123906

    if (null == ($imgPath = @$phonebook['imagepath'])) {
        throw new \Exception('Missing phonebook/imagepath in config. Image upload not possible.');
    }
    $imgPath = rtrim($imgPath, '/') . '/';  // ensure one slash at end

    // Prepare FTP connection
    $secure = @$config['plainFTP'] ? $config['plainFTP'] : false;
    $ftp_conn = getFtpConnection($config['url'], $config['user'], $config['password'], $config['fonpix'], $secure);

    // Build up dictionary to look up UID => current FTP image file
    if (false === ($ftpFiles = ftp_nlist($ftp_conn, "."))) {
        $ftpFiles = [];
    }

    foreach ($ftpFiles as $ftpFile) {
        $ftpUid = preg_replace("/\_.*/", "", $ftpFile);  // new filename with time stamp postfix
        $ftpUid = preg_replace("/\.jpg/i", "", $ftpUid); // old filename
        $mapFTPUIDtoFTPImageName[$ftpUid] = $ftpFile;
    }

    foreach ($vcards as $vcard) {
        if (is_callable($callback)) {
            ($callback)();
        }

        if (!isset($vcard->PHOTO)) {                            // skip vCard without image
            continue;
        }

        $uid = (string)$vcard->UID;

        // Occurs when embedding was not possible during download (for example, no access to linked data)
        if (preg_match("/^http/", $vcard->PHOTO)) {             // if the embed failed
            error_log(sprintf(PHP_EOL . 'The image for UID %s can not be accessed! ', $uid));
            continue;
        }
        // Fritz!Box only accept jpg-files
        if (!$vcardImage = getJPEGimage($vcard)) {
            continue;
        }

        $countAllImages++;

        // Check if we can skip upload
        $newFTPimage = sprintf('%1$s_%2$s.jpg', $uid, $timestampPostfix);
        if (array_key_exists($uid, $mapFTPUIDtoFTPImageName)) {
            $currentFTPimage = $mapFTPUIDtoFTPImageName[$uid];
            if (ftp_size($ftp_conn, $currentFTPimage) == strlen($vcardImage)) {
                // No upload needed, but store old image URL in vCard
                $vcard->IMAGEURL = $imgPath . $currentFTPimage;
                continue;
            }
            // we already have an old image, but the new image differs in size
            ftp_delete($ftp_conn, $currentFTPimage);
        }

        // Upload new image file
        $memstream = fopen('php://memory', 'r+');     // we use a fast in-memory file stream
        fputs($memstream, $vcardImage);
        rewind($memstream);

        // upload new image
        if (ftp_fput($ftp_conn, $newFTPimage, $memstream, FTP_BINARY)) {
            $countUploadedImages++;
            // upload of new image done, now store new image URL in vCard (new Random Postfix!)
            $vcard->IMAGEURL = $imgPath . $newFTPimage;
        } else {
            error_log(PHP_EOL."Error uploading $newFTPimage.");
            unset($vcard->PHOTO);                              // no wrong link will set in phonebook
            unset($vcard->IMAGEURL);                           // no wrong link will set in phonebook
        }
        fclose($memstream);
    }
    @ftp_close($ftp_conn);

    if ($countAllImages > MAX_IMAGE_COUNT) {
        error_log(sprintf(<<<EOD
WARNING: You have %d contact images on FritzBox. FritzFon may handle only up to %d images.
         Some images may not display properly, see: https://github.com/andig/carddav2fb/issues/92.
EOD
        , $countAllImages, MAX_IMAGE_COUNT));
    }

    return [$countUploadedImages, $countAllImages];
}

/**
 * Dissolve the groups of iCloud contacts
 *
 * @param mixed[] $vcards
 * @return mixed[]
 */
function dissolveGroups(array $vcards): array
{
    $groups = [];

    // separate iCloud groups
    /** @var \stdClass $vcard */
    foreach ($vcards as $key => $vcard) {
        if (isset($vcard->{'X-ADDRESSBOOKSERVER-KIND'})) {
            if ($vcard->{'X-ADDRESSBOOKSERVER-KIND'} == 'group') {      // identifier
                foreach ($vcard->{'X-ADDRESSBOOKSERVER-MEMBER'} as $member) {
                    $member = str_replace(['urn:', 'uuid:'], ['', ''], (string)$member);
                    $groups[(string)$vcard->FN][] = $member;
                }
                unset($vcards[$key]);                                   // delete this vCard
            }
        }
    }

    // assign group memberships
    foreach ($vcards as $vcard) {
        foreach ($groups as $group => $members) {
            if (in_array((string)$vcard->UID, $members)) {
                if (isset($vcard->GROUPS)) {
                    $assignedGroups = $vcard->GROUPS->getParts();   // get array of values
                    $assignedGroups[] = $group;                     // add the new value
                    $vcard->GROUPS->setParts($assignedGroups);      // set the values
                } else {
                    $vcard->GROUPS = $group;                        // set the new value
                }
            }
        }
    }

    return $vcards;
}

/**
 * Filter included/excluded vcards
 *
 * @param mixed[] $vcards
 * @param array $filters
 * @return mixed[]
 */
function filter(array $vcards, array $filters): array
{
    // include selected
    $includeFilter = $filters['include'] ?? [];

    if (countFilters($includeFilter)) {
        $step1 = [];
        foreach ($vcards as $vcard) {
            if (filtersMatch($vcard, $includeFilter)) {
                $step1[] = $vcard;
            }
        }
    } else {
        // filter defined but empty sub-rules?
        if (count($includeFilter)) {
            error_log('Include filter is empty: including all downloaded vCards');
        }

        // include all by default
        $step1 = $vcards;
    }

    $excludeFilter = $filters['exclude'] ?? [];
    if (!count($excludeFilter)) {
        return $step1;
    }

    $step2 = [];
    foreach ($step1 as $vcard) {
        if (!filtersMatch($vcard, $excludeFilter)) {
            $step2[] = $vcard;
        }
    }

    return $step2;
}

/**
 * Count populated filter rules
 *
 * @param array $filters
 * @return int
 */
function countFilters(array $filters): int
{
    $filterCount = 0;

    foreach ($filters as $key => $value) {
        if (is_array($value)) {
            $filterCount += count($value);
        }
    }

    return $filterCount;
}

/**
 * Check a list of filters against the vcard properties CATEGORIES and/or GROUPS
 *
 * @param Document $vcard
 * @param array $filters
 * @return bool
 */
function filtersMatch(Document $vcard, array $filters): bool
{
    foreach ($filters as $attribute => $values) {
        $param = strtoupper($attribute);
        if (isset($vcard->$param)) {
            if (array_intersect($vcard->$param->getParts(), $values)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Export cards to fritzbox xml
 *
 * @param Document[] $cards
 * @param array $conversions
 * @return SimpleXMLElement     the XML phone book in Fritz Box format
 */
function exportPhonebook(array $cards, array $conversions): SimpleXMLElement
{
    $xmlPhonebook = new SimpleXMLElement(
        <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<phonebooks>
<phonebook />
</phonebooks>
EOT
    );

    $root = $xmlPhonebook->xpath('//phonebook')[0];
    $root->addAttribute('name', $conversions['phonebook']['name']);

    $converter = new Converter($conversions);
    $restore = new Restorer;

    foreach ($cards as $card) {
        $contacts = $converter->convert($card);
        foreach ($contacts as $contact) {
            $restore->xml_adopt($root, $contact);
        }
    }
    return $xmlPhonebook;
}

/**
 * Upload cards to fritzbox
 *
 * @param SimpleXMLElement  $xmlPhonebook
 * @param array             $config
 * @return void
 */
function uploadPhonebook(SimpleXMLElement $xmlPhonebook, array $config)
{
    $options = $config['fritzbox'];

    $fritz = new Api($options['url']);
    $fritz->setAuth($options['user'], $options['password']);
    $fritz->mergeClientOptions($options['http'] ?? []);
    $fritz->login();

    $formfields = [
        'PhonebookId' => $config['phonebook']['id']
    ];

    $filefields = [
        'PhonebookImportFile' => [
            'type' => 'text/xml',
            'filename' => 'updatepb.xml',
            'content' => $xmlPhonebook->asXML(), // convert XML object to XML string
        ]
    ];

    $result = $fritz->postFile($formfields, $filefields); // send the command to store new phonebook
    if (!uploadSuccessful($result)) {
        throw new \Exception('Upload failed');
    }
}

/**
 * Check if upload was successful
 *
 * @param string $msg FRITZ!Box message
 * @return bool
 */
function uploadSuccessful(string $msg): bool
{
    $success =
        strpos($msg, 'Das Telefonbuch der FRITZ!Box wurde wiederhergestellt') !== false ||
        strpos($msg, 'FRITZ!Box telephone book restored') !== false;
    return $success;
}

/**
 * Downloads the phone book from Fritzbox
 *
 * @param   array $fritzbox
 * @param   array $phonebook
 * @return  SimpleXMLElement|bool with the old existing phonebook
 */
function downloadPhonebook(array $fritzbox, array $phonebook)
{
    $fritz = new Api($fritzbox['url']);
    $fritz->setAuth($fritzbox['user'], $fritzbox['password']);
    $fritz->mergeClientOptions($fritzbox['http'] ?? []);
    $fritz->login();

    $formfields = [
        'PhonebookId' => $phonebook['id'],
        'PhonebookExportName' => $phonebook['name'],
        'PhonebookExport' => "",
    ];
    $result = $fritz->postFile($formfields, []); // send the command to load existing phone book
    if (substr($result, 0, 5) !== "<?xml") {
        error_log("ERROR: Could not load phonebook with ID=".$phonebook['id']);
        return false;
    }
    $xmlPhonebook = simplexml_load_string($result);

    return $xmlPhonebook;
}

/**
 * Get quickdial number and names as array from given XML phone book
 *
 * @param array $attributes
 * @param bool $alias
 * @return array $quickdialNames
 */
function getQuickdials(array $attributes, bool $alias = false)
{
    if (empty($attributes)) {
        return [];
    }

    $quickdialNames = [];
    foreach ($attributes as $values) {
        $parts = explode(', ', $values['name']);
        if (count($parts) !== 2) {                  // if the name was not separated by a comma (no first and last name)
            $name = $values['name'];                // fullName
        } else {
            $name = $parts[1];                      // firstname
        }
        if ($alias && !empty($values['vanity'])) {
            $name = ucfirst(strtolower($values['vanity']));     // quickdial alias
        }
        $quickdialNames[$values['quickdial']] = substr($name, 0, 10);
    }
    ksort($quickdialNames);                         // ascending: lowest quickdial # first

    return $quickdialNames;
}

/**
 * upload background image to fritzbox
 *
 * @param array $attributes
 * @param array $config
 * @return void
 */
function uploadBackgroundImage($attributes, array $config)
{
    $quickdials = getQuickdials($attributes, $config['quickdial_alias'] ?? false);
    if (!count($quickdials)) {
        error_log('No quickdial numbers are set for a background image upload');
        return;
    }
    if (key($quickdials) > 9) {    // usual the pointer should on the first element; with 7.3.*: array_key_first()
        error_log('Quickdial numbers out of range for a background image upload');
        return;
    }

    $image = new BackgroundImage();
    $image->uploadImage($quickdials, $config);
}

/**
 * save special attributes to internal FRITZ!Box memory (../FRITZ/mediabox)
 *
 * @param SimpleXMLElement $phonebook
 * @param array $config
 * @return array
 */
function uploadAttributes($phonebook, $config)
{
    $restore = new Restorer;
    if (!count($specialAttributes = $restore->getPhonebookData($phonebook, $config))) {
        return [];
    }
    $fritzbox= $config['fritzbox'];

    error_log('Save internal data from recent FRITZ!Box phonebook!');
    // Prepare FTP connection
    $secure = @$fritzbox['plainFTP'] ? $fritzbox['plainFTP'] : false;
    $ftp_conn = getFtpConnection($fritzbox['url'], $fritzbox['user'], $fritzbox['password'], '/FRITZ/mediabox', $secure);
    // backup already stored data
    if (ftp_size($ftp_conn, 'Attributes.csv') != -1) {                  // file already exists
        if (ftp_size($ftp_conn, 'Attributes.csv.bak') != -1) {          // backaup file already exists
            ftp_delete($ftp_conn, 'Attributes.csv.bak');                // delete backup file
        }
        ftp_rename($ftp_conn, 'Attributes.csv', 'Attributes.csv.bak');  // create a new backup file
    }

    // open a fast in-memory file stream
    $memstream = fopen('php://memory', 'r+');
    $rows = $restore->phonebookDataToCSV($specialAttributes);
    fputs($memstream, $rows);
    rewind($memstream);
    if (!ftp_fput($ftp_conn, 'Attributes.csv', $memstream, FTP_BINARY)) {
        error_log('Error uploading Attributes.csv!' . PHP_EOL);
    }
    fclose($memstream);
    @ftp_close($ftp_conn);

    return $specialAttributes;
}

/**
 * get saved special attributes from internal FRITZ!Box memory (../FRITZ/mediabox)
 *
 * @param array $config
 * @return array
 */
function downloadAttributes($config)
{
    // Prepare FTP connection
    $secure = @$config['plainFTP'] ? $config['plainFTP'] : false;
    $ftp_conn = getFtpConnection($config['url'], $config['user'], $config['password'], '/FRITZ/mediabox', $secure);
    if (ftp_size($ftp_conn, 'Attributes.csv') == -1) {
        return [];
    }

    $restore = new Restorer;
    $specialAttributes = [];
    $csvFile = fopen('php://temp', 'r+');
    if (ftp_fget($ftp_conn, $csvFile, 'Attributes.csv', FTP_BINARY)) {
        rewind($csvFile);
        while ($csvRow = fgetcsv($csvFile)) {
            $specialAttributes = array_merge($restore->csvToPhonebookData($csvRow), $specialAttributes);
        }
    }
    fclose($csvFile);
    @ftp_close($ftp_conn);

    return $specialAttributes;
}

/**
 * Restore special attributes (quickdial, vanity) and internal phone numbers
 * in given target phone book
 *
 * @param SimpleXMLElement $xmlTargetPhoneBook
 * @param array $attributes array of special attributes
 * @return SimpleXMLElement phonebook with restored special attributes
 */
function mergeAttributes(SimpleXMLElement $xmlTargetPhoneBook, array $attributes)
{
    if (!$attributes) {
        return $xmlTargetPhoneBook;
    }
    $restore = new Restorer;
    $xmlTargetPhoneBook = $restore->setPhonebookData($xmlTargetPhoneBook, $attributes);

    return $xmlTargetPhoneBook;
}

/**
 * convert phonebook to VCF
 *
 * @param SimpleXMLElement $phoneBook
 * @param bool $images
 * @param array $config
 * @return string
 */
function phonebookToVCF(SimpleXMLElement $phoneBook, bool $images = false, array $config)
{
    $vcf = '';
    $saver = new replymail;
    if ($images) {
        $secure = @$config['plainFTP'] ? $config['plainFTP'] : false;
        $ftp_conn = getFtpConnection($config['url'], $config['user'], $config['password'], '/', $secure);
    }
    foreach ($phoneBook->phonebook->contact as $contact) {
        // fetch data basic data
        $name = $contact->person->realName;
        $numbers = [];
        foreach ($contact->telephony->number as $number) {
            $numbers[(string)$number] = (string)$number['type'];
        }
        $emails = [];
        if (isset($contact->services->email)) {
            foreach ($contact->services->email as $email) {
                $emails[] = (string)$email;
            }
        }
        $vip = $contact->category;
        // assemble basic vCard
        $vCard = $saver->getvCard($name, $numbers, $emails, $vip);
        $vCard->UID = (string)$contact->carddav_uid;
        if ($images && isset($contact->person->imageURL)) {
            $memStream = fopen('php://memory', 'r+');               // use fast in-memory file stream
            $imgLocation = strstr((string)$contact->person->imageURL, 'InternerSpeicher');
            $imgLocation = str_replace('InternerSpeicher', '', $imgLocation);
            if (ftp_fget($ftp_conn, $memStream, $imgLocation, FTP_BINARY)) {
                rewind($memStream);
                $contents = stream_get_contents($memStream);
                $vCard->add('PHOTO', base64_encode($contents), ['TYPE' => 'JPEG', 'ENCODING' => 'b']);
                fclose($memStream);
            }
        }
        $vcf .= $vCard->serialize();
    }
    @ftp_close($ftp_conn);

    return $vcf;
}

/**
 * this function checked if all phone numbers from the FRITZ!Box phonebook are
 * included in the new phonebook. If not so the number(s) and type(s) resp.
 * vip flag are compiled in a vCard and sent as a vcf file with the name as
 * filename will be send as an email attachement
 *
 * @param SimpleXMLElement $oldPhonebook
 * @param SimpleXMLElement $newPhonebook
 * @param array $config
 * @return int
 */
function checkUpdates($oldPhonebook, $newPhonebook, $config)
{
    $i = 0;
    if (!isset($config['reply'])) {
        return $i;
    } else {
        $reply = $config['reply'];
    }
    $eMailer = new replymail;
    $eMailer->setSMTPcredentials($reply);
    // check if entries are not included in the intended upload
    foreach ($oldPhonebook->phonebook->contact as $contact) {
        $numbers = [];                                              // container for ew numbers per contact
        foreach ($contact->telephony->number as $number) {
            if ((substr($number, 0, 1) == '*') ||                   // skip internal numbers
                (substr($number, 0, 1) == '#') ||
                (strpos($number, '00@hd-telefonie.avm.de'))) {
                continue;
            }
            $queryString = sprintf('//telephony[number = "%s"]', (string)$number);    // assemble search string
            if (!$newPhonebook->xpath($queryString)) {              // not found in upload: entry to save!
                $numbers[(string)$number] = (string)$number['type'];
            }
        }
        if (count($numbers)) {                                      // one or more new numbers found
            // fetch data
            $emails = [];
            $name  = $contact->person->realName;
            foreach ($contact->services->email as $email) {
                $emails[] = (string)$email;
            }
            $vip   = $contact->category;
            // assemble vCard from new entry(s)
            $new_vCard = $eMailer->getvCard($name, $numbers, $emails, $vip);
            // send new entry as vCard to designated reply adress
            if ($eMailer->sendReply($config['phonebook']['name'], $new_vCard->serialize(), $name . '.vcf')) {
                $i++;
            }
        }
    }
    return $i;
}

/**
 * if $config['fritzbox']['fritzadr'] is set, than all contact (names) with a fax number
 * are copied into a dBase III database fritzadr.dbf for FRITZ!fax purposes
 *
 * @param SimpleXMLElement $xmlPhonebook phonebook in FRITZ!Box format
 * @param array $config
 * @return int number of records written to fritzadr.dbf
 */
function uploadFritzAdr(SimpleXMLElement $xmlPhonebook, $config)
{
    // Prepare FTP connection
    $secure = @$config['plainFTP'] ? $config['plainFTP'] : false;
    $ftp_conn = getFtpConnection($config['url'], $config['user'], $config['password'], $config['fritzadr'], $secure);
    // open a fast in-memory file stream
    $memstream = fopen('php://memory', 'r+');
    $converter = new fritzadr;
    $faxContacts = $converter->getFAXcontacts($xmlPhonebook);       // extracting
    if (count($faxContacts)) {
        fputs($memstream, $converter->getdBaseData($faxContacts));
        rewind($memstream);
        if (!ftp_fput($ftp_conn, 'fritzadr.dbf', $memstream, FTP_BINARY)) {
            error_log("Error uploading fritzadr.dbf!" . PHP_EOL);
        }
    }
    fclose($memstream);
    ftp_close($ftp_conn);
    return count($faxContacts);
}
