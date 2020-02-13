<?php

namespace Andig\ReplyMail;

/* class fritzvCard delivers a simple function based on VCard
 * to provide a vcf file whose data is based on the FRITZ!Box
 * phonebook entries
 *
 * class replymail delivers a simple function based on PHPMailer
 *
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Sabre\VObject\Document;
use Sabre\VObject;

class replymail
{
    private $vCard;
    private $mail;

    public function __construct()
    {
        date_default_timezone_set('Etc/UTC');
        $this->mail = new PHPMailer;
        $this->mail->CharSet = 'UTF-8';
    }

    /**
     * get a new simple vCard according to FRITZ!Box phonebook data
     *
     * @param string $name
     * @param array $numbers
     * @param string $email
     * @param string $vip
     * @return string
     */
    public function getvCard($name, $numbers, $email = '', $vip = '')
    {
        $this->vCard = new VObject\Component\VCard(['FN' => $name]);
        $parts = explode(', ', $name);
        count($parts) == 2 ? $this->vCard->add('N', [$parts[0], $parts[1]]) : $this->vCard->add('ORG', $name);
        foreach ($numbers as $number => $type) {
            switch ($type) {
                case 'fax_work':
                    $this->vCard->add('TEL', $number, ['type' => 'FAX']);
                    break;

                case 'mobile':
                    $this->vCard->add('TEL', $number, ['type' => 'CELL']);
                    break;

                default:    // home & work
                    $this->vCard->add('TEL', $number, ['type' => strtoupper($type)]);
                    break;
            }
        }
        if (!empty($email)) {
            $this->vCard->add('EMAIL', $email);
        }
        if ($vip == 1) {
            $this->vCard->add('NOTE', 'This contact was marked as important.\nSuggestion: assign to a VIP category or group.');
        }

        return $this->vCard->serialize();
    }

    /**
     * set SMTP credetials
     *
     * @param array $account
     * @return void
     */
    public function setSMTPcredentials($account)
    {
        $this->mail->isSMTP();                                  // tell PHPMailer to use SMTP
        $this->mail->SMTPDebug  = $account['debug'];
        $this->mail->Host       = $account['url'];              // set the hostname of the mail server
        $this->mail->Port       = $account['port'];             // set the SMTP port number - likely to be 25, 465 or 587
        $this->mail->SMTPSecure = $account['secure'];
        $this->mail->SMTPAuth   = true;                         // whether to use SMTP authentication
        $this->mail->Username   = $account['user'];             // username to use for SMTP authentication
        $this->mail->Password   = $account['password'];         // password to use for SMTP authentication
        $this->mail->setFrom($account['user'], 'carddav2fb');   // set who the message is to be sent fromly-to address
        $this->mail->addAddress($account['receiver']);          // set who the message is to be sent to
    }

    /**
     * send reply mail
     *
     * @param string $phonebook
     * @param string $attachment
     * @param string $label
     * @return bool
     */
    public function sendReply($phonebook, $attachment, $label)
    {
        $this->mail->clearAttachments();
        $this->mail->Subject = 'Newer contact was found in phonebook: ' . $phonebook;  //Set the subject line
        $this->mail->Body = 'Add this contact to your CardDAV server:';
        $this->mail->addStringAttachment($attachment, $label, 'quoted-printable', 'text/x-vcard');
        if (!$this->mail->send()) {                                     // send the message, check for errors
            echo 'Mailer Error: ' . $this->mail->ErrorInfo;
            return false;
        }

        return true;
    }
}
