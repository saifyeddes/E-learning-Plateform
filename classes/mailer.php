<?php
namespace local_elearning_system;

defined('MOODLE_INTERNAL') || die();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class mailer {

    public static function send_mail($toemail, $toname, $subject, $htmlbody, $altbody = '') {
        global $CFG;

        require_once($CFG->dirroot . '/vendor/autoload.php');

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; // à remplacer si autre SMTP
            $mail->SMTPAuth = true;
            $mail->Username = 'saifyedes20@gmail.com'; // à remplacer
            $mail->Password = 'migq ojjw wfmr xzwn
'; // à remplacer
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->CharSet = 'UTF-8';

            $mail->setFrom('saifyedes20@gmail.com', 'SIT'); // à remplacer
            $mail->addAddress($toemail, $toname);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlbody;
            $mail->AltBody = !empty($altbody) ? $altbody : strip_tags($htmlbody);

            $mail->send();
            return true;

        } catch (Exception $e) {
            debugging('PHPMailer error: ' . $mail->ErrorInfo, DEBUG_DEVELOPER);
            return false;
        }
    }
}